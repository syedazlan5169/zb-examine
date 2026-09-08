<?php

namespace Tests\Feature;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\FormType;
use App\Models\Examination;
use App\Models\ExaminationCustomsFormNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use Tests\TestCase;

class ExaminationSubmissionFormTest extends TestCase
{
    // DatabaseMigrations (not RefreshDatabase): the submission-number generator
    // refuses to run inside a nested transaction, which RefreshDatabase would cause.
    use DatabaseMigrations;

    public function test_guest_can_view_the_form(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_malay_is_default_and_uses_the_official_product_name(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Sistem Daftar Pemeriksaan')
            ->assertSee('Pendaftaran Pemeriksaan')
            ->assertSee('NOMBOR BORANG KASTAM')
            ->assertDontSee('Examine Registration System');
    }

    public function test_english_locale_renders_english_labels(): void
    {
        $this->get('/language/en');

        $this->get('/')
            ->assertOk()
            ->assertSee('Examine Registration System')
            ->assertSee('Examination Submission')
            ->assertDontSee('Sistem Daftar Pemeriksaan');
    }

    public function test_required_fields_are_rejected(): void
    {
        $this->post('/examinations', [])
            ->assertSessionHasErrors([
                'agent_name',
                'agent_phone',
                'agent_code',
                'agent_company_name',
                'agent_station_code',
                'location',
                'form_type',
                'customs_form_numbers',
                'container_status',
                'attending_officer_type',
            ]);

        $this->assertSame(0, Examination::count());
    }

    public function test_required_field_validation_renders_the_malay_translated_message(): void
    {
        $response = $this->followingRedirects()->post('/examinations', $this->validPayload(['agent_name' => '']));

        // Proves validation.required + attributes.agent_name + locale=ms + the error bag + Blade rendering all wire together.
        $response->assertSee('Medan nama ejen wajib diisi.');
    }

    public function test_invalid_enum_values_are_rejected(): void
    {
        $this->post('/examinations', $this->validPayload([
            'location' => 'bogus',
            'form_type' => 'bogus',
            'container_status' => 'bogus',
            'reason' => 'bogus',
            'attending_officer_type' => 'bogus',
        ]))->assertSessionHasErrors([
            'location',
            'form_type',
            'container_status',
            'reason',
            'attending_officer_type',
        ]);
    }

    public function test_form_type_other_is_required_when_form_type_is_other(): void
    {
        $this->post('/examinations', $this->validPayload(['form_type' => 'other']))
            ->assertSessionHasErrors(['form_type_other']);
    }

    public function test_stale_form_type_other_does_not_persist_when_form_type_changes_away_from_other(): void
    {
        $this->post('/examinations', $this->validPayload([
            'form_type' => FormType::K1->value,
            'form_type_other' => 'Stale hidden value',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertNull(Examination::firstOrFail()->form_type_other);
    }

    public function test_reason_is_optional(): void
    {
        $payload = $this->validPayload();
        unset($payload['reason']);

        $this->post('/examinations', $payload)->assertSessionDoesntHaveErrors();

        $this->assertNull(Examination::firstOrFail()->reason);
    }

    public function test_empty_reason_normalizes_to_null_instead_of_a_validation_error(): void
    {
        $this->post('/examinations', $this->validPayload(['reason' => '']))
            ->assertSessionDoesntHaveErrors();

        $this->assertNull(Examination::firstOrFail()->reason);
    }

    public function test_reason_other_is_required_when_reason_is_other(): void
    {
        $this->post('/examinations', $this->validPayload(['reason' => 'other']))
            ->assertSessionHasErrors(['reason_other']);
    }

    public function test_stale_reason_other_does_not_persist_when_reason_changes_away_from_other(): void
    {
        $this->post('/examinations', $this->validPayload([
            'reason' => 'drawback',
            'reason_other' => 'Stale hidden value',
        ]))->assertSessionDoesntHaveErrors();

        $persisted = Examination::firstOrFail();
        $this->assertSame('drawback', $persisted->reason->value);
        $this->assertNull($persisted->reason_other);
    }

    public function test_valid_guest_submission_succeeds_and_the_success_page_shows_the_generated_number(): void
    {
        $response = $this->post('/examinations', $this->validPayload());

        $examination = Examination::firstOrFail();
        $response->assertRedirect(route('examinations.success'));
        $this->assertNull($examination->user_id);

        $success = $this->get(route('examinations.success'));
        $success->assertOk()->assertSee($examination->submission_no);

        // Refresh continues to show the number (normal session state, not flash-only).
        $this->get(route('examinations.success'))
            ->assertOk()
            ->assertSee($examination->submission_no);

        // Starting a new submission clears the previous success state.
        $this->get('/');
        $this->get(route('examinations.success'))->assertRedirect(route('examinations.create'));
    }

    public function test_success_route_redirects_to_the_form_without_prior_session_state(): void
    {
        $this->get(route('examinations.success'))->assertRedirect(route('examinations.create'));
    }

    public function test_success_session_contains_only_the_submission_number(): void
    {
        $response = $this->post('/examinations', $this->validPayload());

        $examination = Examination::firstOrFail();

        // Exact shape: no Examination model, user_id, customs numbers, agent data, or internal id leak into session.
        $response->assertSessionHas('examination_success', [
            'submission_no' => $examination->submission_no,
        ]);
    }

    public function test_authenticated_user_id_is_persisted_but_submitted_values_are_snapshotted(): void
    {
        $user = User::factory()->create([
            'name' => 'Stale Profile Name',
            'phone' => '0000000000',
            'agent_code' => 'STALE-CODE',
            'company_name' => 'Stale Company Sdn Bhd',
            'station_code' => 'STALE-STN',
        ]);

        $this->actingAs($user)->post('/examinations', $this->validPayload());

        $persisted = Examination::firstOrFail();

        $this->assertSame($user->id, $persisted->user_id);
        $this->assertSame('Ali bin Abu', $persisted->agent_name);
        $this->assertSame('0123456789', $persisted->agent_phone);
        $this->assertSame('AGT-001', $persisted->agent_code);
    }

    public function test_shorthand_customs_form_numbers_succeed(): void
    {
        $this->post('/examinations', $this->validPayload([
            'customs_form_numbers' => 'B18112068450,51,52',
        ]))->assertSessionDoesntHaveErrors();

        $examination = Examination::firstOrFail();

        $numbers = ExaminationCustomsFormNumber::where('examination_id', $examination->getKey())
            ->orderBy('display_order')
            ->pluck('number')
            ->all();

        $this->assertSame(['B18112068450', 'B18112068451', 'B18112068452'], $numbers);
    }

    public function test_invalid_customs_form_number_syntax_becomes_a_field_error_and_consumes_no_submission_number(): void
    {
        $response = $this->post('/examinations', $this->validPayload([
            'customs_form_numbers' => 'B18112068450,50',
        ]));

        $response->assertSessionHasErrors(['customs_form_numbers']);
        $this->assertSame(0, Examination::count());

        // Old input (aside from the rejected field) is preserved for the next render.
        $this->get('/')->assertSee('Ali bin Abu');

        $this->assertSame(
            0,
            DB::table('submission_sequences')->count(),
            'An invalid parser input must not consume/create a sequence row.'
        );
    }

    public function test_sequence_exhaustion_shows_a_safe_localized_form_level_error(): void
    {
        $this->travelTo(Carbon::create(2026, 9, 8, 2, 0, 0, 'UTC'), function () {
            DB::table('submission_sequences')->insert([
                'sequence_date' => '2026-09-08',
                'last_number' => 9999,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->post('/examinations', $this->validPayload());

            $response->assertSessionHas('submission_error');
            $this->assertSame(0, Examination::count());

            $this->get('/')
                ->assertSee(__('examination.errors.sequence_exhausted'))
                ->assertDontSee('SubmissionNumberSequenceExhausted', false);
        });
    }

    public function test_unexpected_persistence_failure_shows_a_safe_generic_error_and_reports_the_exception(): void
    {
        Exceptions::fake();

        $event = 'eloquent.creating: '.Examination::class;

        Event::listen($event, function (): void {
            throw new LogicException('unexpected persistence failure');
        });

        $response = $this->post('/examinations', $this->validPayload());

        Event::forget($event);

        $response->assertSessionHas('submission_error');
        $this->assertSame(0, Examination::count());

        $this->get('/')->assertDontSee('unexpected persistence failure');

        Exceptions::assertReported(LogicException::class);
    }

    public function test_form_renders_double_submit_protection_hooks(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="examination-form"', false)
            ->assertSee('id="examination-submit"', false)
            ->assertSee('data-loading-text=', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'agent_name' => 'Ali bin Abu',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Syarikat Penghantaran Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => ExaminationLocation::ContainerGateTerminal->value,
            'form_type' => FormType::K1->value,
            'customs_form_numbers' => 'B18112068450',
            'container_status' => ContainerStatus::Fcl->value,
            'attending_officer_type' => AttendingOfficerType::Customs->value,
        ], $overrides);
    }
}
