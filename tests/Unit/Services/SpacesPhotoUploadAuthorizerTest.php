<?php

namespace Tests\Unit\Services;

use App\Data\DirectPhotoUploadAuthorization;
use App\Exceptions\PhotoUploadStorageException;
use App\Services\PhotoUploadObjectPath;
use App\Services\SpacesPhotoUploadAuthorizer;
use App\Services\SpacesPutPresigner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PHPUnit\Framework\TestCase;

class SpacesPhotoUploadAuthorizerTest extends TestCase
{
    public function test_only_staging_paths_receive_a_put_capability(): void
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(5);
        $presigner = new FakeSpacesPutPresigner;
        $authorizer = new SpacesPhotoUploadAuthorizer($presigner);

        $authorization = $authorizer->authorizeStaging(
            PhotoUploadObjectPath::staging('session-public', 'photo-public'),
            $expiresAt,
        );

        $this->assertSame('PUT', $authorization->method);
        $this->assertSame(['Content-Type' => 'image/jpeg'], $authorization->requiredHeaders);
        $this->assertSame(PhotoUploadObjectPath::staging('session-public', 'photo-public'), $presigner->path);

        $this->expectExceptionObject(new PhotoUploadStorageException('seal_failed'));
        $authorizer->authorizeStaging(PhotoUploadObjectPath::sealed('session-public', 'photo-public'), $expiresAt);
    }

    public function test_expired_authorization_is_rejected(): void
    {
        $authorizer = new SpacesPhotoUploadAuthorizer(new FakeSpacesPutPresigner);

        $this->expectExceptionObject(new PhotoUploadStorageException('seal_failed'));
        $authorizer->authorizeStaging(
            PhotoUploadObjectPath::staging('session-public', 'photo-public'),
            CarbonImmutable::now()->subSecond(),
        );
    }
}

final class FakeSpacesPutPresigner implements SpacesPutPresigner
{
    public ?string $path = null;

    public function presign(string $storagePath, CarbonInterface $expiresAt): DirectPhotoUploadAuthorization
    {
        $this->path = $storagePath;

        return new DirectPhotoUploadAuthorization('PUT', 'https://example.test/signed', ['Content-Type' => 'image/jpeg'], $expiresAt);
    }
}
