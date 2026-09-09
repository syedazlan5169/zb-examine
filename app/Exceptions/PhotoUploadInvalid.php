<?php

namespace App\Exceptions;

use RuntimeException;

/** Photo-level rejection, scoped to an already-authenticated session. */
final class PhotoUploadInvalid extends RuntimeException
{
    private const HTTP_STATUSES = [
        'photo_limit_reached' => 422,
        'invalid_photo' => 422,
        'photo_too_large' => 422,
        'photo_not_found' => 404,
        // Genuinely incomplete: complete() called before any upload exists.
        'upload_not_ready' => 409,
        // Wrong direction: an operation conflicts with an already-settled state
        // (e.g. re-uploading a photo that's already verified).
        'photo_state_conflict' => 409,
        // Step 3B.4 finalization-only checks, re-verified authoritatively under the session lock.
        'photo_count_invalid' => 422,
        'unverified_photo_pending' => 422,
    ];

    public function __construct(private readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUSES[$this->errorCode];
    }
}
