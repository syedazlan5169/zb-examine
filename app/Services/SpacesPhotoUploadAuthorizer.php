<?php

namespace App\Services;

use App\Data\DirectPhotoUploadAuthorization;
use App\Exceptions\PhotoUploadStorageException;
use Carbon\CarbonInterface;

final class SpacesPhotoUploadAuthorizer implements DirectPhotoUploadAuthorizer
{
    public function __construct(private readonly SpacesPutPresigner $presigner) {}

    public function authorizeStaging(string $storagePath, CarbonInterface $expiresAt): DirectPhotoUploadAuthorization
    {
        if (! PhotoUploadObjectPath::isStaging($storagePath) || $expiresAt->isPast()) {
            throw new PhotoUploadStorageException('seal_failed');
        }

        return $this->presigner->presign($storagePath, $expiresAt);
    }
}
