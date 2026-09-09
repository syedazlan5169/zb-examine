<?php

namespace Tests\Unit\Services;

use App\Providers\AppServiceProvider;
use App\Services\LocalPhotoUploadTransport;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUploadStorageConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['zb-examine.photo_upload_mode' => 'proxy']);
    }

    public function test_proxy_defaults_preserve_the_existing_local_disk(): void
    {
        $this->assertSame('proxy', config('zb-examine.photo_upload_mode'));
        $this->assertSame('photo_uploads', config('zb-examine.photo_upload_disk'));
        $this->assertSame('photo_uploads_spaces', config('zb-examine.photo_upload_direct_disk'));
        $this->assertSame(300, config('zb-examine.photo_upload_presign_ttl_seconds'));
        $this->assertSame('local', config('filesystems.disks.photo_uploads.driver'));
        $this->assertSame('s3', config('filesystems.disks.photo_uploads_spaces.driver'));
    }

    public function test_invalid_presign_ttl_is_rejected_before_runtime_use(): void
    {
        config([
            'zb-examine.photo_upload_presign_ttl_seconds' => 3600,
            'zb-examine.photo_cleanup_settle_seconds' => 3600,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHOTO_UPLOAD_PRESIGN_TTL');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_existing_transport_can_delete_from_the_separate_spaces_disk(): void
    {
        Storage::fake('photo_uploads_spaces');
        Storage::disk('photo_uploads_spaces')->put('temporary/object.jpg', 'bytes');

        (new LocalPhotoUploadTransport)->deleteByPath('photo_uploads_spaces', 'temporary/object.jpg');

        Storage::disk('photo_uploads_spaces')->assertMissing('temporary/object.jpg');
    }
}
