<?php

namespace Tests\Feature;

use App\Enums\ExaminationReason;
use App\Enums\FormType;
use App\Models\Examination;
use App\Models\ExaminationCustomsFormNumber;
use App\Models\ExaminationPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaffExaminationRetrievalTest extends TestCase
{
    use DatabaseMigrations;

    public function test_guests_are_redirected_from_staff_routes(): void
    {
        $examination = Examination::factory()->create();

        $this->get(route('examinations.index'))->assertRedirect(route('login'));
        $this->get(route('examinations.show', $examination))->assertRedirect(route('login'));
    }

    public function test_agents_are_forbidden_from_staff_routes(): void
    {
        $examination = Examination::factory()->create();

        $this->actingAs(User::factory()->agent()->create())
            ->get(route('examinations.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->agent()->create())
            ->get(route('examinations.show', $examination))
            ->assertForbidden();
    }

    public function test_officers_and_admins_can_view_staff_routes(): void
    {
        $examination = Examination::factory()->create();

        foreach ([User::factory()->officer()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('examinations.index'))->assertOk();
            $this->actingAs($user)->get(route('examinations.show', $examination))->assertOk();
        }
    }

    public function test_list_is_ordered_by_submitted_at_then_id_and_is_paginated(): void
    {
        $timestamp = Carbon::parse('2026-09-10 02:00:00', 'UTC');
        $older = Examination::factory()->create(['submission_no' => 'ZB-OLDER', 'submitted_at' => $timestamp->copy()->subDay()]);
        $first = Examination::factory()->create(['submission_no' => 'ZB-FIRST', 'submitted_at' => $timestamp]);
        $second = Examination::factory()->create(['submission_no' => 'ZB-SECOND', 'submitted_at' => $timestamp]);
        Examination::factory()->count(24)->create();

        $response = $this->actingAs(User::factory()->officer()->create())->get(route('examinations.index'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertLessThan(strpos($content, $second->submission_no), strpos($content, $first->submission_no));
        $this->assertStringNotContainsString($older->submission_no, $content);
        $this->assertStringContainsString('page=2', $response->getContent());
    }

    public function test_index_renders_minimal_sidebar_rows_and_no_selection_state(): void
    {
        $examination = Examination::factory()->create([
            'submission_no' => 'ZB-SIDEBAR',
            'agent_name' => 'Hidden Agent',
            'agent_company_name' => 'Hidden Company',
            'agent_code' => 'HIDDEN-CODE',
            'agent_station_code' => 'HIDDEN-STATION',
            'submitted_at' => Carbon::parse('2026-09-10 00:30:00', 'UTC'),
        ]);
        ExaminationPhoto::factory()->for($examination)->create();

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.index'));

        $response->assertOk()
            ->assertSee('ZB-SIDEBAR')
            ->assertSee('10/09/2026 08:30')
            ->assertSee(__('examination.staff.select_examination'))
            ->assertSee('action="'.route('examinations.index').'"', false)
            ->assertDontSee('Hidden Agent')
            ->assertDontSee('Hidden Company')
            ->assertDontSee('HIDDEN-CODE')
            ->assertDontSee('HIDDEN-STATION')
            ->assertDontSee(trans_choice('examination.staff.photo_count', 1, ['count' => 1]));

        $this->assertStringNotContainsString('data-selected="true"', $response->getContent());
    }

    public function test_index_renders_empty_total_state(): void
    {
        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.index'))
            ->assertOk()
            ->assertSee(__('examination.staff.no_examinations'))
            ->assertSee(__('examination.staff.select_examination'));
    }

    public function test_malformed_array_search_input_is_rejected_by_validation(): void
    {
        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.index', ['search' => ['x']]));

        $response->assertRedirect()
            ->assertSessionHasErrors(['search']);
    }

    public function test_search_is_preserved_in_pagination_links(): void
    {
        Examination::factory()->count(26)->create([
            'agent_company_name' => 'PaginationCompany',
        ]);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.index', ['search' => 'PaginationCompany']));

        $response->assertOk()
            ->assertSee('search=PaginationCompany', false)
            ->assertSee('page=2', false);
    }

    public function test_list_searches_examination_and_customs_form_fields(): void
    {
        $match = Examination::factory()->create([
            'submission_no' => 'ZB-MATCH',
            'agent_code' => 'AG-MATCH',
            'agent_station_code' => 'ST-MATCH',
            'agent_name' => 'Matched Agent',
            'agent_company_name' => 'Matched Company',
        ]);
        ExaminationCustomsFormNumber::create(['examination_id' => $match->id, 'number' => 'FORM-MATCH', 'display_order' => 1]);
        $other = Examination::factory()->create(['submission_no' => 'ZB-OTHER']);

        foreach (['ZB-MATCH', 'AG-MATCH', 'ST-MATCH', 'Matched Agent', 'Matched Company', 'FORM-MATCH'] as $search) {
            $response = $this->actingAs(User::factory()->officer()->create())
                ->get(route('examinations.index', ['search' => $search]));

            $response->assertOk()->assertSee($match->submission_no)->assertDontSee($other->submission_no);
        }
    }

    public function test_whitespace_search_is_unfiltered_and_no_results_are_localized(): void
    {
        $examination = Examination::factory()->create();
        $staff = User::factory()->officer()->create();

        $this->actingAs($staff)
            ->get(route('examinations.index', ['search' => '   ']))
            ->assertOk()
            ->assertSee($examination->submission_no);

        $this->actingAs($staff)
            ->get(route('examinations.index', ['search' => 'does-not-exist']))
            ->assertOk()
            ->assertSee(__('examination.staff.no_search_results'));
    }

    public function test_selected_show_renders_sidebar_context_and_selected_marker(): void
    {
        $examination = Examination::factory()->create([
            'submission_no' => 'ZB-SELECTED',
            'agent_company_name' => 'ContextCompany',
        ]);
        Examination::factory()->count(25)->create(['agent_company_name' => 'ContextCompany']);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.show', [$examination, 'search' => 'ContextCompany', 'page' => 2]));

        $response->assertOk()
            ->assertSee('ZB-SELECTED')
            ->assertSee('ContextCompany')
            ->assertSee('data-selected="true"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('search=ContextCompany', false)
            ->assertSee('page=2', false)
            ->assertDontSee(trans_choice('examination.staff.photo_count', 1, ['count' => 1]));

        $response->assertSee(
            e(route('examinations.show', [$examination, 'search' => 'ContextCompany', 'page' => 1])),
            false,
        );

        $this->assertSame(1, substr_count($response->getContent(), 'data-selected="true"'));
    }

    public function test_detail_renders_localized_fields_timezone_ordered_children_and_protected_photo_routes(): void
    {
        $examination = Examination::factory()->create([
            'submission_no' => 'ZB-DETAIL',
            'submitted_at' => Carbon::parse('2026-09-10 00:30:00', 'UTC'),
            'form_type' => 'other',
            'form_type_other' => 'Special form',
            'reason' => 'other',
            'reason_other' => 'Special reason',
        ]);
        ExaminationCustomsFormNumber::create(['examination_id' => $examination->id, 'number' => 'FORM-2', 'display_order' => 2]);
        ExaminationCustomsFormNumber::create(['examination_id' => $examination->id, 'number' => 'FORM-1', 'display_order' => 1]);
        $firstPhoto = ExaminationPhoto::factory()->for($examination)->create(['display_order' => 1, 'storage_path' => 'private/first.jpg']);
        $secondPhoto = ExaminationPhoto::factory()->for($examination)->create(['display_order' => 2, 'storage_path' => 'private/second.jpg']);
        $other = Examination::factory()->create([
            'submission_no' => 'ZB-OTHER-DETAIL',
            'agent_name' => 'Other Detail Agent',
        ]);

        $response = $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.show', $examination));

        $response->assertOk()
            ->assertSee('ZB-DETAIL')
            ->assertSee('10/09/2026 08:30')
            ->assertSee(FormType::Other->label())
            ->assertSee('Special form')
            ->assertSee(ExaminationReason::Other->label())
            ->assertSee('Special reason')
            ->assertSee('FORM-1')
            ->assertSee('FORM-2')
            ->assertSeeInOrder([
                route('examinations.photos.preview', [$examination, $firstPhoto]),
                route('examinations.photos.preview', [$examination, $secondPhoto]),
            ], false)
            ->assertSee($other->submission_no)
            ->assertDontSee('Other Detail Agent')
            ->assertDontSee('private/first.jpg')
            ->assertDontSee('storage_disk')
            ->assertDontSee('digitaloceanspaces.com');
    }

    public function test_detail_handles_no_photos_and_success_route_remains_literal(): void
    {
        $examination = Examination::factory()->create();

        $this->actingAs(User::factory()->officer()->create())
            ->get(route('examinations.show', $examination))
            ->assertSee(__('examination.staff.no_photos'));

        $this->get(route('examinations.success'))->assertRedirect(route('examinations.create'));
    }

    public function test_staff_navigation_is_visible_only_to_staff(): void
    {
        $staffLink = 'href="'.route('examinations.index').'"';

        $this->get(route('examinations.create'))->assertDontSee($staffLink, false);

        foreach ([User::factory()->officer()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('examinations.create'))
                ->assertSee($staffLink, false);
        }

        $this->actingAs(User::factory()->agent()->create())
            ->get(route('examinations.create'))
            ->assertDontSee($staffLink, false);
    }
}
