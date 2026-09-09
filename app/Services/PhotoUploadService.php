<?php

namespace App\Services;

use App\Exceptions\PhotoUploadInvalid;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PhotoUploadService
{
    private const MAX_PHOTOS_PER_SESSION = 10;

    public function __construct(
        private readonly PhotoUploadSessionResolver $sessions,
        private readonly PhotoUploadTransport $transport,
    ) {}

    /**
     * Allocation is a state mutation: entirely inside one locked transaction,
     * never an unlocked count() followed by a separate insert.
     */
    public function allocate(string $sessionPublicId, string $token): PhotoUpload
    {
        return DB::transaction(function () use ($sessionPublicId, $token) {
            $session = $this->sessions->resolveLocked($sessionPublicId, $token);

            $count = $session->photoUploads()->count();

            if ($count >= self::MAX_PHOTOS_PER_SESSION) {
                throw new PhotoUploadInvalid('photo_limit_reached');
            }

            $publicId = (string) Str::ulid();

            $upload = new PhotoUpload;
            $upload->photo_upload_session_id = $session->id;
            $upload->public_id = $publicId;
            $upload->storage_disk = config('zb-examine.photo_upload_disk');
            $upload->storage_path = "photo-uploads/{$session->public_id}/{$publicId}.jpg";
            $upload->display_order = $count + 1;
            $upload->save();

            return $upload;
        });
    }

    /**
     * Receives already-optimized JPEG bytes for a pending photo. No DB lock is
     * held while writing to storage. Once transport->store() has atomically
     * published the object it is never deleted here for any reason — not a
     * concurrent complete(), not a concurrent remove(), not session
     * expiry/finalization racing in — an unreferenced private orphan is an
     * acceptable, Step-3B.5-reconcilable outcome; deleting evidence another
     * request may already have accepted is not. store() itself is the only
     * safety boundary: it rejects (never overwrites) if this photo's object
     * was already published by an earlier request.
     */
    public function upload(string $sessionPublicId, string $token, string $photoPublicId, UploadedFile $file): void
    {
        $upload = $this->prepareForUpload($sessionPublicId, $token, $photoPublicId);

        $this->transport->store($upload, $file);
    }

    /**
     * `verify()` never authorizes deletion: a transient/failed verification
     * is not proof the immutable published object is safe to destroy \u2014 a
     * concurrent complete() may already be about to commit verified_at for
     * this exact object using metadata it captured earlier. On failure the
     * row stays pending and the object stays intact; only an explicit
     * remove() (row-first, object-second) or future Step 3B.5 reconciliation
     * may ever delete a published object.
     */
    public function complete(string $sessionPublicId, string $token, string $photoPublicId): PhotoUpload
    {
        // Unlocked and potentially slow: never happens inside a DB transaction/lock.
        $session = $this->sessions->resolve($sessionPublicId, $token);
        $this->sessions->assertNotFinalized($session);

        $upload = $this->findOwned($session, $photoPublicId);

        if ($upload->verified_at !== null) {
            return $upload; // duplicate/retried completion: idempotent, unchanged
        }

        // Not caught here: a verification failure leaves the row pending and
        // the object untouched, exactly as if this request had never happened.
        $metadata = $this->transport->verify($upload);

        return DB::transaction(function () use ($sessionPublicId, $token, $photoPublicId, $metadata) {
            $session = $this->sessions->resolveLocked($sessionPublicId, $token);

            $upload = $this->findOwned($session, $photoPublicId);

            if ($upload->verified_at !== null) {
                return $upload; // completion raced in between the two checks: idempotent
            }

            $upload->mime_type = $metadata['mime_type'];
            $upload->file_size = $metadata['file_size'];
            $upload->width = $metadata['width'];
            $upload->height = $metadata['height'];
            $upload->verified_at = now();
            $upload->save();

            return $upload;
        });
    }

    /**
     * Remove an existing photo upload.
     *
     * Updated in Step 3B.5: now creates durable deletion intent inside DB transaction
     * before removing row ownership. This ensures no orphan loss if storage delete fails.
     *
     * Physical storage deletion is deferred to queue processor at/after settle time.
     * No storage call occurs in this request.
     */
    public function remove(string $sessionPublicId, string $token, string $photoPublicId): void
    {
        DB::transaction(function () use ($sessionPublicId, $token, $photoPublicId): void {
            $session = $this->sessions->resolveLocked($sessionPublicId, $token);

            $upload = $this->findOwned($session, $photoPublicId);

            // Create durable deletion intent before removing DB ownership
            $settleWindow = (int) config('zb-examine.photo_cleanup_settle_seconds', 3600);
            $deleteAfter = now()->addSeconds($settleWindow);

            app(PhotoUploadCleanupService::class)->stageDeletionIntent(
                $upload->storage_disk,
                $upload->storage_path,
                $session->public_id,
                $deleteAfter,
            );

            // Now safe to delete row; intent is durable
            $upload->delete();
        });

        // Physical deletion deferred to queue processor at/after settle time
        // No storage call here; no bestEffortDelete
    }

    private function findOwned(PhotoUploadSession $session, string $photoPublicId): PhotoUpload
    {
        // Always scoped through the session's own relation \u2014 never a global lookup.
        $upload = $session->photoUploads()->where('public_id', $photoPublicId)->first();

        if (! $upload) {
            throw new PhotoUploadInvalid('photo_not_found');
        }

        return $upload;
    }

    private function prepareForUpload(string $sessionPublicId, string $token, string $photoPublicId): PhotoUpload
    {
        $session = $this->sessions->resolve($sessionPublicId, $token);
        $this->sessions->assertNotFinalized($session);

        $upload = $this->findOwned($session, $photoPublicId);

        if ($upload->verified_at !== null) {
            throw new PhotoUploadInvalid('photo_state_conflict');
        }

        return $upload;
    }
}
