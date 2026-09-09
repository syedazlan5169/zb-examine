<?php

namespace App\Services;

interface SpacesObjectClient
{
    /** @return array{size: int, etag: string} */
    public function head(string $storagePath): array;

    /** @return array{size: int, etag: ?string} */
    public function getToFile(string $storagePath, string $etag, string $destinationPath): array;

    public function putFile(string $storagePath, string $sourcePath, int $fileSize, string $mimeType): void;

    public function deleteByPath(string $storagePath): void;
}
