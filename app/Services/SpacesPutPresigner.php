<?php

namespace App\Services;

use App\Data\DirectPhotoUploadAuthorization;
use Carbon\CarbonInterface;

interface SpacesPutPresigner
{
    public function presign(string $storagePath, CarbonInterface $expiresAt): DirectPhotoUploadAuthorization;
}
