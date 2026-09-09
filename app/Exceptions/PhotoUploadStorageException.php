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
}
