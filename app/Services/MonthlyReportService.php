<?php

namespace App\Services;

use App\Data\ReportPeriod;
use App\Models\Examination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class MonthlyReportService
{
    /**
     * @return array{total_submissions: int, unique_agents: int, evidence_photos: int, average_per_active_day: float}
     */
    public function summary(ReportPeriod $period): array
    {
        $totalSubmissions = $this->baseQuery($period)->count();
        $dailyCounts = $this->dailyCounts($period);
        $agentGroups = $this->agentGroups($period);

        return [
            'total_submissions' => $totalSubmissions,
            'unique_agents' => $agentGroups->count(),
            'evidence_photos' => $this->baseQuery($period)
                ->join('examination_photos', 'examination_photos.examination_id', '=', 'examinations.id')
                ->count('examination_photos.id'),
            'average_per_active_day' => count($dailyCounts) === 0
                ? 0.0
                : round($totalSubmissions / count($dailyCounts), 1),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function dailyCounts(ReportPeriod $period): array
    {
        $counts = [];

        foreach ($this->baseQuery($period)->select(['id', 'submitted_at'])->lazy() as $examination) {
            $date = $examination->submitted_at
                ->setTimezone($period->timezone)
                ->toDateString();
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<array{agent_name: string, agent_code: string, company: string, station: string, submissions: int}>
     */
    public function dailyCalendar(ReportPeriod $period): array
    {
        $counts = $this->dailyCounts($period);
        $calendar = [];

        for ($date = $period->localStart; $date < $period->localNextMonthStart; $date = $date->addDay()) {
            $key = $date->toDateString();
            $calendar[] = ['date' => $key, 'submissions' => $counts[$key] ?? 0];
        }

        return $calendar;
    }

    /**
     * @return list<array{agent_name: string, agent_code: string, company: string, station: string, submissions: int}>
     */
    public function agentSummary(ReportPeriod $period): array
    {
        return $this->agentGroups($period)
            ->map(static function (array $group): array {
                $group['submissions'] = count($group['submission_ids']);
                unset($group['submission_ids']);

                return $group;
            })
            ->sort(function (array $left, array $right): int {
                return [$right['submissions'], $left['agent_code'], $left['company'], $left['station'], $left['agent_name']]
                    <=> [$left['submissions'], $right['agent_code'], $right['company'], $right['station'], $right['agent_name']];
            })
            ->values()
            ->all();
    }

    public function statement(ReportPeriod $period, int $perPage = 25): LengthAwarePaginator
    {
        return $this->statementQuery($period)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function statementQuery(ReportPeriod $period)
    {
        return $this->baseQuery($period)
            ->select([
                'examinations.id',
                'examinations.submission_no',
                'examinations.user_id',
                'examinations.agent_name',
                'examinations.agent_code',
                'examinations.agent_company_name',
                'examinations.agent_station_code',
                'examinations.location',
                'examinations.form_type',
                'examinations.form_type_other',
                'examinations.container_status',
                'examinations.reason',
                'examinations.reason_other',
                'examinations.attending_officer_type',
                'examinations.submitted_at',
            ])
            ->with('customsFormNumbers')
            ->withCount('photos')
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    public function exportHighWatermarkQuery(ReportPeriod $period)
    {
        return $this->baseQuery($period)
            ->select(['examinations.id', 'examinations.submitted_at'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
    }

    private function baseQuery(ReportPeriod $period)
    {
        return Examination::query()
            ->where('submitted_at', '>=', $period->utcStart)
            ->where('submitted_at', '<', $period->utcNextMonthStart);
    }

    /**
     * @return Collection<int, array{agent_name: string, agent_code: string, company: string, station: string, submission_ids: list<int> }>
     */
    private function agentGroups(ReportPeriod $period): Collection
    {
        $groups = collect();

        foreach ($this->baseQuery($period)->select([
            'id',
            'user_id',
            'agent_name',
            'agent_code',
            'agent_company_name',
            'agent_station_code',
            'submitted_at',
        ])->orderByDesc('submitted_at')->orderByDesc('id')->lazy() as $examination) {
            $name = trim((string) $examination->agent_name);
            $code = trim((string) $examination->agent_code);
            $company = trim((string) $examination->agent_company_name);
            $station = trim((string) $examination->agent_station_code);
            $key = $examination->user_id !== null
                ? 'user:'.$examination->user_id
                : 'guest:'.json_encode([$code, $company, $station, $name], JSON_THROW_ON_ERROR);

            if (! $groups->has($key)) {
                $groups->put($key, [
                    'agent_name' => $name,
                    'agent_code' => $code,
                    'company' => $company,
                    'station' => $station,
                    'submission_ids' => [],
                ]);
            }

            $group = $groups->get($key);
            $group['submission_ids'][] = (int) $examination->id;
            $groups->put($key, $group);
        }

        return $groups;
    }
}
