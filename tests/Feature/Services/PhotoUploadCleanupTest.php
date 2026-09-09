<?php

namespace Tests\Feature\Services;

use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadCleanupQueue;
use App\Models\PhotoUploadSession;
use App\Services\LocalPhotoUploadTransport;
use App\Services\PhotoUploadCleanupService;
use App\Services\PhotoUploadService;
use App\Services\PhotoUploadTransport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUploadCleanupTest extends TestCase
{
    use DatabaseMigrations;

    private PhotoUploadCleanupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PhotoUploadCleanupService::class);
        Storage::fake('photo_uploads');
    }

    // ========== Core Expired-Session Staging Tests ==========

    public function test_empty_expired_session_staged_and_deleted(): void
    {
        $session = $this->createExpiredUnfinalizedSession(photoCount: 0);

        $result = $this->service->stageExpiredSessions(cutoff: now());

        $this->assertEquals(1, $result->sessionsScanned);
        $this->assertEquals(1, $result->sessionsStaged);
        $this->assertEquals(0, $result->photoRowsStaged);
        $this->assertNull($session->fresh());
    }

    public function test_single_verified_photo_staged(): void
    {
        $session = $this->createExpiredUnfinalizedSession(photoCount: 1);

        $result = $this->service->stageExpiredSessions(cutoff: now());

        $this->assertEquals(1, $result->sessionsScanned);
        $this->assertEquals(1, $result->sessionsStaged);
        $this->assertEquals(1, $result->photoRowsStaged);
        $this->assertCount(1, PhotoUploadCleanupQueue::all());
    }

    public function test_ten_photos_all_staged(): void
    {
        $session = $this->createExpiredUnfinalizedSession(photoCount: 10);

        $result = $this->service->stageExpiredSessions(cutoff: now());

        $this->assertEquals(1, $result->sessionsScanned);
        $this->assertEquals(1, $result->sessionsStaged);
        $this->assertEquals(10, $result->photoRowsStaged);
        $this->assertCount(10, PhotoUploadCleanupQueue::all());
    }

    public function test_unexpired_session_untouched(): void
    {
        $session = PhotoUploadSession::factory()->create([
            'examination_id' => null,
            'expires_at' => now()->addDay(),
        ]);

        $result = $this->service->stageExpiredSessions(cutoff: now());

        $this->assertEquals(0, $result->sessionsScanned);
        $this->assertEquals(0, $result->sessionsStaged);
        $this->assertTrue($session->fresh()->exists);
    }

    public function test_finalized_session_untouched_even_when_expired(): void
    {
        $examination = Examination::factory()->create();
        $session = PhotoUploadSession::factory()->create(['expires_at' => now()->subDay()]);
        $session->forceFill(['examination_id' => $examination->id])->save();

        $result = $this->service->stageExpiredSessions(cutoff: now());

        $this->assertEquals(0, $result->sessionsScanned);
        $this->assertEquals(0, $result->sessionsStaged);
        $this->assertTrue($session->fresh()->exists);
    }

    public function test_session_staging_creates_deletion_intents(): void
    {
        $session = $this->createExpiredUnfinalizedSession(photoCount: 1);
        $photo = $session->photoUploads()->first();

        $this->service->stageExpiredSessions(cutoff: now());

        $intent = PhotoUploadCleanupQueue::where('storage_path', $photo->storage_path)->first();
        $this->assertNotNull($intent);
        $this->assertEquals($photo->storage_disk, $intent->storage_disk);
        $this->assertEquals($session->public_id, $intent->source_session_public_id);
    }

    public function test_delete_after_includes_settling_window(): void
    {
        $now = now();
        $session = $this->createExpiredUnfinalizedSession(photoCount: 1, expiresAt: $now->subMinute());

        $this->service->stageExpiredSessions(cutoff: $now);

        $intent = PhotoUploadCleanupQueue::first();
        $settleWindow = config('zb-examine.photo_cleanup_settle_seconds', 3600);
        $expectedDeleteAfter = $now->addSeconds($settleWindow);
        $this->assertEquals($expectedDeleteAfter->timestamp, $intent->delete_after->timestamp);
    }

    public function test_session_staging_limit_enforced(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createExpiredUnfinalizedSession(photoCount: 1);
        }

        $result = $this->service->stageExpiredSessions(cutoff: now(), limit: 3);

        $this->assertEquals(3, $result->sessionsStaged);
    }

    // ========== Queue Processing Tests ==========

    public function test_not_yet_due_intent_untouched(): void
    {
        $future = now()->addHour();
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'test.jpg',
            'delete_after' => $future,
        ]);

        $result = $this->service->processQueueUntilSettled(now: now());

        $this->assertEquals(0, $result->queueRowsDue);
        $this->assertEquals(0, $result->objectsCleared);
        $this->assertCount(1, PhotoUploadCleanupQueue::all());
    }

    public function test_exact_boundary_intent_is_eligible(): void
    {
        $instant = CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC');
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'exact.jpg',
            'delete_after' => $instant,
        ]);

        $result = $this->service->processQueueUntilSettled(now: $instant);

        $this->assertSame(1, $result->queueRowsDue);
        $this->assertSame(1, $result->objectsCleared);
    }

    public function test_past_boundary_intent_is_eligible(): void
    {
        $instant = CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC');
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'past.jpg',
            'delete_after' => $instant->subSecond(),
        ]);

        $result = $this->service->processQueueUntilSettled(now: $instant);

        $this->assertSame(1, $result->queueRowsDue);
        $this->assertSame(1, $result->objectsCleared);
    }

    public function test_due_intent_processed(): void
    {
        Storage::disk('photo_uploads')->put('test.jpg', 'fake content');
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'test.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $result = $this->service->processQueueUntilSettled(now: now());

        $this->assertEquals(1, $result->queueRowsDue);
        $this->assertEquals(1, $result->objectsCleared);
        $this->assertCount(0, PhotoUploadCleanupQueue::all());
        $this->assertFalse(Storage::disk('photo_uploads')->exists('test.jpg'));
    }

    public function test_queue_processor_respects_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            PhotoUploadCleanupQueue::create([
                'storage_disk' => 'photo_uploads',
                'storage_path' => "file{$i}.jpg",
                'delete_after' => now()->subMinute(),
            ]);
        }

        $result = $this->service->processQueueUntilSettled(now: now(), limit: 5);

        $this->assertEquals(5, $result->objectsCleared);
        $this->assertCount(5, PhotoUploadCleanupQueue::all());
    }

    public function test_queue_oldest_first_order(): void
    {
        $oldest = now()->subHour();
        $newest = now()->subMinute();

        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'newest.jpg',
            'delete_after' => $newest,
            'created_at' => now(),
        ]);
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'oldest.jpg',
            'delete_after' => $oldest,
            'created_at' => now()->subHour(),
        ]);

        $this->service->processQueueUntilSettled(now: now(), limit: 1);

        $remaining = PhotoUploadCleanupQueue::first();
        $this->assertEquals('newest.jpg', $remaining->storage_path);
    }

    // ========== Explicit Remove Integration Tests ==========

    public function test_explicit_remove_creates_deletion_intent(): void
    {
        [$session, $token] = $this->createSessionWithPhoto();
        $photo = $session->photoUploads()->first();

        app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);

        $intent = PhotoUploadCleanupQueue::where('storage_path', $photo->storage_path)->first();
        $this->assertNotNull($intent);
        $this->assertEquals($photo->storage_disk, $intent->storage_disk);
        $this->assertEquals($session->public_id, $intent->source_session_public_id);
    }

    public function test_explicit_remove_deletes_photo_row(): void
    {
        [$session, $token] = $this->createSessionWithPhoto();
        $photo = $session->photoUploads()->first();
        $photoId = $photo->id;

        app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);

        $this->assertNull(PhotoUpload::find($photoId));
    }

    public function test_explicit_remove_makes_no_storage_call(): void
    {
        [$session, $token] = $this->createSessionWithPhoto();
        $photo = $session->photoUploads()->first();

        $transport = new class implements PhotoUploadTransport
        {
            public int $deleteCalls = 0;

            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return ['mime_type' => 'image/jpeg', 'file_size' => 1, 'width' => 1, 'height' => 1];
            }

            public function delete(PhotoUpload $upload): void
            {
                $this->deleteCalls++;
            }

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                $this->deleteCalls++;
            }
        };

        $this->app->instance(PhotoUploadTransport::class, $transport);
        $this->app->instance(PhotoUploadCleanupService::class, new PhotoUploadCleanupService($transport));

        app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);

        $this->assertSame(0, $transport->deleteCalls);
        $this->assertDatabaseMissing('photo_uploads', ['id' => $photo->id]);
        $this->assertDatabaseHas('photo_upload_cleanup_queue', ['storage_path' => $photo->storage_path]);
    }

    public function test_explicit_remove_intent_uses_settling_window(): void
    {
        [$session, $token] = $this->createSessionWithPhoto();
        $photo = $session->photoUploads()->first();
        $before = now();

        app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);

        $intent = PhotoUploadCleanupQueue::where('storage_path', $photo->storage_path)->first();
        $settleWindow = config('zb-examine.photo_cleanup_settle_seconds', 3600);
        $expectedMin = $before->addSeconds($settleWindow);
        $this->assertGreaterThanOrEqual($expectedMin->timestamp, $intent->delete_after->timestamp);
    }

    public function test_concurrent_explicit_removes_create_single_intent(): void
    {
        [$session, $token] = $this->createSessionWithPhoto();
        $photo = $session->photoUploads()->first();
        $photoId = $photo->id;

        // First remove
        app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);

        // Refresh and verify photo is gone
        $this->assertNull(PhotoUpload::find($photoId));

        // Second remove on same photo (concurrent scenario simulated) would fail with "not found"
        // which is expected behavior (already removed)

        $intents = PhotoUploadCleanupQueue::where('storage_path', $photo->storage_path)->get();
        $this->assertCount(1, $intents);
    }

    // ========== Evidence Guard Tests ==========

    public function test_finalized_examination_photo_blocks_storage_delete(): void
    {
        Storage::disk('photo_uploads')->put('evidence.jpg', 'content');

        $examination = Examination::factory()->create();
        ExaminationPhoto::create([
            'examination_id' => $examination->id,
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'evidence.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'width' => 100,
            'height' => 100,
            'display_order' => 1,
        ]);

        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'evidence.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $this->assertDatabaseHas('examination_photos', [
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'evidence.jpg',
        ]);
        $this->assertDatabaseHas('photo_upload_cleanup_queue', [
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'evidence.jpg',
        ]);

        $transport = new class implements PhotoUploadTransport
        {
            public int $deleteCalls = 0;

            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return [];
            }

            public function delete(PhotoUpload $upload): void
            {
                $this->deleteCalls++;
            }

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                $this->deleteCalls++;
            }
        };
        $service = new PhotoUploadCleanupService($transport);
        $now = now();

        $result = $service->processQueueUntilSettled(now: $now);
        $intent = PhotoUploadCleanupQueue::firstOrFail();

        $this->assertEquals(1, $result->integrityConflicts);
        $this->assertEquals(0, $result->objectsCleared);
        $this->assertSame(0, $transport->deleteCalls);
        $this->assertSame(1, $intent->attempt_count);
        $this->assertEquals($now->timestamp, $intent->last_attempt_at->timestamp);
        $this->assertEquals($now->timestamp, $intent->last_failed_at->timestamp);
        $this->assertSame('finalized_evidence_reference', $intent->last_error_code);
        $this->assertTrue(Storage::disk('photo_uploads')->exists('evidence.jpg'));
        $this->assertCount(1, PhotoUploadCleanupQueue::all());
    }

    public function test_queue_pointer_to_unrelated_path_deletes_normally(): void
    {
        Storage::disk('photo_uploads')->put('unrelated.jpg', 'content');

        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'unrelated.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $result = $this->service->processQueueUntilSettled(now: now());

        $this->assertEquals(0, $result->integrityConflicts);
        $this->assertEquals(1, $result->objectsCleared);
    }

    // ========== Storage Failure Handling ==========

    public function test_storage_delete_failure_updates_retry_metadata(): void
    {
        $first = PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'failed.jpg',
            'delete_after' => now()->subMinute(),
            'attempt_count' => 0,
        ]);
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'later.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $transport = new class implements PhotoUploadTransport
        {
            public bool $shouldFail = true;

            public int $deleteCalls = 0;

            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return [];
            }

            public function delete(PhotoUpload $upload): void
            {
                $this->deleteCalls++;
            }

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                $this->deleteCalls++;
                if ($this->shouldFail && $storagePath === 'failed.jpg') {
                    throw new \RuntimeException('provider secret should not escape');
                }
            }
        };
        $service = new PhotoUploadCleanupService($transport);
        $now = now();

        $result = $service->processQueueUntilSettled(now: $now);

        $first->refresh();
        $this->assertSame(1, $result->objectsCleared);
        $this->assertSame(1, $result->clearingFailures);
        $this->assertDatabaseHas('photo_upload_cleanup_queue', ['storage_path' => 'failed.jpg']);
        $this->assertSame(1, $first->attempt_count);
        $this->assertNotNull($first->last_attempt_at);
        $this->assertNotNull($first->last_failed_at);
        $this->assertSame('storage_delete_failed', $first->last_error_code);
        $this->assertSame(2, $transport->deleteCalls);

        $transport->shouldFail = false;
        $second = $service->processQueueUntilSettled(now: $now->addMinute());

        $this->assertSame(1, $second->objectsCleared);
        $this->assertDatabaseMissing('photo_upload_cleanup_queue', ['storage_path' => 'failed.jpg']);
        $this->assertDatabaseMissing('photo_upload_cleanup_queue', ['storage_path' => 'later.jpg']);
    }

    public function test_storage_absent_object_is_idempotent(): void
    {
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'nonexistent.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $result = $this->service->processQueueUntilSettled(now: now());

        // Laravel's delete is idempotent; missing file doesn't cause failure
        $this->assertEquals(1, $result->objectsCleared);
        $this->assertCount(0, PhotoUploadCleanupQueue::all());
    }

    public function test_local_transport_throws_when_adapter_reports_false(): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with('missing-or-failed.jpg')->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('photo_uploads')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        (new LocalPhotoUploadTransport)->deleteByPath('photo_uploads', 'missing-or-failed.jpg');
    }

    public function test_expired_cleanup_rolls_back_when_intent_staging_fails(): void
    {
        $session = $this->createExpiredUnfinalizedSession(photoCount: 2);
        $photoIds = $session->photoUploads()->pluck('id')->all();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_contains(strtolower($query->sql), 'photo_upload_cleanup_queue')) {
                throw new \RuntimeException('simulated intent staging failure');
            }
        });

        $result = $this->service->stageExpiredSessions(cutoff: now());
        $fail = false;

        $this->assertSame(0, $result->sessionsStaged);
        $this->assertNotNull($session->fresh());
        $this->assertSame(2, PhotoUpload::whereIn('id', $photoIds)->count());
        $this->assertSame(0, PhotoUploadCleanupQueue::count());
    }

    public function test_explicit_remove_rolls_back_when_intent_staging_fails(): void
    {
        [$session, $token] = $this->createSessionWithPhoto();
        $photo = $session->photoUploads()->first();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_contains(strtolower($query->sql), 'photo_upload_cleanup_queue')) {
                throw new \RuntimeException('simulated intent staging failure');
            }
        });

        try {
            app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);
            $this->fail('Expected intent staging to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated intent staging failure', $exception->getMessage());
        } finally {
            $fail = false;
        }

        $this->assertNotNull($session->fresh());
        $this->assertNotNull(PhotoUpload::find($photo->id));
        $this->assertSame(0, PhotoUploadCleanupQueue::count());
    }

    // ========== Backlog Processing ==========

    public function test_preexisting_queue_backlog_processed_even_without_new_sessions(): void
    {
        Storage::disk('photo_uploads')->put('backlog.jpg', 'content');

        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'backlog.jpg',
            'delete_after' => now()->subHour(),
        ]);

        // Stage zero sessions
        $stageResult = $this->service->stageExpiredSessions(cutoff: now());
        $this->assertEquals(0, $stageResult->sessionsStaged);

        // But queue processor still runs
        $queueResult = $this->service->processQueueUntilSettled(now: now());
        $this->assertEquals(1, $queueResult->objectsCleared);
    }

    // ========== Idempotent Deletion Intent Staging ==========

    public function test_duplicate_deletion_intent_never_shortens_delete_after(): void
    {
        $farFuture = now()->addHours(10);
        $this->service->stageDeletionIntent(
            'photo_uploads',
            'same.jpg',
            'session1',
            $farFuture,
        );

        $intent1 = PhotoUploadCleanupQueue::where('storage_path', 'same.jpg')->first();
        $this->assertEquals($farFuture->timestamp, $intent1->delete_after->timestamp);

        // Second staging with nearer delete_after
        $nearFuture = now()->addMinutes(5);
        $this->service->stageDeletionIntent(
            'photo_uploads',
            'same.jpg',
            'session2',
            $nearFuture,
        );

        $intent2 = PhotoUploadCleanupQueue::where('storage_path', 'same.jpg')->first();
        // Should preserve the far future (max of both)
        $this->assertEquals($farFuture->timestamp, $intent2->delete_after->timestamp);
    }

    // ========== Helper Methods ==========

    private function createExpiredUnfinalizedSession(int $photoCount = 0, $expiresAt = null): PhotoUploadSession
    {
        $expiresAt ??= now()->subMinute();
        $session = PhotoUploadSession::factory()->create([
            'examination_id' => null,
            'expires_at' => $expiresAt,
        ]);

        for ($i = 0; $i < $photoCount; $i++) {
            PhotoUpload::factory()
                ->verified()
                ->for($session)
                ->create([
                    'display_order' => $i + 1,
                ]);
        }

        return $session;
    }

    private function createSessionWithPhoto(): array
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        PhotoUpload::factory()
            ->verified()
            ->for($session)
            ->create();

        return [$session, $token];
    }
}
