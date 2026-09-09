<?php

namespace App\Services;

use App\Data\PhotoUploadCleanupResult;
use App\Models\ExaminationPhoto;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadCleanupQueue;
use App\Models\PhotoUploadSession;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Durable deletion-intent system for photo-upload storage objects.
 *
 * Stages deletion intents when DB ownership is removed (both explicit remove
 * and expired-session cleanup), then processes intents after settling window.
 *
 * No storage calls occur under DB locks; all storage deletion is deferred and
 * idempotent. Late-publication race is prevented by settling window.
 */
final class PhotoUploadCleanupService
{
    public function __construct(
        private readonly PhotoUploadTransport $transport,
    ) {}

    /**
     * Stage expired unfinalized sessions for cleanup.
     *
     * Identifies candidates where examination_id IS NULL and expires_at <= cutoff.
     * For each candidate independently:
     *   - locks parent session
     *   - verifies still unfinalized and expired
     *   - stages durable deletion intents for each child PhotoUpload
     *   - deletes session (cascading child rows)
     *   - commits
     *
     * No storage/network calls. Atomicity: intent staging + row deletion in same
     * transaction, so if either fails, neither persists.
     *
     * @param  int  $limit  Maximum sessions to stage per run
     */
    public function stageExpiredSessions(?CarbonInterface $cutoff = null, ?int $limit = null): PhotoUploadCleanupResult
    {
        $cutoff ??= now();
        $limit ??= (int) config('zb-examine.photo_cleanup_session_limit', 100);

        $sessionsScanned = 0;
        $sessionsStaged = 0;
        $photoRowsStaged = 0;
        $failures = [];

        // Unlocked advisory selection: candidate IDs only
        $candidateIds = PhotoUploadSession::query()
            ->where('examination_id', null)
            ->where('expires_at', '<=', $cutoff)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        foreach ($candidateIds as $sessionId) {
            $sessionsScanned++;

            try {
                $staged = DB::transaction(function () use ($sessionId, $cutoff, &$photoRowsStaged): bool {
                    // Lock parent session by primary key
                    $session = PhotoUploadSession::query()
                        ->where('id', $sessionId)
                        ->lockForUpdate()
                        ->first();

                    if (! $session) {
                        return false; // Already cleaned by concurrent worker
                    }

                    // Authoritative recheck: must remain eligible
                    if ($session->examination_id !== null || $session->expires_at > $cutoff) {
                        return false; // Session changed state (finalized or not yet expired)
                    }

                    // Fresh child query after lock acquired
                    $photos = $session->photoUploads()
                        ->orderBy('display_order')
                        ->orderBy('id')
                        ->get();

                    $settleWindow = (int) config('zb-examine.photo_cleanup_settle_seconds', 3600);
                    $deleteAfter = $cutoff->copy()->addSeconds($settleWindow);

                    // Stage deletion intent for each photo
                    foreach ($photos as $photo) {
                        $this->stageDeletionIntent(
                            $photo->storage_disk,
                            $photo->storage_path,
                            $session->public_id,
                            $deleteAfter
                        );
                        $photoRowsStaged++;
                    }

                    // Delete session (cascades photo_uploads)
                    $session->delete();

                    return true;
                });

                if ($staged) {
                    $sessionsStaged++;
                }
            } catch (Throwable $e) {
                report($e);
                $failures[] = "Session {$sessionId}: ".$this->sanitizeErrorCode($e);
            }
        }

        return new PhotoUploadCleanupResult(
            sessionsScanned: $sessionsScanned,
            sessionsStaged: $sessionsStaged,
            photoRowsStaged: $photoRowsStaged,
            queueRowsDue: 0,
            objectsCleared: 0,
            clearingFailures: 0,
            integrityConflicts: 0,
            failures: $failures,
        );
    }

