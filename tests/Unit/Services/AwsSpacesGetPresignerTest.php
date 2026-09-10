<?php

namespace Tests\Unit\Services;

use App\Services\AwsSpacesGetPresigner;
use App\Services\SpacesS3ClientFactory;
use Tests\TestCase;

class AwsSpacesGetPresignerTest extends TestCase
{
    public function test_it_signs_a_get_object_request_with_server_controlled_response_metadata(): void
    {
        $secret = 'test-secret-that-must-not-be-returned';
        config([
            'zb-examine.photo_upload_direct_disk' => 'photo_uploads_spaces',
            'filesystems.disks.photo_uploads_spaces' => [
                'region' => 'sgp1',
                'endpoint' => 'https://sgp1.digitaloceanspaces.com',
                'bucket' => 'private-bucket',
                'key' => 'test-key',
                'secret' => $secret,
                'use_path_style_endpoint' => false,
            ],
        ]);

        $url = new AwsSpacesGetPresigner(new SpacesS3ClientFactory)->presign(
            'photo-uploads/01JAR7Z5G2R9D7RQP6H9P6FS03/01JAR7Z5G2R9D7RQP6H9P6FS04/0123456789abcdef0123456789abcdef0123456789abcdef.jpg',
            120,
            'evidence-42.jpg',
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringContainsString('private-bucket', $url);
        $this->assertStringContainsString('photo-uploads/01JAR7Z5G2R9D7RQP6H9P6FS03/01JAR7Z5G2R9D7RQP6H9P6FS04/0123456789abcdef0123456789abcdef0123456789abcdef.jpg', $url);
        $this->assertSame('120', $query['X-Amz-Expires']);
        $this->assertSame('image/jpeg', $query['response-content-type']);
        $this->assertSame('inline; filename="evidence-42.jpg"', $query['response-content-disposition']);
        $this->assertSame('private, no-store, max-age=0', $query['response-cache-control']);
        $this->assertStringContainsString('X-Amz-Credential', $url);
        $this->assertStringNotContainsString($secret, $url);
    }
}
