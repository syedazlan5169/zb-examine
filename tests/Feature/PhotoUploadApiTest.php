<?php

namespace Tests\Feature;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\FormType;
use App\Exceptions\PhotoUploadInvalid;
use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use App\Services\LocalPhotoUploadTransport;
use App\Services\PhotoUploadService;
use App\Services\PhotoUploadSessionResolver;
use App\Services\PhotoUploadTransport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PhotoUploadApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photo_uploads');
    }

    // ----- allocation -----

    public function test_first_allocated_photo_gets_display_order_one(): void
    {
        [$publicId, $token] = $this->createSession();

        $response = $this->allocate($publicId, $token);

        $response->assertCreated();
        $this->assertSame(1, $response->json('display_order'));
    }

    public function test_second_allocated_photo_gets_display_order_two(): void
    {
        [$publicId, $token] = $this->createSession();

        $this->allocate($publicId, $token);
        $second = $this->allocate($publicId, $token);

        $this->assertSame(2, $second->json('display_order'));
    }

    public function test_storage_path_uses_only_server_generated_identifiers(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $upload = PhotoUpload::where('public_id', $photoPublicId)->firstOrFail();

        $this->assertSame("photo-uploads/{$publicId}/{$photoPublicId}.jpg", $upload->storage_path);
    }

    public function test_allocation_is_capped_at_ten(): void
    {
        [$publicId, $token] = $this->createSession();

        for ($i = 0; $i < 10; $i++) {
            $this->allocate($publicId, $token)->assertCreated();
        }

        $response = $this->allocate($publicId, $token);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'photo_limit_reached']);
    }

    public function test_allocation_rejected_for_a_finalized_session(): void
    {
        [$publicId, $token] = $this->createSession();
        $this->finalize($publicId);

        $response = $this->allocate($publicId, $token);

        $response->assertStatus(409);
        $response->assertJson(['code' => 'session_finalized']);
    }

    public function test_allocation_rejected_for_an_expired_session(): void
    {
        [$publicId, $token] = $this->createSession();
        $this->expireSession($publicId);

        $response = $this->allocate($publicId, $token);

        $response->assertStatus(410);
        $response->assertJson(['code' => 'session_expired']);
    }

    // ----- upload/verify -----

    public function test_valid_jpeg_is_accepted_and_stored_privately(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $response = $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg', 100, 80));

        $response->assertOk();
        $response->assertJson(['status' => 'stored']);
        Storage::disk('photo_uploads')->assertExists("photo-uploads/{$publicId}/{$photoPublicId}.jpg");
    }

    public function test_non_jpeg_upload_is_rejected(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $response = $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->create('photo.png', 10, 'image/png'));

        $response->assertStatus(422);
        Storage::disk('photo_uploads')->assertMissing("photo-uploads/{$publicId}/{$photoPublicId}.jpg");
    }

    public function test_oversized_upload_is_rejected(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $response = $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->create('photo.jpg', 2049, 'image/jpeg'));

        $response->assertStatus(422);
    }

    public function test_photo_too_large_is_rejected_by_the_authoritative_transport_check(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $upload = PhotoUpload::where('public_id', $photoPublicId)->firstOrFail();

        // Bypasses the FormRequest layer to exercise verify()'s own authoritative size check.
        Storage::disk('photo_uploads')->put($upload->storage_path, str_repeat('a', 3 * 1024 * 1024));

        try {
            app(LocalPhotoUploadTransport::class)->verify($upload);
            $this->fail('Expected PhotoUploadInvalid to be thrown.');
        } catch (PhotoUploadInvalid $e) {
            $this->assertSame('photo_too_large', $e->getErrorCode());
        }
    }

    public function test_complete_derives_actual_metadata_from_the_stored_file(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg', 120, 90))->assertOk();

        $response = $this->completePhoto($publicId, $token, $photoPublicId);

        $response->assertOk();
        $response->assertJson([
            'verified' => true,
            'mime_type' => 'image/jpeg',
            'width' => 120,
            'height' => 90,
        ]);
        $this->assertGreaterThan(0, $response->json('file_size'));
    }

    public function test_client_supplied_metadata_is_ignored_in_favor_of_server_derived_values(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg', 64, 48));

        // The complete endpoint accepts no body fields at all \u2014 nothing client-supplied
        // can influence the derived metadata, regardless of what a client might send.
        $response = $this->postJson(
            "/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}/complete",
            ['width' => 9999, 'height' => 9999, 'file_size' => 1, 'mime_type' => 'image/png'],
            $this->authHeader($token),
        );

        $response->assertJson(['width' => 64, 'height' => 48, 'mime_type' => 'image/jpeg']);
    }

    public function test_verified_at_is_null_after_upload_and_set_only_after_complete(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg'));

        $this->assertNull(PhotoUpload::where('public_id', $photoPublicId)->firstOrFail()->verified_at);

        $this->completePhoto($publicId, $token, $photoPublicId);

        $this->assertNotNull(PhotoUpload::where('public_id', $photoPublicId)->firstOrFail()->verified_at);
    }

    public function test_corrupt_image_is_rejected_at_complete_but_row_and_object_remain_until_explicit_removal(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        // Real .jpg extension/declared mime, garbage bytes: passes the FormRequest,
        // fails the authoritative exif_imagetype()/getimagesize() check.
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg'))->assertOk();

        $response = $this->completePhoto($publicId, $token, $photoPublicId);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'invalid_photo']);

        // A failed verification is not proof the object is safe to delete: the
        // row stays pending and the object stays intact, exactly as before.
        $this->assertDatabaseHas('photo_uploads', ['public_id' => $photoPublicId]);
        $this->assertNull(PhotoUpload::where('public_id', $photoPublicId)->firstOrFail()->verified_at);
        Storage::disk('photo_uploads')->assertExists("photo-uploads/{$publicId}/{$photoPublicId}.jpg");

        // Only an explicit remove cleans up both, row-first then object.
        $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token))
            ->assertNoContent();

        $this->assertDatabaseMissing('photo_uploads', ['public_id' => $photoPublicId]);
        Storage::disk('photo_uploads')->assertMissing("photo-uploads/{$publicId}/{$photoPublicId}.jpg");
    }

    public function test_a_transient_verification_failure_never_destroys_a_recoverable_object(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg', 60, 45))->assertOk();

        $storagePath = "photo-uploads/{$publicId}/{$photoPublicId}.jpg";
        $originalBytes = Storage::disk('photo_uploads')->get($storagePath);

        // Exercised at the service level (not HTTP): Laravel's Router caches a
        // resolved controller instance per Route object, so a container
        // rebind after this same route has already been dispatched once
        // within a test would silently have no effect on the next request.
        $failingTransport = new class implements PhotoUploadTransport
        {
            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                // One of the existing safe verification codes \u2014 no new code.
                throw new PhotoUploadInvalid('invalid_photo');
            }

            public function delete(PhotoUpload $upload): void {}
        };

        $serviceWithFailingTransport = new PhotoUploadService(app(PhotoUploadSessionResolver::class), $failingTransport);

        try {
            $serviceWithFailingTransport->complete($publicId, $token, $photoPublicId);
            $this->fail('Expected PhotoUploadInvalid to be thrown.');
        } catch (PhotoUploadInvalid $e) {
            $this->assertSame('invalid_photo', $e->getErrorCode());
        }

        $this->assertNull(PhotoUpload::where('public_id', $photoPublicId)->firstOrFail()->verified_at);
        Storage::disk('photo_uploads')->assertExists($storagePath);
        $this->assertSame($originalBytes, Storage::disk('photo_uploads')->get($storagePath));

        // Retry with the real transport against the exact same, untouched object.
        $completed = app(PhotoUploadService::class)->complete($publicId, $token, $photoPublicId);

        $this->assertNotNull($completed->verified_at);
        $this->assertSame('image/jpeg', $completed->mime_type);
        $this->assertSame(60, $completed->width);
        $this->assertSame(45, $completed->height);
        Storage::disk('photo_uploads')->assertExists($storagePath);
    }

    public function test_complete_before_any_upload_returns_upload_not_ready(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $response = $this->completePhoto($publicId, $token, $photoPublicId);

        $response->assertStatus(409);
        $response->assertJson(['code' => 'upload_not_ready']);
    }

    public function test_duplicate_complete_returns_success_without_changing_verified_at_or_metadata(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg', 50, 50));

        $first = $this->completePhoto($publicId, $token, $photoPublicId);
        $verifiedAtAfterFirst = PhotoUpload::where('public_id', $photoPublicId)->firstOrFail()->verified_at;

        $second = $this->completePhoto($publicId, $token, $photoPublicId);

        $second->assertOk();
        $second->assertJson($first->json());
        $verifiedAtAfterSecond = PhotoUpload::where('public_id', $photoPublicId)->firstOrFail()->verified_at;
        $this->assertTrue($verifiedAtAfterFirst->equalTo($verifiedAtAfterSecond));
    }

    public function test_reupload_on_an_already_verified_photo_returns_photo_state_conflict(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg'));
        $this->completePhoto($publicId, $token, $photoPublicId);

        $response = $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg'));

        $response->assertStatus(409);
        $response->assertJson(['code' => 'photo_state_conflict']);
    }

    // ----- write-once / race regressions -----

    public function test_a_second_store_attempt_never_overwrites_an_already_published_object(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $upload = PhotoUpload::where('public_id', $photoPublicId)->firstOrFail();

        app(LocalPhotoUploadTransport::class)->store($upload, UploadedFile::fake()->image('a.jpg', 40, 30));
        $originalBytes = Storage::disk('photo_uploads')->get($upload->storage_path);

        try {
            app(LocalPhotoUploadTransport::class)->store($upload, UploadedFile::fake()->image('b.jpg', 999, 999));
            $this->fail('Expected PhotoUploadInvalid to be thrown.');
        } catch (PhotoUploadInvalid $e) {
            $this->assertSame('photo_state_conflict', $e->getErrorCode());
        }

        Storage::disk('photo_uploads')->assertExists($upload->storage_path);
        $this->assertSame($originalBytes, Storage::disk('photo_uploads')->get($upload->storage_path));
    }

    public function test_the_http_upload_endpoint_rejects_a_retry_without_overwriting_the_first_object(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('a.jpg', 40, 30))->assertOk();
        $originalBytes = Storage::disk('photo_uploads')->get("photo-uploads/{$publicId}/{$photoPublicId}.jpg");

        $response = $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('b.jpg', 999, 999));

        $response->assertStatus(409);
        $response->assertJson(['code' => 'photo_state_conflict']);
        $this->assertSame(
            $originalBytes,
            Storage::disk('photo_uploads')->get("photo-uploads/{$publicId}/{$photoPublicId}.jpg"),
        );
    }

    public function test_a_published_object_survives_a_complete_call_that_verifies_it_first(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $service = app(PhotoUploadService::class);

        // The upload request has already published its object by the time this
        // line runs — simulating a concurrent complete() winning the race before
        // the upload request would ever have looked at DB state again.
        $service->upload($publicId, $token, $photoPublicId, UploadedFile::fake()->image('a.jpg', 40, 30));
        $completed = $service->complete($publicId, $token, $photoPublicId);

        $this->assertNotNull($completed->verified_at);
        $this->assertSame('image/jpeg', $completed->mime_type);
        Storage::disk('photo_uploads')->assertExists("photo-uploads/{$publicId}/{$photoPublicId}.jpg");

        $fresh = PhotoUpload::where('public_id', $photoPublicId)->firstOrFail();
        $this->assertNotNull($fresh->verified_at);
    }

    public function test_a_late_publish_after_its_row_was_removed_does_not_recreate_the_row_or_break_consistency(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        // Simulates the row already having been removed (e.g. by a concurrent
        // remove()) before this in-flight upload's publish actually lands —
        // the service API itself would reject at prepareForUpload() before
        // reaching storage, so the transport is exercised directly to prove
        // the same invariant at the layer where the race actually resolves.
        $upload = PhotoUpload::where('public_id', $photoPublicId)->firstOrFail();
        $upload->delete();

        app(LocalPhotoUploadTransport::class)->store($upload, UploadedFile::fake()->image('late.jpg', 20, 20));

        $this->assertDatabaseMissing('photo_uploads', ['public_id' => $photoPublicId]);
        Storage::disk('photo_uploads')->assertExists($upload->storage_path);
    }

    // ----- races/state -----

    public function test_a_photo_cannot_be_manipulated_using_a_foreign_sessions_token(): void
    {
        [$publicIdA, $tokenA] = $this->createSession();
        [, $tokenB] = $this->createSession();
        $photoPublicId = $this->allocate($publicIdA, $tokenA)->json('public_id');

        $response = $this->deleteJson(
            "/photo-upload-sessions/{$publicIdA}/photos/{$photoPublicId}",
            [],
            $this->authHeader($tokenB),
        );

        $response->assertStatus(401);
        $response->assertJson(['code' => 'invalid_session']);
    }

    public function test_finalized_session_rejects_every_mutating_operation(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->finalize($publicId);

        $this->allocate($publicId, $token)->assertStatus(409)->assertJson(['code' => 'session_finalized']);

        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg'))
            ->assertStatus(409)->assertJson(['code' => 'session_finalized']);

        $this->completePhoto($publicId, $token, $photoPublicId)
            ->assertStatus(409)->assertJson(['code' => 'session_finalized']);

        $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token))
            ->assertStatus(409)->assertJson(['code' => 'session_finalized']);
    }

    public function test_completing_a_since_removed_photo_returns_photo_not_found(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg'));

        $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token))
            ->assertNoContent();

        $response = $this->completePhoto($publicId, $token, $photoPublicId);

        $response->assertStatus(404);
        $response->assertJson(['code' => 'photo_not_found']);
    }

    // ----- removal -----

    public function test_pending_photo_can_be_removed(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token))
            ->assertNoContent();

        $this->assertDatabaseMissing('photo_uploads', ['public_id' => $photoPublicId]);
    }

    public function test_verified_photo_can_be_removed_and_object_deleted_after_the_row(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg'));
        $this->completePhoto($publicId, $token, $photoPublicId);

        $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token))
            ->assertNoContent();

        $this->assertDatabaseMissing('photo_uploads', ['public_id' => $photoPublicId]);
        Storage::disk('photo_uploads')->assertMissing("photo-uploads/{$publicId}/{$photoPublicId}.jpg");
    }

    public function test_removing_a_foreign_photo_is_rejected(): void
    {
        [$publicIdA, $tokenA] = $this->createSession();
        [$publicIdB, $tokenB] = $this->createSession();
        $photoPublicId = $this->allocate($publicIdA, $tokenA)->json('public_id');

        $response = $this->deleteJson(
            "/photo-upload-sessions/{$publicIdB}/photos/{$photoPublicId}",
            [],
            $this->authHeader($tokenB),
        );

        $response->assertStatus(404);
        $response->assertJson(['code' => 'photo_not_found']);
    }

    public function test_removal_rejected_for_a_finalized_session(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->finalize($publicId);

        $response = $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $response->assertJson(['code' => 'session_finalized']);
    }

    public function test_removal_rejected_for_an_expired_session(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->expireSession($publicId);

        $response = $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token));

        $response->assertStatus(410);
        $response->assertJson(['code' => 'session_expired']);
    }

    public function test_storage_deletion_failure_does_not_fail_the_request_or_recreate_the_row(): void
    {
        $this->app->bind(PhotoUploadTransport::class, fn () => new class implements PhotoUploadTransport
        {
            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return ['mime_type' => 'image/jpeg', 'file_size' => 1, 'width' => 1, 'height' => 1];
            }

            public function delete(PhotoUpload $upload): void
            {
                throw new RuntimeException('simulated storage failure');
            }
        });

        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');

        Exceptions::fake();

        $response = $this->deleteJson("/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('photo_uploads', ['public_id' => $photoPublicId]);
        Exceptions::assertReported(RuntimeException::class);
    }

    // ----- resume -----

    public function test_resume_returns_only_safe_fields_ordered_by_display_order(): void
    {
        [$publicId, $token] = $this->createSession();
        $first = $this->allocate($publicId, $token)->json('public_id');
        $second = $this->allocate($publicId, $token)->json('public_id');

        $response = $this->getJson("/photo-upload-sessions/{$publicId}", $this->authHeader($token));

        $response->assertOk();
        $photos = $response->json('photos');
        $this->assertSame([$first, $second], array_column($photos, 'public_id'));
        $this->assertArrayNotHasKey('storage_path', $photos[0]);
        $this->assertArrayNotHasKey('storage_disk', $photos[0]);
        $this->assertArrayNotHasKey('id', $photos[0]);
        $this->assertArrayNotHasKey('photo_upload_session_id', $photos[0]);
    }

    public function test_finalized_session_resume_still_succeeds_and_is_marked_finalized(): void
    {
        [$publicId, $token] = $this->createSession();
        $this->finalize($publicId);

        $response = $this->getJson("/photo-upload-sessions/{$publicId}", $this->authHeader($token));

        $response->assertOk();
        $response->assertJson(['finalized' => true]);
    }

    // ----- regression -----

    public function test_no_examination_or_examination_photo_is_created_by_this_phase(): void
    {
        [$publicId, $token] = $this->createSession();
        $photoPublicId = $this->allocate($publicId, $token)->json('public_id');
        $this->uploadFile($publicId, $token, $photoPublicId, UploadedFile::fake()->image('photo.jpg'));
        $this->completePhoto($publicId, $token, $photoPublicId);

        $this->assertSame(0, Examination::count());
        $this->assertSame(0, ExaminationPhoto::count());
    }

    // ----- helpers -----

    private function createSession(): array
    {
        $response = $this->postJson('/photo-upload-sessions');

        return [$response->json('public_id'), $response->json('token')];
    }

    private function allocate(string $publicId, string $token)
    {
        return $this->postJson("/photo-upload-sessions/{$publicId}/photos", [], $this->authHeader($token));
    }

    private function uploadFile(string $publicId, string $token, string $photoPublicId, UploadedFile $file)
    {
        return $this->post(
            "/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}/upload",
            ['photo' => $file],
            $this->authHeader($token),
        );
    }

    private function completePhoto(string $publicId, string $token, string $photoPublicId)
    {
        return $this->postJson(
            "/photo-upload-sessions/{$publicId}/photos/{$photoPublicId}/complete",
            [],
            $this->authHeader($token),
        );
    }

    private function authHeader(string $token): array
    {
        return ['X-Photo-Upload-Token' => $token, 'Accept' => 'application/json'];
    }

    private function finalize(string $publicId): void
    {
        $examination = $this->createExamination();

        PhotoUploadSession::where('public_id', $publicId)->update(['examination_id' => $examination->id]);
    }

    private function expireSession(string $publicId): void
    {
        PhotoUploadSession::where('public_id', $publicId)->update(['expires_at' => now()->subHour()]);
    }

    private function createExamination(): Examination
    {
        return Examination::create([
            'submission_no' => 'ZB-'.now()->format('ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'agent_name' => 'Test Agent',
            'agent_phone' => '0123456789',
            'agent_code' => 'AGT-001',
            'agent_company_name' => 'Test Sdn Bhd',
            'agent_station_code' => 'STN-01',
            'location' => ExaminationLocation::cases()[0],
            'form_type' => FormType::cases()[0],
            'container_status' => ContainerStatus::cases()[0],
            'attending_officer_type' => AttendingOfficerType::cases()[0],
            'submitted_at' => now(),
        ]);
    }
}
