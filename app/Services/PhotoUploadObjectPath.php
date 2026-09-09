<?php

namespace App\Services;

final class PhotoUploadObjectPath
{
    public static function staging(string $sessionPublicId, string $photoPublicId): string
    {
        return "photo-upload-staging/{$sessionPublicId}/{$photoPublicId}.jpg";
    }

    public static function sealed(string $sessionPublicId, string $photoPublicId): string
    {
        $sealId = bin2hex(random_bytes(24));

        return "photo-uploads/{$sessionPublicId}/{$photoPublicId}/{$sealId}.jpg";
    }

    public static function isStaging(string $storagePath): bool
    {
        return str_starts_with($storagePath, 'photo-upload-staging/');
    }

    public static function isSealed(string $storagePath): bool
    {
        return str_starts_with($storagePath, 'photo-uploads/');
    }
}
