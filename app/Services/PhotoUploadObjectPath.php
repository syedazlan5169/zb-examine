<?php

namespace App\Services;

final class PhotoUploadObjectPath
{
    public static function isLocalFinalized(string $storagePath): bool
    {
        return preg_match(
            '#^photo-uploads/[0-9A-HJKMNP-TV-Z]{26}/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#',
            $storagePath,
        ) === 1;
    }

    public static function isSpacesFinalized(string $storagePath): bool
    {
        return preg_match(
            '#^photo-uploads/[0-9A-HJKMNP-TV-Z]{26}/[0-9A-HJKMNP-TV-Z]{26}/[a-f0-9]{48}\.jpg$#',
            $storagePath,
        ) === 1;
    }

    public static function classifyStorage(string $storageDisk, string $storagePath): string
    {
        if ($storageDisk === config('zb-examine.photo_upload_direct_disk', 'photo_uploads_spaces') && (self::isStaging($storagePath) || self::isSealed($storagePath))) {
            return self::isStaging($storagePath) || self::isSealed($storagePath) ? 'direct' : 'proxy';
        }

        return 'proxy';
    }

    public static function isDirectStorage(string $storageDisk, string $storagePath): bool
    {
        return $storageDisk === config('zb-examine.photo_upload_direct_disk', 'photo_uploads_spaces')
            && (self::isStaging($storagePath) || self::isSealed($storagePath));
    }

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
