<?php

namespace App\Services;

use App\Exceptions\PhotoUploadSessionInvalid;
use App\Models\PhotoUploadSession;

/**
 * The single canonical place upload-session token authentication happens.
 * No controller/service re-implements token hashing or lookup elsewhere.
 */
final class PhotoUploadSessionResolver
{
    /** Unlocked read: for resume/read paths and pre-checks before slow I/O. */
    public function resolve(string $publicId, string $rawToken): PhotoUploadSession
    {
        $session = $this->authenticate($publicId, $rawToken, lock: false);

        $this->assertNotExpired($session);

        return $session;
    }

    /**
     * Must be called inside an active DB::transaction(). Locks the parent
     * session row \u2014 the mandated serialization point for every mutation \u2014
     * and re-checks expiry/finalization authoritatively.
     */
    public function resolveLocked(string $publicId, string $rawToken): PhotoUploadSession
    {
        $session = $this->authenticate($publicId, $rawToken, lock: true);

        $this->assertNotExpired($session);
        $this->assertNotFinalized($session);

        return $session;
    }

    /** Exposed so read paths (e.g. upload's unlocked pre-check) can opt into this check. */
    public function assertNotFinalized(PhotoUploadSession $session): void
    {
        if ($session->examination_id !== null) {
            throw new PhotoUploadSessionInvalid('session_finalized');
        }
    }

    private function assertNotExpired(PhotoUploadSession $session): void
    {
        if ($session->expires_at->isPast()) {
            throw new PhotoUploadSessionInvalid('session_expired');
        }
    }

    private function authenticate(string $publicId, string $rawToken, bool $lock): PhotoUploadSession
    {
        $query = PhotoUploadSession::query()
            ->where('public_id', $publicId)
            ->where('token_hash', hash('sha256', $rawToken));

        if ($lock) {
            $query->lockForUpdate();
        }

        // A single combined predicate: wrong token and a nonexistent/mismatched
        // public_id both simply match zero rows \u2014 indistinguishable from outside.
        $session = $query->first();

        if (! $session) {
            throw new PhotoUploadSessionInvalid('invalid_session');
        }

        return $session;
    }
}
