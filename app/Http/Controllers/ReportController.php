<?php

namespace App\Http\Controllers;

use App\Data\ReportPeriod;
use App\Exports\MonthlyReportExport;
use App\Models\Examination;
use App\Services\MonthlyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request, MonthlyReportService $reports): View
    {
        Gate::authorize('viewAny', Examination::class);
        $period = $this->period($request);

        return view('reports.index', [
            'period' => $period,
            'summary' => $reports->summary($period),
            'dailyActivity' => $reports->dailyCalendar($period),
            'agentSummary' => $reports->agentSummary($period),
            'statement' => $reports->statement($period),
        ]);
    }

    public function export(Request $request, MonthlyReportService $reports): BinaryFileResponse
    {
        Gate::authorize('viewAny', Examination::class);
        $period = $this->period($request);
        $path = app(MonthlyReportExport::class)->create($period, $reports);

        return response()->download(
            $path,
            $period->filenameLabel().'-Examination-Report.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    private function period(Request $request): ReportPeriod
    {
        $timezone = (string) config('zb-examine.business_timezone', 'Asia/Kuala_Lumpur');
        $now = now($timezone);
        $validated = Validator::make($request->query(), [
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,'.($now->year + 1)],
        ])->validate();

        return ReportPeriod::make(
            (int) ($validated['year'] ?? $now->year),
            (int) ($validated['month'] ?? $now->month),
            $timezone,
        );
    }
}
