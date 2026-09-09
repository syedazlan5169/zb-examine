<?php

namespace Tests\Feature;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\FormType;
use App\Models\Examination;
use App\Models\ExaminationCustomsFormNumber;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use App\Models\User;
use App\Services\PhotoUploadTransport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Step 3B.4: real Examination form + atomic photo finalization. See D022 in
 * docs/DECISIONS.md. Non-photo submission behavior is covered by the existing
 * ExaminationSubmissionFormTest; this file focuses on photo-specific behavior.
 */
class ExaminationPhotoFinalizationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_guest_happy_path_with_one_verified_photo(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $upload = PhotoUpload::factory()->for($session)->create();

        $response = $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $response->assertRedirect(route('examinations.success'));

        $examination = Examination::firstOrFail();
        $this->assertNull($examination->user_id);

        $photo = ExaminationPhoto::where('examination_id', $examination->id)->firstOrFail();
        $this->assertSame($upload->storage_disk, $photo->storage_disk);
        $this->assertSame($upload->storage_path, $photo->storage_path);
        $this->assertSame(1, $photo->display_order);

        $session->refresh();
        $this->assertSame($examination->id, $session->examination_id);
    }

    public function test_authenticated_happy_path(): void
    {
        $user = User::factory()->create();
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $this->actingAs($user)->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]))->assertRedirect(route('examinations.success'));

        $this->assertSame($user->id, Examination::firstOrFail()->user_id);
    }

    public function test_ten_photo_happy_path_has_contiguous_display_order(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->count(10)->sequence(
            fn ($sequence) => ['display_order' => $sequence->index + 1],
        )->create();

        $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]))->assertRedirect(route('examinations.success'));

        $examination = Examination::firstOrFail();

        $orders = ExaminationPhoto::where('examination_id', $examination->id)
            ->orderBy('display_order')
            ->pluck('display_order')
            ->all();

        $this->assertSame(range(1, 10), $orders);
    }

    public function test_zero_photos_rejected_before_number_allocation(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();

        $response = $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $response->assertSessionHas('submission_error');
        $this->assertSame(0, Examination::count());
        $this->assertSame(0, DB::table('submission_sequences')->count());
    }

    public function test_pending_unverified_photo_rejected_before_number_allocation(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->pending()->for($session)->create();

        $response = $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $response->assertSessionHas('submission_error');
        $this->assertSame(0, Examination::count());
        $this->assertSame(0, DB::table('submission_sequences')->count());
    }

    public function test_invalid_token_rejected(): void
    {
        ['session' => $session] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $response = $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => 'wrong-token',
        ]));

        $response->assertSessionHas('submission_error');
        $this->assertSame(0, Examination::count());
    }

    public function test_expired_session_rejected(): void
    {
        $session = PhotoUploadSession::factory()->expired()->create();
        $token = Str::random(64);
        $session->token_hash = hash('sha256', $token);
        $session->save();
        PhotoUpload::factory()->for($session)->create();

        $response = $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $response->assertSessionHas('submission_error');
        $this->assertSame(0, Examination::count());
    }

    public function test_already_finalized_session_rejected(): void
    {
        $existing = $this->createExamination();
        $session = PhotoUploadSession::factory()->create(['examination_id' => $existing->id]);
        $token = Str::random(64);
        $session->token_hash = hash('sha256', $token);
        $session->save();
        PhotoUpload::factory()->for($session)->create();

        $response = $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $response->assertSessionHas('submission_error');
        $this->assertSame(1, Examination::count());
    }

    public function test_final_db_failure_rolls_back_examination_customs_photos_and_session_claim(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        // Force a persistence failure after the parent lock/photo checks pass,
        // while still inside the finalization transaction.
        $event = 'eloquent.creating: '.ExaminationCustomsFormNumber::class;
        Event::listen($event, function (): void {
            throw new LogicException('forced customs row failure');
        });

        $response = $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        Event::forget($event);

        $response->assertSessionHas('submission_error');
        $this->assertSame(0, Examination::count());
        $this->assertSame(0, ExaminationPhoto::count());

        $session->refresh();
        $this->assertNull($session->examination_id);
    }

    public function test_submission_number_remains_consumed_after_final_db_failure(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $event = 'eloquent.creating: '.ExaminationCustomsFormNumber::class;
        Event::listen($event, function (): void {
            throw new LogicException('forced customs row failure');
        });

        $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        Event::forget($event);

        $this->assertSame(1, DB::table('submission_sequences')->value('last_number'));
    }

    public function test_customs_number_validation_failure_happens_before_photo_finalization_and_number_allocation(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $response = $this->post('/examinations', $this->validPayload([
            'customs_form_numbers' => ['B18112068450', 'b18112068450'],
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $response->assertSessionHasErrors(['customs_form_numbers.1']);
        $this->assertSame(0, Examination::count());
        $this->assertSame(0, DB::table('submission_sequences')->count());

        $session->refresh();
        $this->assertNull($session->examination_id, 'An invalid customs-number collection must never touch the photo session.');
    }

    public function test_no_storage_transport_call_occurs_during_finalization(): void
    {
        $this->mock(PhotoUploadTransport::class, function ($mock): void {
            $mock->shouldNotReceive('store');
            $mock->shouldNotReceive('verify');
            $mock->shouldNotReceive('delete');
        });

        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]))->assertRedirect(route('examinations.success'));
    }

    public function test_ordinary_non_photo_validation_failure_leaves_photo_session_reusable(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $this->post('/examinations', $this->validPayload([
            'agent_name' => '',
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]))->assertSessionHasErrors(['agent_name']);

        $this->assertSame(0, Examination::count());

        $session->refresh();
        $this->assertNull($session->examination_id, 'Ordinary field validation failure must not finalize the photo session.');

        // The same session/token can still be used to submit successfully afterwards.
        $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]))->assertRedirect(route('examinations.success'));
    }

    public function test_real_form_requires_photo_session_fields_server_side(): void
    {
        $payload = $this->validPayload();
        unset($payload['photo_upload_session_public_id'], $payload['photo_upload_token']);

        $this->post('/examinations', $payload)->assertSessionHasErrors([
            'photo_upload_session_public_id',
            'photo_upload_token',
        ]);

        $this->assertSame(0, Examination::count());
    }

    public function test_success_flow_is_unchanged(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $examination = Examination::firstOrFail();

        $this->get(route('examinations.success'))
            ->assertOk()
            ->assertSee($examination->submission_no);
    }

    public function test_real_public_page_never_exposes_private_storage_paths(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $upload = PhotoUpload::factory()->for($session)->create();

        $this->post('/examinations', $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => $token,
        ]));

        $this->get(route('examinations.success'))->assertDontSee($upload->storage_path);
        $this->get('/')->assertDontSee($upload->storage_path);
    }

    public function test_form_request_validation_failure_never_flashes_the_raw_token(): void
    {
        $payload = $this->validPayload([
            'agent_name' => '', // forces ExaminationSubmissionRequest's own validation to fail
            'photo_upload_token' => 'super-secret-test-token',
        ]);

        $this->post('/examinations', $payload);

        $oldInput = session('_old_input');

        $this->assertIsArray($oldInput);
        $this->assertArrayNotHasKey('photo_upload_token', $oldInput);
        $this->assertStringNotContainsString('super-secret-test-token', json_encode($oldInput));
    }

    public function test_manual_controller_redirect_never_flashes_the_raw_token_but_keeps_ordinary_fields(): void
    {
        ['session' => $session] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        // Passes ExaminationSubmissionRequest validation, fails domain photo validation instead.
        $payload = $this->validPayload([
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => 'super-secret-test-token',
        ]);

        $this->post('/examinations', $payload);

        $oldInput = session('_old_input');

        $this->assertIsArray($oldInput);
        $this->assertArrayNotHasKey('photo_upload_token', $oldInput);
        $this->assertStringNotContainsString('super-secret-test-token', json_encode($oldInput));
        $this->assertSame('Ali bin Abu', $oldInput['agent_name'] ?? null);
        $this->assertSame(['B18112068450'], $oldInput['customs_form_numbers'] ?? null);
    }

    public function test_real_form_never_renders_old_value_into_the_hidden_token_field(): void
    {
        ['session' => $session] = PhotoUploadSession::issue();
        PhotoUpload::factory()->for($session)->create();

        $this->post('/examinations', $this->validPayload([
            'agent_name' => '', // trigger validation failure so old() input is flashed
            'photo_upload_session_public_id' => $session->public_id,
            'photo_upload_token' => 'super-secret-test-token',
        ]));

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="photo_upload_token"', false);
        $response->assertDontSee('super-secret-test-token');
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
            'customs_form_numbers' => ['B18112068450'],
            'container_status' => ContainerStatus::Fcl->value,
            'attending_officer_type' => AttendingOfficerType::Customs->value,
        ], $overrides);
    }

    private function createExamination(): Examination
    {
        return Examination::create([
            'submission_no' => 'ZB-000000-'.Str::random(8),
            'agent_name' => 'Ali bin Abu',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Syarikat Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => ExaminationLocation::ContainerGateTerminal,
            'form_type' => FormType::K1,
            'container_status' => ContainerStatus::Fcl,
            'attending_officer_type' => AttendingOfficerType::Customs,
            'submitted_at' => now(),
        ]);
    }
}
