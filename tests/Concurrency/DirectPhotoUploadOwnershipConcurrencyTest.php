<?php

namespace Tests\Concurrency;

use App\Exceptions\PhotoUploadInvalid;
use App\Exceptions\PhotoUploadSessionInvalid;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadCleanupQueue;
use App\Models\PhotoUploadSession;
use App\Services\PhotoUploadCleanupService;
use App\Services\PhotoUploadObjectPath;
use App\Services\PhotoUploadService;
use App\Services\PhotoUploadSessionResolver;
use App\Services\PhotoUploadTransport;
use App\Services\SpacesObjectClient;
use App\Services\SpacesPhotoUploadSealer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Step 3B.6B (D026) real-MySQL ownership-handoff proofs: whichever side locks
 * the candidate's `photo_upload_cleanup_queue` row first — a direct-completion
 * claim or the cleanup processor — wins, and the loser observes the winner's
 * already-committed outcome. Run only via phpunit.concurrency.xml against
 * `zb_examine_test`, never SQLite, never a faked lock.
 */
class DirectPhotoUploadOwnershipConcurrencyTest extends TestCase
{
    private string $fakeJpegBytes;

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
        Storage::fake('photo_uploads_spaces');

        // Built directly via GD rather than UploadedFile::fake()->image(): the
        // latter backs its file with tmpfile(), which Linux unlinks from the
        // directory immediately (the fd stays valid, but a fresh path-based
        // read like file_get_contents(getRealPath()) fails inside Docker).
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagejpeg($image);
        $this->fakeJpegBytes = ob_get_clean();
        imagedestroy($image);
    }

    // ========== A. Claim wins before cleanup start ==========

    public function test_real_mysql_direct_claim_wins_before_cleanup_start(): void
    {
        [$session, , $upload] = $this->createDirectPendingUpload();
        $disk = $upload->storage_disk;
        $candidatePath = PhotoUploadObjectPath::sealed($session->public_id, $upload->public_id);

        app(PhotoUploadCleanupService::class)->stageDeletionIntent($disk, $candidatePath, $session->public_id, now()->subMinute());

        $directory = storage_path('framework/testing/direct-claim-wins-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $claimLockedFile = $directory.'/claim-locked';
        $cleanupStartedFile = $directory.'/cleanup-started';

        DB::disconnect();
        $claimPid = pcntl_fork();
        $this->assertNotSame(-1, $claimPid, 'pcntl_fork() failed.');
        if ($claimPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            // Mirrors PhotoUploadService::completeDirect()'s claim transaction exactly.
            DB::transaction(function () use ($session, $upload, $disk, $candidatePath, $claimLockedFile, $cleanupStartedFile): void {
                $locked = PhotoUploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                $freshUpload = $locked->photoUploads()->whereKey($upload->id)->firstOrFail();
                $candidate = PhotoUploadCleanupQueue::query()
                    ->where('storage_disk', $disk)
                    ->where('storage_path', $candidatePath)
                    ->lockForUpdate()
                    ->firstOrFail();

                file_put_contents($claimLockedFile, '1');
                while (! file_exists($cleanupStartedFile)) {
                    usleep(1000);
                }

                $freshUpload->storage_path = $candidatePath;
                $freshUpload->verified_at = now();
                $freshUpload->save();
                $candidate->delete();
            });
            exit(0);
        }

        $cleanupPid = pcntl_fork();
        $this->assertNotSame(-1, $cleanupPid, 'pcntl_fork() failed.');
        if ($cleanupPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($claimLockedFile)) {
                usleep(1000);
            }
            file_put_contents($cleanupStartedFile, '1');

            try {
                (new PhotoUploadCleanupService($this->noopTransport()))->processQueueUntilSettled(now(), 10);
                exit(0);
            } catch (Throwable $exception) {
                exit(1);
            }
        }

        file_put_contents($startFile, '1');
        pcntl_waitpid($claimPid, $claimStatus);
        pcntl_waitpid($cleanupPid, $cleanupStatus);
        $this->assertSame(0, pcntl_wexitstatus($claimStatus));
        $this->assertSame(0, pcntl_wexitstatus($cleanupStatus));

        DB::reconnect('mysql');
        $winner = PhotoUpload::find($upload->id);
        $this->assertSame($candidatePath, $winner->storage_path);
        $this->assertNotNull($winner->verified_at);
        $this->assertSame(0, PhotoUploadCleanupQueue::where('storage_path', $candidatePath)->count());

        $this->cleanupDirectory($directory);
    }

    // ========== B. Cleanup start wins before claim ==========

    public function test_real_mysql_cleanup_start_wins_before_direct_claim(): void
    {
        [$session, , $upload] = $this->createDirectPendingUpload();
        $disk = $upload->storage_disk;
        $candidatePath = PhotoUploadObjectPath::sealed($session->public_id, $upload->public_id);

        app(PhotoUploadCleanupService::class)->stageDeletionIntent($disk, $candidatePath, $session->public_id, now()->subMinute());

        $directory = storage_path('framework/testing/cleanup-wins-before-claim-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $markedFile = $directory.'/marked';
        $releaseFile = $directory.'/release';
        $claimResultFile = $directory.'/claim-result';

        DB::disconnect();
        $cleanupPid = pcntl_fork();
        $this->assertNotSame(-1, $cleanupPid, 'pcntl_fork() failed.');
        if ($cleanupPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            $transport = new class($markedFile, $releaseFile) implements PhotoUploadTransport
            {
                public function __construct(private readonly string $markedFile, private readonly string $releaseFile) {}

                public function store(PhotoUpload $upload, UploadedFile $file): void {}

                public function verify(PhotoUpload $upload): array
                {
                    return [];
                }

                public function delete(PhotoUpload $upload): void {}

                public function deleteByPath(string $storageDisk, string $storagePath): void
                {
                    // Marker is already committed by now; pause here so the claimant
                    // observes the started-but-not-yet-physically-deleted window.
                    file_put_contents($this->markedFile, '1');
                    while (! file_exists($this->releaseFile)) {
                        usleep(1000);
                    }
                }
            };

            (new PhotoUploadCleanupService($transport))->processQueueUntilSettled(now(), 10);
            exit(0);
        }

        $claimPid = pcntl_fork();
        $this->assertNotSame(-1, $claimPid, 'pcntl_fork() failed.');
        if ($claimPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($markedFile)) {
                usleep(1000);
            }

            // Mirrors PhotoUploadService::completeDirect()'s claim transaction exactly.
            try {
                DB::transaction(function () use ($session, $upload, $disk, $candidatePath): void {
                    $locked = PhotoUploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                    $freshUpload = $locked->photoUploads()->whereKey($upload->id)->firstOrFail();
                    $candidate = PhotoUploadCleanupQueue::query()
                        ->where('storage_disk', $disk)
                        ->where('storage_path', $candidatePath)
                        ->lockForUpdate()
                        ->first();

                    if (! $candidate || $candidate->deletion_started_at !== null) {
                        throw new PhotoUploadInvalid('photo_state_conflict');
                    }

                    $freshUpload->storage_path = $candidatePath;
                    $freshUpload->verified_at = now();
                    $freshUpload->save();
                    $candidate->delete();
                });
                file_put_contents($claimResultFile, 'UNEXPECTED_SUCCESS');
            } catch (PhotoUploadInvalid $exception) {
                file_put_contents($claimResultFile, 'REJECTED:'.$exception->getErrorCode());
            }
            exit(0);
        }

        file_put_contents($startFile, '1');
        while (! file_exists($claimResultFile)) {
            usleep(1000);
        }
        file_put_contents($releaseFile, '1');

        pcntl_waitpid($cleanupPid, $cleanupStatus);
        pcntl_waitpid($claimPid, $claimStatus);
        $this->assertSame(0, pcntl_wexitstatus($cleanupStatus));
        $this->assertSame(0, pcntl_wexitstatus($claimStatus));

        DB::reconnect('mysql');
        $this->assertSame('REJECTED:photo_state_conflict', trim(file_get_contents($claimResultFile)));
        $refreshed = PhotoUpload::find($upload->id);
        $this->assertNull($refreshed->verified_at);
        $this->assertNotSame($candidatePath, $refreshed->storage_path);
        $this->assertSame(0, PhotoUploadCleanupQueue::where('storage_path', $candidatePath)->count());

        $this->cleanupDirectory($directory);
    }

    // ========== C. Cleanup start then process crash before storage delete ==========

    public function test_cleanup_retries_an_already_started_row_after_a_simulated_crash(): void
    {
        $startedAt = now()->subMinutes(5)->startOfSecond();
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'crash-recovery.jpg',
            'delete_after' => now()->subMinute(),
            // Simulates a prior worker that committed the marker then died before
            // ever calling the transport's deleteByPath().
            'deletion_started_at' => $startedAt,
        ]);
        Storage::disk('photo_uploads')->put('crash-recovery.jpg', 'content');

        $capturingTransport = $this->capturingTransport();

        $result = (new PhotoUploadCleanupService($capturingTransport))->processQueueUntilSettled(now: now());

        $this->assertSame(1, $result->objectsCleared);
        $this->assertSame(0, PhotoUploadCleanupQueue::where('storage_path', 'crash-recovery.jpg')->count());
        $this->assertFalse(Storage::disk('photo_uploads')->exists('crash-recovery.jpg'));
        $this->assertNotNull($capturedTimestamp = $capturingTransport->capturedMarker);
        $this->assertSame($startedAt->timestamp, $capturedTimestamp->timestamp);
    }

    // ========== D. Cleanup start + storage delete failure, then successful retry ==========

    public function test_cleanup_start_marker_survives_a_storage_delete_failure_then_retries_successfully(): void
    {
        PhotoUploadCleanupQueue::create([
            'storage_disk' => 'photo_uploads',
            'storage_path' => 'flaky-delete.jpg',
            'delete_after' => now()->subMinute(),
        ]);
        Storage::disk('photo_uploads')->put('flaky-delete.jpg', 'content');

        $failingTransport = new class implements PhotoUploadTransport
        {
            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return [];
            }

            public function delete(PhotoUpload $upload): void {}

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                throw new \RuntimeException('simulated transient storage failure');
            }
        };

        $firstRun = (new PhotoUploadCleanupService($failingTransport))->processQueueUntilSettled(now: now());

        $this->assertSame(1, $firstRun->clearingFailures);
        $row = PhotoUploadCleanupQueue::where('storage_path', 'flaky-delete.jpg')->firstOrFail();
        $this->assertNotNull($row->deletion_started_at);
        $this->assertSame(1, $row->attempt_count);
        $markerAfterFirstRun = $row->deletion_started_at;

        $capturingTransport = $this->capturingTransport();
        $secondRun = (new PhotoUploadCleanupService($capturingTransport))->processQueueUntilSettled(now: now());

        $this->assertSame(1, $secondRun->objectsCleared);
        $this->assertSame(0, PhotoUploadCleanupQueue::where('storage_path', 'flaky-delete.jpg')->count());
        $this->assertNotNull($capturedTimestamp = $capturingTransport->capturedMarker);
        // The marker committed by the failed first run was never reset by the retry.
        $this->assertSame($markerAfterFirstRun->timestamp, $capturedTimestamp->timestamp);
    }

    // ========== E. Two direct completions racing on the same photo ==========

    public function test_real_mysql_two_direct_completions_exactly_one_wins_ownership(): void
    {
        [$session, $token, $upload] = $this->createDirectPendingUpload();

        $directory = storage_path('framework/testing/two-direct-completions-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';

        DB::disconnect();
        $pids = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

            if ($pid === 0) {
                DB::purge('mysql');
                DB::reconnect('mysql');
                while (! file_exists($startFile)) {
                    usleep(1000);
                }

                try {
                    $result = $this->fakeDirectPhotoUploadService()->complete($session->public_id, $token, $upload->public_id);
                    file_put_contents($directory.'/'.getmypid().'.result', 'OK:'.$result->storage_path);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/'.getmypid().'.result', 'ERROR:'.$exception::class);
                }
                exit(0);
            }

            $pids[] = $pid;
        }

        file_put_contents($startFile, '1');
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::reconnect('mysql');
        $winner = PhotoUpload::find($upload->id);
        $this->assertNotNull($winner->verified_at);
        $this->assertTrue(PhotoUploadObjectPath::isSealed($winner->storage_path));

        // Exactly one sealed candidate became authoritative; the loser's own
        // candidate remains a cleanup-owned, unstarted intent.
        $remainingIntents = PhotoUploadCleanupQueue::where('storage_disk', $winner->storage_disk)->get();
        $this->assertCount(1, $remainingIntents);
        $this->assertNotSame($winner->storage_path, $remainingIntents->first()->storage_path);
        $this->assertNull($remainingIntents->first()->deletion_started_at);

        $this->cleanupDirectory($directory);
    }

    // ========== F. Remove wins before direct claim ==========

    public function test_real_mysql_remove_wins_before_direct_claim(): void
    {
        [$session, $token, $upload] = $this->createDirectPendingUpload();
        $stagingPath = $upload->storage_path;

        $directory = storage_path('framework/testing/remove-wins-before-claim-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $removeLockedFile = $directory.'/remove-locked';
        $claimStartedFile = $directory.'/claim-started';

        DB::disconnect();
        $removePid = pcntl_fork();
        $this->assertNotSame(-1, $removePid, 'pcntl_fork() failed.');
        if ($removePid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            DB::transaction(function () use ($session, $upload, $removeLockedFile, $claimStartedFile): void {
                $locked = PhotoUploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                file_put_contents($removeLockedFile, '1');
                while (! file_exists($claimStartedFile)) {
                    usleep(1000);
                }

                app(PhotoUploadCleanupService::class)->stageDeletionIntent(
                    $upload->storage_disk,
                    $upload->storage_path,
                    $locked->public_id,
                    now()->addHour(),
                );
                $locked->photoUploads()->whereKey($upload->id)->delete();
            });
            exit(0);
        }

        $claimPid = pcntl_fork();
        $this->assertNotSame(-1, $claimPid, 'pcntl_fork() failed.');
        if ($claimPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($removeLockedFile)) {
                usleep(1000);
            }
            file_put_contents($claimStartedFile, '1');

            try {
                $this->fakeDirectPhotoUploadService()->complete($session->public_id, $token, $upload->public_id);
                file_put_contents($directory.'/claim.result', 'UNEXPECTED_SUCCESS');
            } catch (PhotoUploadInvalid $exception) {
                file_put_contents($directory.'/claim.result', 'REJECTED:'.$exception->getErrorCode());
            }
            exit(0);
        }

        file_put_contents($startFile, '1');
        pcntl_waitpid($removePid, $removeStatus);
        pcntl_waitpid($claimPid, $claimStatus);
        $this->assertSame(0, pcntl_wexitstatus($removeStatus));
        $this->assertSame(0, pcntl_wexitstatus($claimStatus));

        DB::reconnect('mysql');
        $this->assertSame('REJECTED:photo_not_found', trim(file_get_contents($directory.'/claim.result')));
        $this->assertNull(PhotoUpload::find($upload->id));
        $this->assertSame(1, PhotoUploadCleanupQueue::where('storage_path', $stagingPath)->count());

        $this->cleanupDirectory($directory);
    }

    // ========== G. Direct claim wins before remove ==========

    public function test_real_mysql_direct_claim_wins_before_remove(): void
    {
        [$session, $token, $upload] = $this->createDirectPendingUpload();
        $stagingPath = $upload->storage_path;

        $directory = storage_path('framework/testing/claim-wins-before-remove-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $claimedFile = $directory.'/claimed';

        DB::disconnect();
        $claimPid = pcntl_fork();
        $this->assertNotSame(-1, $claimPid, 'pcntl_fork() failed.');
        if ($claimPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            $this->fakeDirectPhotoUploadService()->complete($session->public_id, $token, $upload->public_id);
            file_put_contents($claimedFile, '1');
            exit(0);
        }

        $removePid = pcntl_fork();
        $this->assertNotSame(-1, $removePid, 'pcntl_fork() failed.');
        if ($removePid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($claimedFile)) {
                usleep(1000);
            }

            app(PhotoUploadService::class)->remove($session->public_id, $token, $upload->public_id);
            exit(0);
        }

        file_put_contents($startFile, '1');
        pcntl_waitpid($claimPid, $claimStatus);
        pcntl_waitpid($removePid, $removeStatus);
        $this->assertSame(0, pcntl_wexitstatus($claimStatus));
        $this->assertSame(0, pcntl_wexitstatus($removeStatus));

        DB::reconnect('mysql');
        $this->assertNull(PhotoUpload::find($upload->id));
        $intents = PhotoUploadCleanupQueue::all();
        $this->assertCount(1, $intents);
        $this->assertTrue(PhotoUploadObjectPath::isSealed($intents->first()->storage_path));
        $this->assertNotSame($stagingPath, $intents->first()->storage_path);
        $this->assertNull($intents->first()->deletion_started_at);

        $this->cleanupDirectory($directory);
    }

    // ========== H. Expired session cleanup wins before claim ==========

    public function test_real_mysql_expired_session_cleanup_wins_before_direct_claim(): void
    {
        [$session, $token, $upload] = $this->createDirectPendingUpload();
        $session->forceFill(['expires_at' => now()->subMinute()])->save();
        $stagingPath = $upload->storage_path;

        $directory = storage_path('framework/testing/expired-cleanup-wins-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $cleanupLockedFile = $directory.'/cleanup-locked';
        $claimStartedFile = $directory.'/claim-started';

        DB::disconnect();
        $cleanupPid = pcntl_fork();
        $this->assertNotSame(-1, $cleanupPid, 'pcntl_fork() failed.');
        if ($cleanupPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            DB::transaction(function () use ($session, $cleanupLockedFile, $claimStartedFile): void {
                $locked = PhotoUploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                file_put_contents($cleanupLockedFile, '1');
                while (! file_exists($claimStartedFile)) {
                    usleep(1000);
                }

                foreach ($locked->photoUploads()->get() as $photo) {
                    app(PhotoUploadCleanupService::class)->stageDeletionIntent(
                        $photo->storage_disk,
                        $photo->storage_path,
                        $locked->public_id,
                        now()->addHour(),
                    );
                }
                $locked->delete();
            });
            exit(0);
        }

        $claimPid = pcntl_fork();
        $this->assertNotSame(-1, $claimPid, 'pcntl_fork() failed.');
        if ($claimPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($cleanupLockedFile)) {
                usleep(1000);
            }
            file_put_contents($claimStartedFile, '1');

            try {
                $this->fakeDirectPhotoUploadService()->complete($session->public_id, $token, $upload->public_id);
                file_put_contents($directory.'/claim.result', 'UNEXPECTED_SUCCESS');
            } catch (PhotoUploadSessionInvalid $exception) {
                file_put_contents($directory.'/claim.result', 'REJECTED:'.$exception->getErrorCode());
            } catch (PhotoUploadInvalid $exception) {
                file_put_contents($directory.'/claim.result', 'REJECTED:'.$exception->getErrorCode());
            }
            exit(0);
        }

        file_put_contents($startFile, '1');
        pcntl_waitpid($cleanupPid, $cleanupStatus);
        pcntl_waitpid($claimPid, $claimStatus);
        $this->assertSame(0, pcntl_wexitstatus($cleanupStatus));
        $this->assertSame(0, pcntl_wexitstatus($claimStatus));

        DB::reconnect('mysql');
        $this->assertNull(PhotoUploadSession::find($session->id));
        $this->assertNull(PhotoUpload::find($upload->id));
        $this->assertSame(1, PhotoUploadCleanupQueue::where('storage_path', $stagingPath)->count());
        $this->assertSame('REJECTED:session_expired', trim(file_get_contents($directory.'/claim.result')));

        $this->cleanupDirectory($directory);
    }

    // ========== I. Direct claim wins while session valid before expiry cleanup ==========

    public function test_real_mysql_direct_claim_wins_before_expiry_cleanup(): void
    {
        [$session, $token, $upload] = $this->createDirectPendingUpload();

        $directory = storage_path('framework/testing/claim-before-expiry-cleanup-'.getmypid());
        @mkdir($directory, 0775, true);
        $startFile = $directory.'/start';
        $claimedFile = $directory.'/claimed';

        DB::disconnect();
        $claimPid = pcntl_fork();
        $this->assertNotSame(-1, $claimPid, 'pcntl_fork() failed.');
        if ($claimPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($startFile)) {
                usleep(1000);
            }

            $this->fakeDirectPhotoUploadService()->complete($session->public_id, $token, $upload->public_id);
            file_put_contents($claimedFile, '1');
            exit(0);
        }

        $cleanupPid = pcntl_fork();
        $this->assertNotSame(-1, $cleanupPid, 'pcntl_fork() failed.');
        if ($cleanupPid === 0) {
            DB::purge('mysql');
            DB::reconnect('mysql');
            while (! file_exists($claimedFile)) {
                usleep(1000);
            }

            // Simulates wall-clock time passing after the claim already committed.
            PhotoUploadSession::query()->whereKey($session->id)->update(['expires_at' => now()->subMinute()]);
            app(PhotoUploadCleanupService::class)->stageExpiredSessions(cutoff: now());
            exit(0);
        }

        file_put_contents($startFile, '1');
        pcntl_waitpid($claimPid, $claimStatus);
        pcntl_waitpid($cleanupPid, $cleanupStatus);
        $this->assertSame(0, pcntl_wexitstatus($claimStatus));
        $this->assertSame(0, pcntl_wexitstatus($cleanupStatus));

        DB::reconnect('mysql');
        // This unfinalized session is a legitimate later-expiry cleanup candidate
        // regardless of the earlier claim (examination_id, not verified_at, gates
        // eligibility) — the point being proven is that cleanup's fresh read sees
        // the winner's already-committed sealed path, not a stale staging path,
        // and stages a durable intent rather than losing the object outright.
        $this->assertNull(PhotoUploadSession::find($session->id));
        $this->assertNull(PhotoUpload::find($upload->id));
        $intents = PhotoUploadCleanupQueue::all();
        $this->assertCount(1, $intents);
        $this->assertTrue(PhotoUploadObjectPath::isSealed($intents->first()->storage_path));
        $this->assertNotSame($upload->storage_path, $intents->first()->storage_path);

        $this->cleanupDirectory($directory);
    }

    /**
     * @return array{0: PhotoUploadSession, 1: string, 2: PhotoUpload}
     */
    private function createDirectPendingUpload(): array
    {
        ['session' => $session, 'token' => $token] = PhotoUploadSession::issue();
        $disk = config('zb-examine.photo_upload_direct_disk', 'photo_uploads_spaces');
        $stagingPath = PhotoUploadObjectPath::staging($session->public_id, (string) Str::ulid());

        $upload = PhotoUpload::factory()->pending()->for($session)->create([
            'storage_disk' => $disk,
            'storage_path' => $stagingPath,
        ]);

        return [$session, $token, $upload];
    }

    /** A PhotoUploadService wired with a real sealer against a fake Spaces client — no network calls. */
    private function fakeDirectPhotoUploadService(): PhotoUploadService
    {
        $bytes = $this->fakeJpegBytes;
        $client = new class($bytes) implements SpacesObjectClient
        {
            public function __construct(private readonly string $bytes) {}

            public function head(string $storagePath): array
            {
                return ['size' => strlen($this->bytes), 'etag' => '"fixed-etag"'];
            }

            public function getToFile(string $storagePath, string $etag, string $destinationPath): array
            {
                file_put_contents($destinationPath, $this->bytes);

                return ['size' => strlen($this->bytes), 'etag' => '"fixed-etag"'];
            }

            public function putFile(string $storagePath, string $sourcePath, int $fileSize, string $mimeType): void {}

            public function deleteByPath(string $storagePath): void {}
        };

        return new PhotoUploadService(
            app(PhotoUploadSessionResolver::class),
            app(PhotoUploadTransport::class),
            new SpacesPhotoUploadSealer($client),
        );
    }

    private function noopTransport(): PhotoUploadTransport
    {
        return new class implements PhotoUploadTransport
        {
            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return [];
            }

            public function delete(PhotoUpload $upload): void {}

            public function deleteByPath(string $storageDisk, string $storagePath): void {}
        };
    }

    /** Captures the queue row's deletion_started_at exactly as the transport observes it. */
    private function capturingTransport(): PhotoUploadTransport
    {
        return new class implements PhotoUploadTransport
        {
            public $capturedMarker = null;

            public function store(PhotoUpload $upload, UploadedFile $file): void {}

            public function verify(PhotoUpload $upload): array
            {
                return [];
            }

            public function delete(PhotoUpload $upload): void {}

            public function deleteByPath(string $storageDisk, string $storagePath): void
            {
                $this->capturedMarker = PhotoUploadCleanupQueue::where('storage_disk', $storageDisk)
                    ->where('storage_path', $storagePath)
                    ->first()?->deletion_started_at;

                Storage::disk($storageDisk)->delete($storagePath);
            }
        };
    }

    private function cleanupDirectory(string $directory): void
    {
        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }
}
