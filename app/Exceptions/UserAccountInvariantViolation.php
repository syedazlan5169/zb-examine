<?php

namespace App\Exceptions;

use RuntimeException;

final class UserAccountInvariantViolation extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
