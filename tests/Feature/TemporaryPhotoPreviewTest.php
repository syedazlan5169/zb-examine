<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TemporaryPhotoPreviewTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zb-examine.photo_upload_disk' => 'photo_uploads',
            'zb-examine.photo_upload_direct_disk' => 'photo_uploads_spaces',
        ]);
        Storage::fake('photo_uploads');
        Storage::fake('photo_uploads_spaces');
    }

    public function test_owned_verified_local_photo_is_streamed_without_mutation(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $photo = PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => "photo-uploads/{$session->public_id}/01ARZ3NDEKTSV4RRFFQ69G5FAV.jpg",
        ]);
        Storage::disk('photo_uploads')->put($photo->storage_path, 'jpeg-test-bytes');
        $countBefore = PhotoUpload::count();

        $response = $this->get(
            "/photo-upload-sessions/{$session->public_id}/photos/{$photo->public_id}/preview",
            ['X-Photo-Upload-Token' => $token, 'Accept' => 'image/jpeg'],
        );

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertDontSee($photo->storage_path);
        $this->assertSame(['max-age=0', 'no-store', 'private'], explode(', ', $response->headers->get('Cache-Control')));
        $this->assertSame('jpeg-test-bytes', $response->streamedContent());
        $this->assertSame($countBefore, PhotoUpload::count());
        $this->assertDatabaseHas('photo_uploads', ['id' => $photo->id, 'verified_at' => $photo->verified_at]);
    }

    public function test_wrong_token_and_cross_session_photo_are_denied(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        ['session' => $otherSession, 'token' => $otherToken] = PhotoUploadSession::issue();
        $photo = PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => "photo-uploads/{$session->public_id}/01ARZ3NDEKTSV4RRFFQ69G5FAV.jpg",
        ]);
        Storage::disk('photo_uploads')->put($photo->storage_path, 'jpeg-test-bytes');

        $this->getJson(
            "/photo-upload-sessions/{$session->public_id}/photos/{$photo->public_id}/preview",
            ['X-Photo-Upload-Token' => 'wrong-token'],
        )->assertUnauthorized();

        $this->getJson(
            "/photo-upload-sessions/{$otherSession->public_id}/photos/{$photo->public_id}/preview",
            ['X-Photo-Upload-Token' => $otherToken],
        )->assertNotFound();
    }

    public function test_expired_finalized_pending_and_missing_objects_are_unavailable(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $photo = PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => "photo-uploads/{$session->public_id}/01ARZ3NDEKTSV4RRFFQ69G5FAV.jpg",
        ]);

        Storage::disk('photo_uploads')->put($photo->storage_path, 'jpeg-test-bytes');
        $session->expires_at = now()->subMinute();
        $session->save();
        $this->getJson(
            "/photo-upload-sessions/{$session->public_id}/photos/{$photo->public_id}/preview",
            ['X-Photo-Upload-Token' => $token],
        )->assertStatus(410);

        ['session' => $finalized, 'token' => $finalizedToken] = PhotoUploadSession::issue();
        $examination = Examination::factory()->create();
        $finalizedPhoto = PhotoUpload::factory()->for($finalized)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => "photo-uploads/{$finalized->public_id}/01ARZ3NDEKTSV4RRFFQ69G5FAV.jpg",
        ]);
        $finalized->examination_id = $examination->id;
        $finalized->save();
        $this->getJson(
            "/photo-upload-sessions/{$finalized->public_id}/photos/{$finalizedPhoto->public_id}/preview",
            ['X-Photo-Upload-Token' => $finalizedToken],
        )->assertStatus(409);

        ['session' => $pendingSession, 'token' => $pendingToken] = PhotoUploadSession::issue();
        $pending = PhotoUpload::factory()->pending()->for($pendingSession)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => "photo-uploads/{$pendingSession->public_id}/01ARZ3NDEKTSV4RRFFQ69G5FAV.jpg",
        ]);
        $this->getJson(
            "/photo-upload-sessions/{$pendingSession->public_id}/photos/{$pending->public_id}/preview",
            ['X-Photo-Upload-Token' => $pendingToken],
        )->assertNotFound();

        ['session' => $missingSession, 'token' => $missingToken] = PhotoUploadSession::issue();
        $missing = PhotoUpload::factory()->for($missingSession)->create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => "photo-uploads/{$missingSession->public_id}/01ARZ3NDEKTSV4RRFFQ69G5FAV.jpg",
        ]);
        $this->getJson(
            "/photo-upload-sessions/{$missingSession->public_id}/photos/{$missing->public_id}/preview",
            ['X-Photo-Upload-Token' => $missingToken],
        )->assertNotFound();
    }

    public function test_direct_photo_preview_is_streamed_through_the_application_disk(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $photo = PhotoUpload::factory()->for($session)->create([
            'storage_disk' => 'photo_uploads_spaces',
            'storage_path' => "photo-uploads/{$session->public_id}/01ARZ3NDEKTSV4RRFFQ69G5FAV/0123456789abcdef0123456789abcdef0123456789abcdef.jpg",
        ]);
        Storage::disk('photo_uploads_spaces')->put($photo->storage_path, 'spaces-jpeg-test-bytes');

        $response = $this->get(
            "/photo-upload-sessions/{$session->public_id}/photos/{$photo->public_id}/preview",
            ['X-Photo-Upload-Token' => $token],
        );

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertDontSee($photo->storage_path)
            ->assertDontSee('spaces.example.test');
        $this->assertSame(['max-age=0', 'no-store', 'private'], explode(', ', $response->headers->get('Cache-Control')));
        $this->assertSame('spaces-jpeg-test-bytes', $response->streamedContent());
    }
}
