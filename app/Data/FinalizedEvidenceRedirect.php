<?php

namespace App\Data;

final class FinalizedEvidenceRedirect
{
    public function __construct(
        public readonly string $url,
    ) {}
}
