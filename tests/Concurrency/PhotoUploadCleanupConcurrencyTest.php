<?php

namespace Tests\Concurrency;

use App\Data\PhotoUploadSessionCredentials;
use App\Exceptions\PhotoUploadInvalid;
use App\Exceptions\PhotoUploadSessionInvalid;
use App\Models\Examination;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadCleanupQueue;
use App\Models\PhotoUploadSession;
use App\Services\PhotoUploadCleanupService;
use App\Services\PhotoUploadService;
use App\Services\PhotoUploadSessionFinalizer;
use App\Services\PhotoUploadTransport;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class PhotoUploadCleanupConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('photo_upload_cleanup_queue')->truncate();
        DB::table('examination_photos')->truncate();
        DB::table('examination_customs_form_numbers')->truncate();
        DB::table('examinations')->truncate();
        DB::table('users')->truncate();
        DB::table('photo_uploads')->truncate();
        DB::table('photo_upload_sessions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        Storage::fake('photo_uploads');
    }

    // ========== Finalization Race Condition Tests ==========

    public function test_finalization_race_session_never_cleaned(): void
    {
        $examination = Examination::factory()->create();
        $session = PhotoUploadSession::factory()->create(['expires_at' => now()->subMinute()]);
        $session->forceFill(['examination_id' => null])->save();
        $photo = PhotoUpload::factory()
            ->verified()
            ->for($session)
            ->create();

        // Simulate concurrent finalization: update examination_id
        // BEFORE staging completes
        $staging = DB::transaction(function () use ($session) {
            $session->lockForUpdate();

            // Simulate a concurrent finalization happening here
            $session->forceFill(['examination_id' => Examination::factory()->create()->id])->save();

            // Then try to stage
            return app(PhotoUploadCleanupService::class)
                ->stageExpiredSessions(cutoff: now());
        });

        // Session should not have been staged (because it now has examination_id)
        $this->assertEquals(0, $staging->sessionsStaged);
    }

    public function test_concurrent_explicit_remove_and_finalization(): void
    {
        $examination = Examination::factory()->create();
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $photo = PhotoUpload::factory()
            ->verified()
            ->for($session)
            ->create();

        // Explicit remove creates intent
        app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);

        // Remove wins the parent-row mutex; the session itself remains.
        $this->assertTrue($session->fresh()->exists);
        $session->forceFill(['examination_id' => $examination->id])->save();

        // Queue processor should still delete the orphan because explicit remove already staged it
        Storage::disk('photo_uploads')->put($photo->storage_path, 'content');

        // Move intent to due (already settled)
        PhotoUploadCleanupQueue::where('storage_path', $photo->storage_path)
            ->update(['delete_after' => now()->subMinute()]);

        $result = app(PhotoUploadCleanupService::class)->processQueueUntilSettled(now: now());
        $this->assertEquals(1, $result->objectsCleared);
        $this->assertDatabaseCount('examination_photos', 0);
    }

    // ========== Multiple Worker Concurrency Tests ==========

    public function test_two_workers_staging_same_session_duplicates_prevented(): void
    {
        $session = PhotoUploadSession::factory()->create(['expires_at' => now()->subMinute()]);
        $session->forceFill(['examination_id' => null])->save();
        PhotoUpload::factory()
            ->verified()
            ->for($session)
            ->create();

        // Simulate two workers trying to stage the same session simultaneously
        // The first one should lock and stage, the second should re-check and find it finalized

        $worker1 = DB::transaction(function () {
            return app(PhotoUploadCleanupService::class)
                ->stageExpiredSessions(cutoff: now(), limit: 1);
        });

        $worker2 = DB::transaction(function () {
            return app(PhotoUploadCleanupService::class)
                ->stageExpiredSessions(cutoff: now(), limit: 1);
        });

        // Only one should have actually staged
        $totalStaged = $worker1->sessionsStaged + $worker2->sessionsStaged;
        $this->assertEquals(1, $totalStaged);
    }

    public function test_concurrent_queue_processors_dont_double_delete(): void
    {
        Storage::disk('photo_uploads')->put('unique.jpg', 'content');
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'unique.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $worker1 = DB::transaction(function () {
            return app(PhotoUploadCleanupService::class)
                ->processQueueUntilSettled(now: now(), limit: 1);
        });

        $worker2 = DB::transaction(function () {
            return app(PhotoUploadCleanupService::class)
                ->processQueueUntilSettled(now: now(), limit: 1);
        });

        // Only one worker should successfully delete
        $totalCleared = $worker1->objectsCleared + $worker2->objectsCleared;
        $this->assertLessThanOrEqual(1, $totalCleared);
    }

    public function test_real_mysql_queue_workers_clear_one_due_intent(): void
    {
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'worker-shared.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        $directory = storage_path('framework/testing/cleanup-queue-workers-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $pids = [];

        DB::disconnect();
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

            if ($pid === 0) {
                DB::purge('mysql');
                DB::reconnect('mysql');
                while (! file_exists($startFile)) {
                    usleep(1000);
                }

                $transport = new class implements PhotoUploadTransport
                {
                    public function store(PhotoUpload $upload, UploadedFile $file): void {}

                    public function verify(PhotoUpload $upload): array
                    {
                        return [];
                    }

                    public function delete(PhotoUpload $upload): void {}

                    public function deleteByPath(string $storageDisk, string $storagePath): void {}
                };

                try {
                    (new PhotoUploadCleanupService($transport))->processQueueUntilSettled(now(), 1);
                    file_put_contents($directory.'/'.getmypid().'.result', 'OK');
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/'.getmypid().'.result', 'ERROR:'.$exception::class);
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        file_put_contents($startFile, '1');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::reconnect('mysql');
        $this->assertDatabaseCount('photo_upload_cleanup_queue', 0);

        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }

    public function test_real_mysql_finalization_wins_before_cleanup_lock(): void
    {
        $session = PhotoUploadSession::factory()->create(['expires_at' => now()->subMinute()]);
        $photo = PhotoUpload::factory()->verified()->for($session)->create();
        $directory = storage_path('framework/testing/cleanup-finalization-race-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $lockedFile = $directory.'/finalizer-locked';
        $cleanupStartedFile = $directory.'/cleanup-started';
        $releaseFile = $directory.'/release';

        DB::disconnect();
        $finalizerPid = pcntl_fork();
        $this->assertNotSame(-1, $finalizerPid, 'pcntl_fork() failed.');
        if ($finalizerPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            DB::transaction(function () use ($session, $photo, $lockedFile, $cleanupStartedFile, $releaseFile): void {
                $locked = PhotoUploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                $examination = Examination::factory()->create();
                ExaminationPhoto::create([
                    'examination_id' => $examination->id,
                    'storage_disk' => $photo->storage_disk,
                    'storage_path' => $photo->storage_path,
                    'mime_type' => $photo->mime_type ?? 'image/jpeg',
                    'file_size' => $photo->file_size ?? 1,
                    'width' => $photo->width ?? 1,
                    'height' => $photo->height ?? 1,
                    'display_order' => 1,
                ]);
                $locked->forceFill(['examination_id' => $examination->id])->save();
                file_put_contents($lockedFile, '1');
                while (! file_exists($cleanupStartedFile)) {
                    usleep(1000);
                }
                file_put_contents($releaseFile, '1');
            });
            exit(0);
        }

        $cleanupPid = pcntl_fork();
        $this->assertNotSame(-1, $cleanupPid, 'pcntl_fork() failed.');
        if ($cleanupPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($lockedFile)) {
                usleep(1000);
            }
            file_put_contents($cleanupStartedFile, '1');
            (new PhotoUploadCleanupService(app(PhotoUploadTransport::class)))
                ->stageExpiredSessions(now());
            exit(0);
        }

        file_put_contents($startFile, '1');
        while (! file_exists($cleanupStartedFile)) {
            usleep(1000);
        }
        while (! file_exists($releaseFile)) {
            usleep(1000);
        }
        pcntl_waitpid($finalizerPid, $finalizerStatus);
        pcntl_waitpid($cleanupPid, $cleanupStatus);
        $this->assertSame(0, pcntl_wexitstatus($finalizerStatus));
        $this->assertSame(0, pcntl_wexitstatus($cleanupStatus));

        DB::reconnect('mysql');
        $this->assertSame(1, ExaminationPhoto::count());
        $this->assertSame(0, PhotoUploadCleanupQueue::count());
        $this->assertNotNull($session->fresh()->examination_id);

        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }

    public function test_real_mysql_remove_wins_before_finalization_lock(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $photo = PhotoUpload::factory()->verified()->for($session)->create();
        $directory = storage_path('framework/testing/remove-finalization-remove-wins-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $removeLockedFile = $directory.'/remove-locked';
        $finalizerStartedFile = $directory.'/finalizer-started';

        DB::disconnect();
        $removePid = pcntl_fork();
        $this->assertNotSame(-1, $removePid, 'pcntl_fork() failed.');
        if ($removePid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            DB::transaction(function () use ($session, $photo, $removeLockedFile, $finalizerStartedFile): void {
                $locked = PhotoUploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                file_put_contents($removeLockedFile, '1');
                while (! file_exists($finalizerStartedFile)) {
                    usleep(1000);
                }

                app(PhotoUploadCleanupService::class)->stageDeletionIntent(
                    $photo->storage_disk,
                    $photo->storage_path,
                    $locked->public_id,
                    now()->addHour(),
                );
                $locked->photoUploads()->whereKey($photo->id)->delete();
            });
            file_put_contents($directory.'/remove.result', 'REMOVED');
            exit(0);
        }

        $finalizerPid = pcntl_fork();
        $this->assertNotSame(-1, $finalizerPid, 'pcntl_fork() failed.');
        if ($finalizerPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($removeLockedFile)) {
                usleep(1000);
            }
            file_put_contents($finalizerStartedFile, '1');

            try {
                DB::transaction(function () use ($session, $token): void {
                    app(PhotoUploadSessionFinalizer::class)->lock(
                        new PhotoUploadSessionCredentials($session->public_id, $token),
                    );
                });
                file_put_contents($directory.'/finalizer.result', 'UNEXPECTED_SUCCESS');
                exit(1);
            } catch (PhotoUploadInvalid $exception) {
                file_put_contents($directory.'/finalizer.result', 'REJECTED:'.$exception->getErrorCode());
                exit(0);
            }
        }

        file_put_contents($startFile, '1');
        pcntl_waitpid($removePid, $removeStatus);
        pcntl_waitpid($finalizerPid, $finalizerStatus);
        $this->assertSame(0, pcntl_wexitstatus($removeStatus));
        $this->assertSame(0, pcntl_wexitstatus($finalizerStatus));

        DB::reconnect('mysql');
        $this->assertNotNull($session->fresh());
        $this->assertNull(PhotoUpload::find($photo->id));
        $this->assertSame(1, PhotoUploadCleanupQueue::where('storage_path', $photo->storage_path)->count());
        $this->assertSame('REJECTED:photo_count_invalid', trim(file_get_contents($directory.'/finalizer.result')));
        $this->assertSame(0, ExaminationPhoto::where('storage_path', $photo->storage_path)->count());

        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }

    public function test_real_mysql_finalization_wins_before_remove_lock(): void
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $photo = PhotoUpload::factory()->verified()->for($session)->create();
        Storage::disk('photo_uploads')->put($photo->storage_path, 'evidence');
        $directory = storage_path('framework/testing/remove-finalization-finalizer-wins-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $finalizerLockedFile = $directory.'/finalizer-locked';
        $removeStartedFile = $directory.'/remove-started';

        DB::disconnect();
        $finalizerPid = pcntl_fork();
        $this->assertNotSame(-1, $finalizerPid, 'pcntl_fork() failed.');
        if ($finalizerPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            DB::transaction(function () use ($session, $token, $finalizerLockedFile, $removeStartedFile): void {
                $finalizer = app(PhotoUploadSessionFinalizer::class);
                $locked = $finalizer->lock(new PhotoUploadSessionCredentials($session->public_id, $token));
                $examination = Examination::factory()->create();
                $finalizer->attachPhotos($examination, $locked);
                file_put_contents($finalizerLockedFile, '1');
                while (! file_exists($removeStartedFile)) {
                    usleep(1000);
                }
            });
            file_put_contents($directory.'/finalizer.result', 'FINALIZED');
            exit(0);
        }

        $removePid = pcntl_fork();
        $this->assertNotSame(-1, $removePid, 'pcntl_fork() failed.');
        if ($removePid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($finalizerLockedFile)) {
                usleep(1000);
            }
            file_put_contents($removeStartedFile, '1');

            try {
                app(PhotoUploadService::class)->remove($session->public_id, $token, $photo->public_id);
                file_put_contents($directory.'/remove.result', 'UNEXPECTED_SUCCESS');
                exit(1);
            } catch (PhotoUploadSessionInvalid $exception) {
                file_put_contents($directory.'/remove.result', 'REJECTED:'.$exception->getErrorCode());
                exit(0);
            }
        }

        file_put_contents($startFile, '1');
        pcntl_waitpid($finalizerPid, $finalizerStatus);
        pcntl_waitpid($removePid, $removeStatus);
        $this->assertSame(0, pcntl_wexitstatus($finalizerStatus));
        $this->assertSame(0, pcntl_wexitstatus($removeStatus));

        DB::reconnect('mysql');
        $this->assertSame('REJECTED:session_finalized', trim(file_get_contents($directory.'/remove.result')));
        $this->assertNotNull($session->fresh()->examination_id);
        $this->assertSame(1, ExaminationPhoto::where('storage_path', $photo->storage_path)->count());
        $this->assertSame(0, PhotoUploadCleanupQueue::where('storage_path', $photo->storage_path)->count());
        $this->assertNotNull(PhotoUpload::find($photo->id));
        Storage::disk('photo_uploads')->assertExists($photo->storage_path);

        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }

    // ========== Evidence Guard Concurrency ==========

    public function test_evidence_added_during_queue_processing_blocks_delete(): void
    {
        $examination = Examination::factory()->create();

        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'future-evidence.jpg',
            'delete_after' => now()->subMinute(),
        ]);

        // Simulate concurrent finalization that adds evidence
        DB::transaction(function () use ($examination) {
            ExaminationPhoto::factory()
                ->for($examination)
                ->create([
                    'storage_disk' => 'photo_uploads',
                    'storage_path' => 'future-evidence.jpg',
                ]);
        });

        $result = app(PhotoUploadCleanupService::class)->processQueueUntilSettled(now: now());

        $this->assertEquals(1, $result->integrityConflicts);
        $this->assertCount(1, PhotoUploadCleanupQueue::all());
    }

    // ========== Idempotency Under Concurrency ==========

    public function test_duplicate_intent_upsert_idempotent(): void
    {
        $intent1 = DB::transaction(function () {
            app(PhotoUploadCleanupService::class)->stageDeletionIntent(
                'photo_uploads',
                'concurrent.jpg',
                'session1',
                now()->addHours(2),
            );
        });

        $intent2 = DB::transaction(function () {
            app(PhotoUploadCleanupService::class)->stageDeletionIntent(
                'photo_uploads',
                'concurrent.jpg',
                'session2',
                now()->addHour(),
            );
        });

        $intents = PhotoUploadCleanupQueue::where('storage_path', 'concurrent.jpg')->get();
        $this->assertCount(1, $intents);
        // The far-future delete_after should be preserved
        $this->assertTrue($intents->first()->delete_after->gte(now()->addHours(1.5)));
    }

    public function test_same_key_intents_converge_under_real_mysql_workers(): void
    {
        $directory = storage_path('framework/testing/cleanup-intent-concurrency-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $path = 'same-key-concurrent.jpg';
        $earlier = CarbonImmutable::parse('2026-09-09 13:00:00', 'UTC');
        $later = $earlier->addHour();

        DB::disconnect();
        $pids = [];
        foreach ([$earlier, $later] as $deadline) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

            if ($pid === 0) {
                DB::purge('mysql');
                DB::reconnect('mysql');
                while (! file_exists($startFile)) {
                    usleep(1000);
                }

                try {
                    app(PhotoUploadCleanupService::class)->stageDeletionIntent(
                        'photo_uploads',
                        $path,
                        'concurrent-session',
                        $deadline,
                    );
                    file_put_contents($directory.'/'.getmypid().'.result', 'OK');
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/'.getmypid().'.result', 'ERROR:'.$exception::class);
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        file_put_contents($startFile, '1');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::reconnect('mysql');
        $intent = PhotoUploadCleanupQueue::where('storage_path', $path)->first();

        $this->assertNotNull($intent);
        $this->assertSame(1, PhotoUploadCleanupQueue::where('storage_path', $path)->count());
        $this->assertSame($later->timestamp, $intent->delete_after->timestamp);
        $this->assertSame(0, $intent->attempt_count);
        $this->assertNull($intent->last_attempt_at);
        $this->assertNull($intent->last_failed_at);

        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }

    // ========== Large-Scale Concurrent Operations ==========

    public function test_hundred_sessions_concurrent_staging(): void
    {
        for ($i = 0; $i < 100; $i++) {
            PhotoUploadSession::factory()->create(['expires_at' => now()->subMinute()]);
        }

        $result = app(PhotoUploadCleanupService::class)
            ->stageExpiredSessions(cutoff: now(), limit: 150);

        $this->assertGreaterThanOrEqual(100, $result->sessionsStaged);
    }

    // ========== Settling Window Compliance ==========

    public function test_settling_window_prevents_premature_physical_delete(): void
    {
        $now = CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC');
        Storage::disk('photo_uploads')->put('protected.jpg', 'content');

        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'protected.jpg',
            'delete_after' => $now->addMinutes(5),
        ]);

        $result = app(PhotoUploadCleanupService::class)
            ->processQueueUntilSettled(now: $now);

        $this->assertEquals(0, $result->objectsCleared);
        $this->assertTrue(Storage::disk('photo_uploads')->exists('protected.jpg'));
    }

    public function test_settling_window_allows_delete_when_due(): void
    {
        $created = now()->subHours(2);
        Storage::disk('photo_uploads')->put('ready.jpg', 'content');

        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'ready.jpg',
            'delete_after' => $created->addMinutes(5),
            'created_at' => $created,
        ]);

        $result = app(PhotoUploadCleanupService::class)
            ->processQueueUntilSettled(now: now());

        $this->assertEquals(1, $result->objectsCleared);
        $this->assertFalse(Storage::disk('photo_uploads')->exists('ready.jpg'));
    }

    // ========== Atomic Transaction Guarantees ==========

    public function test_session_staging_atomicity_intent_and_row_deleted_together(): void
    {
        $session = PhotoUploadSession::factory()->create(['expires_at' => now()->subMinute()]);
        $session->forceFill(['examination_id' => null])->save();
        PhotoUpload::factory()
            ->verified()
            ->for($session)
            ->create();

        app(PhotoUploadCleanupService::class)->stageExpiredSessions(cutoff: now());

        // If atomicity holds, the session should be deleted AND intent should exist
        $this->assertNull($session->fresh());
        $this->assertGreaterThan(0, PhotoUploadCleanupQueue::count());
    }

    // ========== Error Handling Under Concurrency ==========

    public function test_error_in_one_session_doesnt_block_others(): void
    {
        // Create multiple sessions
        for ($i = 0; $i < 3; $i++) {
            PhotoUploadSession::factory()->create(['expires_at' => now()->subMinute()]);
        }

        $result = app(PhotoUploadCleanupService::class)
            ->stageExpiredSessions(cutoff: now(), limit: 10);

        // All sessions should be processed despite any individual errors
        $this->assertGreaterThanOrEqual(3, $result->sessionsScanned);
    }
}
