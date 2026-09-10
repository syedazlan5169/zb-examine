<?php

namespace App\Exceptions;

use RuntimeException;

final class SpacesGetPresigningException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Spaces GET presigning failed.', 0, $previous);
    }
}
