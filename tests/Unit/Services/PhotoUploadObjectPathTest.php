<?php

namespace Tests\Unit\Services;

use App\Services\PhotoUploadObjectPath;
use PHPUnit\Framework\TestCase;

class PhotoUploadObjectPathTest extends TestCase
{
    public function test_staging_path_contains_only_the_supplied_public_identifiers(): void
    {
        $path = PhotoUploadObjectPath::staging('session-public', 'photo-public');

        $this->assertSame('photo-upload-staging/session-public/photo-public.jpg', $path);
        $this->assertTrue(PhotoUploadObjectPath::isStaging($path));
        $this->assertFalse(PhotoUploadObjectPath::isSealed($path));
    }

    public function test_each_sealed_path_has_a_fresh_opaque_destination_identifier(): void
    {
        $first = PhotoUploadObjectPath::sealed('session-public', 'photo-public');
        $second = PhotoUploadObjectPath::sealed('session-public', 'photo-public');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression(
            '#^photo-uploads/session-public/photo-public/[a-f0-9]{48}\.jpg$#',
            $first,
        );
        $this->assertTrue(PhotoUploadObjectPath::isSealed($first));
        $this->assertFalse(PhotoUploadObjectPath::isStaging($first));
    }
}
