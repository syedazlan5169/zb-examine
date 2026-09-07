<?php

namespace App\Exceptions;

use InvalidArgumentException;

final class InvalidCustomsFormNumberInput extends InvalidArgumentException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly ?string $token = null,
        private readonly ?int $tokenIndex = null,
        private readonly ?string $normalizedValue = null,
    ) {
        parent::__construct($errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getTokenIndex(): ?int
    {
        return $this->tokenIndex;
    }

    public function getNormalizedValue(): ?string
    {
        return $this->normalizedValue;
    }
}
