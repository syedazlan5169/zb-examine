<?php

namespace App\Services;

interface SpacesGetPresigner
{
    public function presign(string $objectKey, int $ttlSeconds, string $filename): string;
}
