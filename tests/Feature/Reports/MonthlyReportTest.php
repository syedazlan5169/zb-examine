<?php

namespace Tests\Feature\Reports;

use App\Models\Examination;
use App\Models\ExaminationCustomsFormNumber;
use App\Models\ExaminationPhoto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use DatabaseMigrations;

    public function test_report_access_is_staff_only(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->get(route('reports.export'))->assertRedirect(route('login'));

        foreach ([User::factory()->agent()->create()] as $agent) {
            $this->actingAs($agent)->get(route('reports.index'))->assertForbidden();
            $this->actingAs($agent)->get(route('reports.export'))->assertForbidden();
        }

        foreach ([User::factory()->officer()->create(), User::factory()->admin()->create()] as $staff) {
            $this->actingAs($staff)->get(route('reports.index', ['year' => 2026, 'month' => 9]))->assertOk();
        }
    }

    public function test_report_export_link_preserves_the_selected_month(): void
    {
        $user = User::factory()->officer()->create();

        $this->actingAs($user)
            ->get(route('reports.index', ['year' => 2026, 'month' => 1]))
            ->assertOk()
            ->assertSee(route('reports.export', ['year' => 2026, 'month' => 1]));

        $this->actingAs($user)
            ->get(route('reports.index', ['year' => 2026, 'month' => 3]))
            ->assertOk()
            ->assertSee(route('reports.export', ['year' => 2026, 'month' => 3]));
    }

    public function test_report_uses_approved_metrics_and_historical_snapshots(): void
    {
        $registered = User::factory()->agent()->create([
            'name' => 'Current Name',
            'agent_code' => 'CURRENT-CODE',
        ]);

        $first = Examination::factory()->for($registered)->create([
            'submission_no' => 'ZB-260901-0001',
            'submitted_at' => CarbonImmutable::parse('2026-09-01 00:05:00', 'Asia/Kuala_Lumpur'),
            'agent_name' => 'Original Name',
            'agent_code' => 'ORIGINAL-CODE',
            'agent_company_name' => 'Original Company',
            'agent_station_code' => 'ST-ORIGINAL',
        ]);
        ExaminationPhoto::factory()->for($first)->count(2)->create();
        ExaminationCustomsFormNumber::create(['examination_id' => $first->id, 'number' => 'FORM-2', 'display_order' => 2]);
        ExaminationCustomsFormNumber::create(['examination_id' => $first->id, 'number' => 'FORM-1', 'display_order' => 1]);

        $registered->update(['name' => 'Changed Name', 'agent_code' => 'CHANGED-CODE']);

        Examination::factory()->for($registered)->create([
            'submission_no' => 'ZB-260902-0002',
            'submitted_at' => CarbonImmutable::parse('2026-09-02 02:00:00', 'UTC'),
            'agent_name' => 'Latest Snapshot',
            'agent_code' => 'LATEST-CODE',
            'agent_company_name' => 'Latest Company',
            'agent_station_code' => 'ST-LATEST',
        ]);
        Examination::factory()->create([
            'submission_no' => 'ZB-260903-0003',
            'user_id' => null,
            'submitted_at' => CarbonImmutable::parse('2026-09-03 02:00:00', 'UTC'),
            'agent_name' => 'Guest Agent',
            'agent_code' => 'GUEST-1',
            'agent_company_name' => 'Guest Company',
            'agent_station_code' => 'ST-GUEST',
        ]);
        Examination::factory()->create([
            'submission_no' => 'ZB-260804-0004',
            'submitted_at' => CarbonImmutable::parse('2026-08-31 15:55:00', 'UTC'),
        ]);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('reports.index', ['year' => 2026, 'month' => 9]));

        $response->assertOk()
            ->assertSee('Original Name')
            ->assertSee('Latest Snapshot')
            ->assertSee('FORM-1, FORM-2')
            ->assertDontSee('Changed Name')
            ->assertDontSee('ZB-260804-0004');

        $view = $response->viewData('summary');
        $this->assertSame(3, $view['total_submissions']);
        $this->assertSame(2, $view['unique_agents']);
        $this->assertSame(2, $view['evidence_photos']);
        $this->assertSame(1.0, $view['average_per_active_day']);
    }

    public function test_daily_activity_includes_zero_days_and_statement_number_respects_pagination(): void
    {
        foreach ([1, 3] as $day) {
            Examination::factory()->create([
                'submitted_at' => CarbonImmutable::create(2026, 9, $day, 1, 0, 0, 'UTC'),
            ]);
        }
        Examination::factory()->count(24)->create([
            'submitted_at' => CarbonImmutable::create(2026, 9, 10, 1, 0, 0, 'UTC'),
        ]);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('reports.index', ['year' => 2026, 'month' => 9, 'page' => 2]));

        $response->assertOk()->assertSee('26');
        $daily = $response->viewData('dailyActivity');
        $this->assertCount(30, $daily);
        $this->assertSame(0, $daily[1]['submissions']);
        $this->assertSame(1, $daily[2]['submissions']);
    }

    public function test_agent_summary_retains_more_than_thirty_agents(): void
    {
        Examination::factory()->count(35)->sequence(
            fn ($sequence) => [
                'submission_no' => sprintf('ZB-AGENT-RETENTION-%02d', $sequence->index + 1),
                'user_id' => null,
                'submitted_at' => CarbonImmutable::parse('2026-09-10 01:00:00', 'UTC'),
                'agent_name' => sprintf('Agent Retention %02d', $sequence->index + 1),
                'agent_code' => sprintf('AG-RET-%03d', $sequence->index + 1),
                'agent_company_name' => sprintf('Retention Company %02d', $sequence->index + 1),
                'agent_station_code' => sprintf('RET-%02d', $sequence->index + 1),
            ],
        )->create();

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('reports.index', ['year' => 2026, 'month' => 9]));

        $response->assertOk()
            ->assertSee('Agent Retention 01')
            ->assertSee('Agent Retention 35');

        $agentSummary = $response->viewData('agentSummary');

        $this->assertGreaterThan(30, $agentSummary);
        $this->assertCount(35, $agentSummary);
        $this->assertSame('Agent Retention 01', $agentSummary[0]['agent_name']);
        $this->assertSame('Agent Retention 35', $agentSummary[34]['agent_name']);
    }

    public function test_invalid_period_parameters_are_rejected(): void
    {
        $user = User::factory()->officer()->create();

        $this->actingAs($user)->get(route('reports.index', ['month' => 13, 'year' => 2026]))->assertSessionHasErrors('month');
        $this->actingAs($user)->get(route('reports.export', ['month' => 9, 'year' => -500]))->assertSessionHasErrors('year');
    }

    public function test_reports_navigation_is_visible_only_to_staff(): void
    {
        $link = 'href="'.route('reports.index').'"';

        $this->get(route('examinations.create'))->assertDontSee($link, false);

        foreach ([User::factory()->officer()->create(), User::factory()->admin()->create()] as $staff) {
            $this->actingAs($staff)
                ->get(route('examinations.create'))
                ->assertSee($link, false);
        }

        $this->actingAs(User::factory()->agent()->create())
            ->get(route('examinations.create'))
            ->assertDontSee($link, false);
    }

    public function test_soft_deleted_examinations_are_excluded_from_report_metrics_and_statement(): void
    {
        $live = Examination::factory()->create([
            'submission_no' => 'ZB-LIVE-REPORT',
            'submitted_at' => CarbonImmutable::parse('2026-09-10 01:00:00', 'UTC'),
        ]);
        $deleted = Examination::factory()->create([
            'submission_no' => 'ZB-DELETED-REPORT',
            'submitted_at' => CarbonImmutable::parse('2026-09-10 02:00:00', 'UTC'),
        ]);
        ExaminationPhoto::factory()->for($live)->count(1)->create();
        ExaminationPhoto::factory()->for($deleted)->count(2)->create();
        $deleted->delete();

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('reports.index', ['year' => 2026, 'month' => 9]));

        $response->assertOk()
            ->assertSee('ZB-LIVE-REPORT')
            ->assertDontSee('ZB-DELETED-REPORT');
        $summary = $response->viewData('summary');
        $this->assertSame(1, $summary['total_submissions']);
        $this->assertSame(1, $summary['evidence_photos']);
    }

    public function test_report_labels_follow_the_current_locale(): void
    {
        $this->actingAs(User::factory()->officer()->create())
            ->get(route('reports.index', ['year' => 2026, 'month' => 9]))
            ->assertSee(__('reports.title'))
            ->assertSee(__('reports.summary.total_submissions'));

        $this->withSession(['locale' => 'en'])
            ->actingAs(User::factory()->officer()->create(['preferred_locale' => 'en']))
            ->get(route('reports.index', ['year' => 2026, 'month' => 9]))
            ->assertSee('Reports')
            ->assertSee('Total Submissions');
    }
}
