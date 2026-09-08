<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Session-level rejection. `invalid_session` covers a nonexistent public_id AND
 * a wrong token identically, so a guessed public_id is never distinguishable.
 */
final class PhotoUploadSessionInvalid extends RuntimeException
{
    private const HTTP_STATUSES = [
        'invalid_session' => 401,
        'session_expired' => 410,
        'session_finalized' => 409,
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
