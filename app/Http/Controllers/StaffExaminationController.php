<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StaffExaminationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Examination::class);

        return view('examinations.index', [
            ...$this->sidebarData($request),
            'selectedExamination' => null,
        ]);
    }

    public function show(Request $request, Examination $examination): View
    {
        Gate::authorize('viewAny', Examination::class);
        Gate::authorize('view', $examination);

        $examination->loadMissing(['customsFormNumbers', 'photos']);

        return view('examinations.show', [
            ...$this->sidebarData($request),
            'selectedExamination' => $examination,
        ]);
    }

    public function destroy(Examination $examination): RedirectResponse
    {
        Gate::authorize('delete', $examination);

        DB::transaction(function () use ($examination): void {
            $examination->deleted_by_user_id = auth()->id();
            $examination->save();
            $examination->delete();
        });

        return redirect()->route('examinations.index')->with('status', __('examination.staff.deleted'));
    }

    /**
     * Build the bounded, paginated examination list shared by both workspace states.
     * Search, date-filter, and page state are carried into generated pagination URLs.
     *
     * @return array{examinations: LengthAwarePaginator, search: string, today: bool}
     */
    private function sidebarData(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'today' => ['nullable', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $today = (string) ($validated['today'] ?? '1') === '1';
        $page = (int) ($validated['page'] ?? 1);
        $exactSubmissionNo = strtoupper($search);
        $isCompleteSubmissionNo = preg_match('/^ZB-\d{6}-\d{4}$/', $exactSubmissionNo) === 1;

        $examinations = Examination::query()
            ->when($today && ! $isCompleteSubmissionNo, function ($query): void {
                $timezone = (string) config('zb-examine.business_timezone', 'Asia/Kuala_Lumpur');
                $localStart = CarbonImmutable::now($timezone)->startOfDay();
                $utcStart = $localStart->utc();
                $utcNextDay = $localStart->addDay()->utc();

                $query->where('submitted_at', '>=', $utcStart)
                    ->where('submitted_at', '<', $utcNextDay);
            })
            ->when($isCompleteSubmissionNo, function ($query) use ($exactSubmissionNo): void {
                $query->where('submission_no', $exactSubmissionNo);
            })
            ->when($search !== '' && ! $isCompleteSubmissionNo, function ($query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where(function ($query) use ($like): void {
                    $query->where('submission_no', 'like', $like)
                        ->orWhere('agent_code', 'like', $like)
                        ->orWhere('agent_station_code', 'like', $like)
                        ->orWhere('agent_name', 'like', $like)
                        ->orWhere('agent_company_name', 'like', $like)
                        ->orWhereHas('customsFormNumbers', function ($query) use ($like): void {
                            $query->where('number', 'like', $like);
                        });
                });
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'page', $page)
            ->appends(array_filter([
                'search' => $search !== '' ? $search : null,
                'today' => $today ? '1' : '0',
            ], static fn ($value): bool => $value !== null));

        return compact('examinations', 'search', 'today');
    }
}
