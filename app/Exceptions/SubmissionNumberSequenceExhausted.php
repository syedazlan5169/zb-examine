<?php

namespace App\Exceptions;

use RuntimeException;

final class SubmissionNumberSequenceExhausted extends RuntimeException
{
    public function __construct(
        private readonly string $businessDate,
        private readonly string $errorCode = 'sequence_exhausted',
    ) {
        parent::__construct($errorCode);
    }

    public function getBusinessDate(): string
    {
        return $this->businessDate;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
