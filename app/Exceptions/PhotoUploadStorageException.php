<?php

namespace App\Exceptions;

use RuntimeException;

final class PhotoUploadStorageException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            'source_changed' => 409,
            'invalid_image', 'object_too_large' => 422,
            default => 503,
        };
    }
}
