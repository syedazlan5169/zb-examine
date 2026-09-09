<?php

namespace App\Services;

use App\Data\VerifiedPhotoUploadSource;
use App\Exceptions\PhotoUploadStorageException;

final class SpacesPhotoUploadSealer
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly SpacesObjectClient $objects) {}

    public function verifyStaging(string $storagePath): VerifiedPhotoUploadSource
    {
        $head = $this->objects->head($storagePath);

        if ($head['size'] > self::MAX_BYTES) {
            throw new PhotoUploadStorageException('object_too_large');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'zb-examine-photo-');

        if ($temporaryPath === false) {
            throw new PhotoUploadStorageException('storage_unavailable');
        }

        try {
            $download = $this->objects->getToFile($storagePath, $head['etag'], $temporaryPath);

            if ($download['etag'] !== null && $download['etag'] !== $head['etag']) {
                throw new PhotoUploadStorageException('source_changed');
            }

            $actualSize = filesize($temporaryPath);

            if ($actualSize === false || $actualSize > self::MAX_BYTES || $download['size'] !== $actualSize) {
                throw new PhotoUploadStorageException('object_too_large');
            }

            if ($this->parseImageType($temporaryPath) !== IMAGETYPE_JPEG) {
                throw new PhotoUploadStorageException('invalid_image');
            }

            $dimensions = $this->parseImageSize($temporaryPath);

            if (
                $dimensions === false
                || ($dimensions['mime'] ?? null) !== 'image/jpeg'
                || ($dimensions[0] ?? 0) <= 0
                || ($dimensions[1] ?? 0) <= 0
            ) {
                throw new PhotoUploadStorageException('invalid_image');
            }

            return new VerifiedPhotoUploadSource(
                storagePath: $storagePath,
                etag: $head['etag'],
                fileSize: $actualSize,
                mimeType: 'image/jpeg',
                width: $dimensions[0],
                height: $dimensions[1],
                temporaryPath: $temporaryPath,
            );
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);

            throw $exception;
        }
    }

    public function seal(VerifiedPhotoUploadSource $source, string $sealedStoragePath): void
    {
        if (! PhotoUploadObjectPath::isStaging($source->storagePath) || ! PhotoUploadObjectPath::isSealed($sealedStoragePath)) {
            throw new PhotoUploadStorageException('seal_failed');
        }

        $this->objects->putFile($sealedStoragePath, $source->temporaryPath, $source->fileSize, $source->mimeType);
    }

    private function parseImageType(string $path): false|int
    {
        return $this->withoutParserWarnings(
            static fn (): false|int => @exif_imagetype($path),
        );
    }

    /** @return array<int|string, mixed>|false */
    private function parseImageSize(string $path): array|false
    {
        return $this->withoutParserWarnings(
            static fn (): array|false => @getimagesize($path),
        );
    }

    private function withoutParserWarnings(callable $parser): mixed
    {
        set_error_handler(static function (int $severity): bool {
            return in_array($severity, [E_WARNING, E_NOTICE], true);
        });

        try {
            return $parser();
        } finally {
            restore_error_handler();
        }
    }
}