    /**
     * Process queued deletion intents that have reached their settle time.
     *
     * Only processes rows where delete_after <= now. Respects independent queue limit
     * to prevent starvation of old retries.
     *
     * Before any physical delete, checks if ExaminationPhoto references the path;
     * blocks deletion and logs integrity conflict if found (defense-in-depth).
     *
     * Each row is re-locked individually (`lockForUpdate()` by id) inside its own
     * short transaction before the irreversible `deletion_started_at` marker is set.
     * This is the same row (identified by storage_disk/storage_path) a direct-upload
     * completion claim locks — whichever side locks first wins, and the loser sees
     * the winner's already-committed outcome (see D026). No DB locks held during
     * storage deletion. Failed deletes leave the queue row unresolved with updated
     * retry metadata and a preserved marker; it will be retried next run.
     */
    public function processQueueUntilSettled(?CarbonInterface $now = null, ?int $limit = null): PhotoUploadCleanupResult
    {
        $now ??= now();
        $limit ??= (int) config('zb-examine.photo_cleanup_queue_limit', 500);

        $objectsCleared = 0;
        $clearingFailures = 0;
        $integrityConflicts = 0;
        $failures = [];

        // Unlocked advisory selection: candidate IDs only, re-locked individually below.
        $dueIds = PhotoUploadCleanupQueue::query()
            ->where('delete_after', '<=', $now)
            ->orderBy('delete_after')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $queueRowsDue = count($dueIds);

        foreach ($dueIds as $id) {
            try {
                // This lock is the same row-level mutex a direct-completion claim takes on
                // its candidate's queue row (by storage_disk/storage_path): whichever side
                // locks first wins, and the loser observes the other's committed outcome.
                $claim = DB::transaction(function () use ($id, $now): array {
                    $row = PhotoUploadCleanupQueue::query()->whereKey($id)->lockForUpdate()->first();

                    if (! $row || $row->delete_after > $now) {
                        // Already claimed/removed by a concurrent owner, or no longer due.
                        return ['action' => 'skip'];
                    }

                    // Defense-in-depth: never delete a path if it's referenced by finalized evidence
                    // or by a live PhotoUpload row (e.g. a candidate a claim just took ownership of).
                    $isEvidence = ExaminationPhoto::query()
                        ->where('storage_disk', $row->storage_disk)
                        ->where('storage_path', $row->storage_path)
                        ->exists();

                    $isLiveUpload = PhotoUpload::query()
                        ->where('storage_disk', $row->storage_disk)
                        ->where('storage_path', $row->storage_path)
                        ->exists();

                    if ($isEvidence || $isLiveUpload) {
                        report(new Exception(
                            'Integrity conflict: queued deletion intent references '
                            .($isEvidence ? 'examination_photos' : 'photo_uploads').': '
                            ."disk={$row->storage_disk}"
                        ));
                        $row->update([
                            'attempt_count' => $row->attempt_count + 1,
                            'last_attempt_at' => $now,
                            'last_failed_at' => $now,
                            'last_error_code' => $isEvidence ? 'finalized_evidence_reference' : 'live_upload_reference',
                        ]);

                        return ['action' => 'integrity_conflict'];
                    }

                    // The irreversible cleanup-ownership marker. Once committed, a
                    // concurrent claim locking this same row must never take ownership
                    // (see PhotoUploadService::completeDirect()). Never reset if already set.
                    $row->update(['deletion_started_at' => $row->deletion_started_at ?? $now]);

                    return ['action' => 'proceed', 'storage_disk' => $row->storage_disk, 'storage_path' => $row->storage_path];
                });

                if ($claim['action'] === 'skip') {
                    continue;
                }

                if ($claim['action'] === 'integrity_conflict') {
                    $integrityConflicts++;

                    continue;
                }

                try {
                    // Idempotent storage deletion outside any DB lock/transaction.
                    $this->transport->deleteByPath($claim['storage_disk'], $claim['storage_path']);

                    DB::transaction(function () use ($id): void {
                        PhotoUploadCleanupQueue::query()->whereKey($id)->lockForUpdate()->first()?->delete();
                    });
                    $objectsCleared++;
                } catch (Throwable $e) {
                    // Storage delete failed; update retry metadata and leave row for next run.
                    // The cleanup-start marker remains set so a concurrent claim cannot take ownership.
                    report($e);
                    DB::transaction(function () use ($id, $now, $e): void {
                        $row = PhotoUploadCleanupQueue::query()->whereKey($id)->lockForUpdate()->first();
                        $row?->update([
                            'deletion_started_at' => $row->deletion_started_at ?? $now,
                            'attempt_count' => $row->attempt_count + 1,
                            'last_attempt_at' => $now,
                            'last_failed_at' => $now,
                            'last_error_code' => $this->sanitizeErrorCode($e),
                        ]);
                    });
                    $clearingFailures++;
                    $failures[] = "Queue row {$id}: ".$this->sanitizeErrorCode($e);
                }
            } catch (Throwable $e) {
                report($e);
                $failures[] = "Queue row {$id}: ".$this->sanitizeErrorCode($e);
            }
        }

        return new PhotoUploadCleanupResult(
            sessionsScanned: 0,
            sessionsStaged: 0,
            photoRowsStaged: 0,
            queueRowsDue: $queueRowsDue,
            objectsCleared: $objectsCleared,
            clearingFailures: $clearingFailures,
            integrityConflicts: $integrityConflicts,
            failures: $failures,
        );
    }

