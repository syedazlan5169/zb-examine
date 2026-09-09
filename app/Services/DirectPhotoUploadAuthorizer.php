<?php

namespace App\Services;

use App\Data\DirectPhotoUploadAuthorization;
use Carbon\CarbonInterface;

interface DirectPhotoUploadAuthorizer
{
    public function authorizeStaging(string $storagePath, CarbonInterface $expiresAt): DirectPhotoUploadAuthorization;
}
