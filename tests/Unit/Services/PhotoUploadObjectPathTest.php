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

    public function test_local_finalized_path_accepts_only_the_canonical_proxy_path(): void
    {
        $path = 'photo-uploads/01JAR7Z5G2R9D7RQP6H9P6FS03/01JAR7Z5G2R9D7RQP6H9P6FS04.jpg';

        $this->assertTrue(PhotoUploadObjectPath::isLocalFinalized($path));
    }

    public function test_local_finalized_path_rejects_noncanonical_and_unsafe_forms(): void
    {
        foreach ([
            '',
            'photo-upload-staging/01JAR7Z5G2R9D7RQP6H9P6FS03/01JAR7Z5G2R9D7RQP6H9P6FS04.jpg',
            'photo-uploads/01JAR7Z5G2R9D7RQP6H9P6FS03/01JAR7Z5G2R9D7RQP6H9P6FS04/abcdef.jpg',
            '/photo-uploads/01JAR7Z5G2R9D7RQP6H9P6FS03/01JAR7Z5G2R9D7RQP6H9P6FS04.jpg',
            'photo-uploads/../private.jpg',
            "photo-uploads/01JAR7Z5G2R9D7RQP6H9P6FS03/01JAR7Z5G2R9D7RQP6H9P6FS04.jpg\0other",
        ] as $path) {
            $this->assertFalse(PhotoUploadObjectPath::isLocalFinalized($path));
        }
    }
}
