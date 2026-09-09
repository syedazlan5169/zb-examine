<?php

namespace App\Services;

use Aws\S3\S3Client;

final class SpacesS3ClientFactory
{
    public function make(): S3Client
    {
        $disk = config('filesystems.disks.'.config('zb-examine.photo_upload_direct_disk', 'photo_uploads_spaces'));

        return new S3Client([
            'version' => 'latest',
            'region' => $disk['region'],
            'endpoint' => $disk['endpoint'],
            'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? false),
            'credentials' => [
                'key' => $disk['key'],
                'secret' => $disk['secret'],
            ],
        ]);
    }

    public function bucket(): string
    {
        $disk = config('filesystems.disks.'.config('zb-examine.photo_upload_direct_disk', 'photo_uploads_spaces'));

        return (string) $disk['bucket'];
    }
}
