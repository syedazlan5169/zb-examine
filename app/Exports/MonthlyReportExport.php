<?php

namespace App\Exports;

use App\Data\ReportPeriod;
use App\Models\Examination;
use App\Services\MonthlyReportService;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

final class MonthlyReportExport
{
    public function create(ReportPeriod $period, MonthlyReportService $reports, ?callable $writerFactory = null, ?callable $tempFileFactory = null): string
    {
        $path = $tempFileFactory !== null
            ? $tempFileFactory()
            : tempnam(sys_get_temp_dir(), 'zb-report-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary report file.');
        }

        $writer = null;
        $completed = false;

        try {
            $writer = $writerFactory !== null
                ? $writerFactory()
                : new Writer(new Options(tempFolder: sys_get_temp_dir()));
            $writer->openToFile($path);

            $summarySheet = $writer->getCurrentSheet()->setName('Summary');
            $summarySheet->setColumnWidth(28, 1);
            $summarySheet->setColumnWidth(18, 2);
            $this->writeSummary($writer, $period, $reports);

            $statementSheet = $writer->addNewSheetAndMakeItCurrent()->setName('Monthly Statement');
            $statementSheet->setColumnWidth(7, 1);
            $statementSheet->setColumnWidth(20, 2);
            $statementSheet->setColumnWidth(14, 3, 4);
            $statementSheet->setColumnWidth(24, 5, 8);
            $statementSheet->setColumnWidth(24, 9, 14);
            $statementSheet->setColumnWidth(14, 15);
            $writer->addRow($this->styledTextRow($this->statementHeaders(), $this->headerStyle()));

            $rowNumber = 1;
            $highWatermark = $this->highWatermark($period, $reports);
            foreach ($this->statementRows($period, $reports, $highWatermark) as $examination) {
                $writer->addRow($this->statementRow($examination, $period, $rowNumber));
                $rowNumber++;
            }

            $statementSheet->setAutoFilter(new AutoFilter(0, 1, 14, max(1, $rowNumber)));
            $writer->close();
            $completed = true;

            return $path;
        } finally {
            if (! $completed) {
                try {
                    $writer?->close();
                } finally {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
    }

    private function writeSummary(Writer $writer, ReportPeriod $period, MonthlyReportService $reports): void
    {
        $summary = $reports->summary($period);
        $writer->addRow($this->textRow(['Sistem Daftar Pemeriksaan']));
        $writer->addRow($this->textRow([__('reports.workbook.monthly_report')]));
        $writer->addRow($this->textRow([__('reports.workbook.selected_month'), $period->label()]));
        $writer->addRow($this->textRow([__('reports.workbook.generated_at'), now($period->timezone)->format('d/m/Y H:i')]));
        $writer->addRow(new Row([]));
        $writer->addRow($this->textRow([__('reports.summary.title')]));

        foreach ([
            [__('reports.summary.total_submissions'), $summary['total_submissions']],
            [__('reports.summary.unique_agents'), $summary['unique_agents']],
            [__('reports.summary.evidence_photos'), $summary['evidence_photos']],
            [__('reports.summary.average_per_active_day'), $summary['average_per_active_day']],
        ] as [$label, $value]) {
            $writer->addRow(new Row([
                new StringCell($label, $this->headerStyle()),
                new NumericCell($value),
            ]));
        }

        $writer->addRow(new Row([]));
        $writer->addRow($this->textRow([__('reports.daily.title'), __('reports.statement.submissions')]));
        foreach ($reports->dailyCalendar($period) as $day) {
            $writer->addRow(new Row([
                new StringCell(CarbonImmutable::parse($day['date'], $period->timezone)->format('d/m/Y')),
                new NumericCell($day['submissions']),
            ]));
        }

        $writer->addRow(new Row([]));
        $writer->addRow($this->textRow([
            __('reports.agents.title'),
            __('reports.statement.submissions'),
        ]));
        foreach ($reports->agentSummary($period) as $agent) {
            $writer->addRow(new Row([
                new StringCell($agent['agent_name']),
                new StringCell($agent['agent_code']),
                new StringCell($agent['company']),
                new StringCell($agent['station']),
                new NumericCell($agent['submissions']),
            ]));
        }
    }

    /** @return iterable<Examination> */
    /** @param array{submitted_at: CarbonImmutable, id: int}|null $highWatermark */
    private function statementRows(ReportPeriod $period, MonthlyReportService $reports, ?array $highWatermark): iterable
    {
        $batchSize = 250;
        $lastSubmittedAt = null;
        $lastId = null;

        do {
            $query = $reports->statementQuery($period);

            if ($highWatermark !== null) {
                $query->where(function ($query) use ($highWatermark): void {
                    $query->where('submitted_at', '<', $highWatermark['submitted_at'])
                        ->orWhere(function ($query) use ($highWatermark): void {
                            $query->where('submitted_at', '=', $highWatermark['submitted_at'])
                                ->where('id', '<=', $highWatermark['id']);
                        });
                });
            }

            if ($lastSubmittedAt !== null && $lastId !== null) {
                $query->where(function ($query) use ($lastSubmittedAt, $lastId): void {
                    $query->where('submitted_at', '>', $lastSubmittedAt)
                        ->orWhere(function ($query) use ($lastSubmittedAt, $lastId): void {
                            $query->where('submitted_at', '=', $lastSubmittedAt)
                                ->where('id', '>', $lastId);
                        });
                });
            }

            $rows = $query
                ->limit($batchSize)
                ->get();

            foreach ($rows as $row) {
                yield $row;
            }

            $count = $rows->count();
            $last = $rows->last();
            $lastSubmittedAt = $last?->submitted_at;
            $lastId = $last?->id;
        } while ($count === $batchSize);
    }

    /** @return array{submitted_at: CarbonImmutable, id: int}|null */
    private function highWatermark(ReportPeriod $period, MonthlyReportService $reports): ?array
    {
        $last = $reports->exportHighWatermarkQuery($period)
            ->first();

        return $last === null
            ? null
            : ['submitted_at' => $last->submitted_at, 'id' => (int) $last->id];
    }

    private function statementRow(Examination $examination, ReportPeriod $period, int $rowNumber): Row
    {
        $submittedAt = $examination->submitted_at->setTimezone($period->timezone);
        $formType = $examination->form_type->label();
        $reason = $examination->reason?->label() ?? __('examination.reason_placeholder');

        return new Row([
            new NumericCell($rowNumber),
            new StringCell($examination->submission_no),
            new StringCell($submittedAt->format('d/m/Y')),
            new StringCell($submittedAt->format('H:i')),
            new StringCell($examination->agent_name),
            new StringCell($examination->agent_code),
            new StringCell($examination->agent_company_name),
            new StringCell($examination->agent_station_code),
            new StringCell($examination->location->label()),
            new StringCell($examination->customsFormNumbers->pluck('number')->implode(', ')),
            new StringCell($formType.($examination->form_type_other ? ' - '.$examination->form_type_other : '')),
            new StringCell($examination->container_status->label()),
            new StringCell($reason.($examination->reason_other ? ' - '.$examination->reason_other : '')),
            new StringCell($examination->attending_officer_type->label()),
            new NumericCell((int) $examination->photos_count),
        ]);
    }

    /** @param list<string> $values */
    private function textRow(array $values): Row
    {
        return new Row(array_map(fn (string $value): StringCell => new StringCell($value), $values));
    }

    /** @param list<string> $values */
    private function styledTextRow(array $values, Style $style): Row
    {
        return new Row(array_map(fn (string $value): StringCell => new StringCell($value, $style), $values));
    }

    private function headerStyle(): Style
    {
        return new Style(fontBold: true, backgroundColor: Color::rgb(229, 231, 235));
    }

    /** @return list<string> */
    private function statementHeaders(): array
    {
        return [
            __('reports.statement.number'),
            __('reports.statement.submission_number'),
            __('reports.statement.submission_date'),
            __('reports.statement.submission_time'),
            __('reports.statement.agent_name'),
            __('reports.statement.agent_code'),
            __('reports.statement.company'),
            __('reports.statement.station'),
            __('reports.statement.location'),
            __('reports.statement.customs_forms'),
            __('reports.statement.form_type'),
            __('reports.statement.container_status'),
            __('reports.statement.reason'),
            __('reports.statement.attending_officer_type'),
            __('reports.statement.evidence_photos'),
        ];
    }
}
