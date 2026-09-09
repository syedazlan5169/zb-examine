<?php

namespace App\Data;

use Carbon\CarbonInterface;

final readonly class DirectPhotoUploadAuthorization
{
    /**
     * @param  array<string, string>  $requiredHeaders
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $requiredHeaders,
        public CarbonInterface $expiresAt,
    ) {}
}
