<?php

namespace Tests\Feature\Reports;

use App\Data\ReportPeriod;
use App\Exports\MonthlyReportExport;
use App\Models\Examination;
use App\Models\User;
use App\Services\MonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class MonthlyReportExportTest extends TestCase
{
    use DatabaseMigrations;

    public function test_export_contains_two_sheets_and_literal_user_text(): void
    {
        Examination::factory()->create([
            'submitted_at' => CarbonImmutable::parse('2026-09-10 01:00:00', 'UTC'),
            'agent_name' => '=SUM(1,1)',
            'agent_code' => '+1+1',
            'agent_company_name' => '-1+1',
            'agent_station_code' => '@station',
        ]);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('reports.export', ['year' => 2026, 'month' => 9]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition');

        $path = app(MonthlyReportExport::class)->create(
            ReportPeriod::make(2026, 9, 'Asia/Kuala_Lumpur'),
            app(MonthlyReportService::class),
        );

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $values = [];
            foreach ($sheet->getRowIterator() as $row) {
                $values[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $values;
        }
        $reader->close();

        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path));
        $statementXml = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        $this->assertSame(0, preg_match_all('/<f(?:\s|>)/', $statementXml));
        unlink($path);

        $this->assertSame(['Summary', 'Monthly Statement'], array_keys($sheets));
        $this->assertContains('=SUM(1,1)', array_merge(...$sheets['Monthly Statement']));
        $this->assertContains('+1+1', array_merge(...$sheets['Monthly Statement']));
        $this->assertContains('-1+1', array_merge(...$sheets['Monthly Statement']));
        $this->assertContains('@station', array_merge(...$sheets['Monthly Statement']));
    }

    public function test_empty_period_exports_a_valid_zero_row_statement(): void
    {
        $path = app(MonthlyReportExport::class)->create(
            ReportPeriod::make(2025, 1, 'Asia/Kuala_Lumpur'),
            app(MonthlyReportService::class),
        );

        $sheets = $this->readWorkbook($path);
        unlink($path);

        $this->assertSame(['Summary', 'Monthly Statement'], array_keys($sheets));
        $this->assertContains(__('reports.summary.total_submissions'), array_merge(...$sheets['Summary']));
        $this->assertContains('0', array_map('strval', array_merge(...$sheets['Summary'])));
        $this->assertSame(
            [__('reports.statement.number'), __('reports.statement.submission_number')],
            array_slice($sheets['Monthly Statement'][0], 0, 2),
        );
        $this->assertCount(1, $sheets['Monthly Statement']);
    }

    public function test_export_excludes_soft_deleted_examinations(): void
    {
        Examination::factory()->create([
            'submission_no' => 'ZB-EXPORT-LIVE',
            'submitted_at' => CarbonImmutable::parse('2026-09-10 01:00:00', 'UTC'),
        ]);
        $deleted = Examination::factory()->create([
            'submission_no' => 'ZB-EXPORT-DELETED',
            'submitted_at' => CarbonImmutable::parse('2026-09-10 02:00:00', 'UTC'),
        ]);
        $deleted->delete();

        $path = app(MonthlyReportExport::class)->create(
            ReportPeriod::make(2026, 9, 'Asia/Kuala_Lumpur'),
            app(MonthlyReportService::class),
        );
        $sheets = $this->readWorkbook($path);
        unlink($path);

        $values = array_merge(...$sheets['Monthly Statement']);
        $this->assertContains('ZB-EXPORT-LIVE', $values);
        $this->assertNotContains('ZB-EXPORT-DELETED', $values);
    }

    public function test_malay_workbook_contains_malay_summary_and_statement_headings(): void
    {
        $originalLocale = app()->getLocale();
        app()->setLocale('ms');

        try {
            $path = app(MonthlyReportExport::class)->create(
                ReportPeriod::make(2026, 9, 'Asia/Kuala_Lumpur'),
                app(MonthlyReportService::class),
            );

            $sheets = $this->readWorkbook($path);
            unlink($path);

            $this->assertContains('Ringkasan', array_merge(...$sheets['Summary']));
            $this->assertContains('No. Penyerahan', array_merge(...$sheets['Monthly Statement']));
            $this->assertContains('Foto Bukti', array_merge(...$sheets['Monthly Statement']));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_generation_failure_removes_the_temporary_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zb-report-failure-');

        try {
            $this->expectException(RuntimeException::class);
            app(MonthlyReportExport::class)->create(
                ReportPeriod::make(2026, 9, 'Asia/Kuala_Lumpur'),
                app(MonthlyReportService::class),
                static fn (): never => throw new RuntimeException('writer failed'),
                static fn () => $path,
            );
        } finally {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_export_contains_more_than_one_batch_in_order_without_duplicates(): void
    {
        $timestamp = CarbonImmutable::parse('2026-09-15 04:00:00', 'UTC');
        Examination::factory()->count(251)->sequence(
            fn ($sequence) => [
                'submission_no' => 'BATCH-'.$sequence->index,
                'submitted_at' => $timestamp,
            ],
        )->create();

        $path = app(MonthlyReportExport::class)->create(
            ReportPeriod::make(2026, 9, 'Asia/Kuala_Lumpur'),
            app(MonthlyReportService::class),
        );
        $sheets = $this->readWorkbook($path);
        unlink($path);

        $rows = array_slice($sheets['Monthly Statement'], 1);
        $submissionNumbers = array_column($rows, 1);

        $this->assertCount(251, $submissionNumbers);
        $this->assertCount(251, array_unique($submissionNumbers));
        $this->assertSame('BATCH-0', $submissionNumbers[0]);
        $this->assertSame('BATCH-250', $submissionNumbers[250]);
    }

    /** @return array<string, list<list<string|int|float|null>>> */
    private function readWorkbook(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $values = [];
            foreach ($sheet->getRowIterator() as $row) {
                $values[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $values;
        }

        $reader->close();

        return $sheets;
    }
}