    /**
     * Idempotently stage a durable deletion intent.
     *
     * Ensures atomicity with DB ownership removal: this must be called inside
     * a DB transaction that also removes the PhotoUpload row ownership.
     *
     * If an intent already exists for this (disk, path):
     *   - never shorten its delete_after (preserve/extend it)
     *   - preserve retry counters (don't reset attempt_count/timestamps)
     *
     * This allows concurrent operations (e.g., remove + expired cleanup finding
     * same abandoned object) to safely upsert.
     */
    public function stageDeletionIntent(
        string $storageDisk,
        string $storagePath,
        ?string $sourceSessionPublicId = null,
        ?CarbonInterface $deleteAfter = null,
    ): void {
        $deleteAfter ??= now()->addSeconds((int) config('zb-examine.photo_cleanup_settle_seconds', 3600));

        $table = (new PhotoUploadCleanupQueue)->getTable();
        $timestamp = now();
        $deadline = $deleteAfter->format('Y-m-d H:i:s');

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "INSERT INTO {$table} (storage_disk, storage_path, source_session_public_id, delete_after, deletion_started_at, attempt_count, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NULL, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    delete_after = IF(delete_after < VALUES(delete_after), VALUES(delete_after), delete_after),
                    deletion_started_at = IFNULL(deletion_started_at, VALUES(deletion_started_at)),
                    updated_at = VALUES(updated_at)",
                [$storageDisk, $storagePath, $sourceSessionPublicId, $deadline, $timestamp, $timestamp],
            );

            return;
        }

        DB::statement(
            "INSERT INTO {$table} (storage_disk, storage_path, source_session_public_id, delete_after, attempt_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, ?)
             ON CONFLICT(storage_disk, storage_path) DO UPDATE SET
                delete_after = CASE WHEN {$table}.delete_after < excluded.delete_after THEN excluded.delete_after ELSE {$table}.delete_after END,
                updated_at = excluded.updated_at",
            [$storageDisk, $storagePath, $sourceSessionPublicId, $deadline, $timestamp, $timestamp],
        );
    }

    /**
     * Sanitize exception message for safe storage.
     *
     * Returns a bounded, provider-agnostic error code if recognizable,
     * otherwise a generic fallback.
     *
     * Do NOT store raw exception text that may contain credentials/URLs.
     */
    private function sanitizeErrorCode(Throwable $e): string
    {
        $msg = $e->getMessage();

        // Recognize common storage patterns and map to safe codes
        if (str_contains($msg, 'not found') || str_contains($msg, 'does not exist')) {
            return 'object_not_found';
        }
        if (str_contains($msg, 'permission') || str_contains($msg, 'access denied')) {
            return 'access_denied';
        }
        if (str_contains($msg, 'connection') || str_contains($msg, 'timeout')) {
            return 'storage_unavailable';
        }

        return 'storage_delete_failed';
    }
}
