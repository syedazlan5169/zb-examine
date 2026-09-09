<?php

namespace Tests\Unit\Services;

use App\Data\VerifiedPhotoUploadSource;
use App\Exceptions\PhotoUploadStorageException;
use App\Services\PhotoUploadObjectPath;
use App\Services\SpacesObjectClient;
use App\Services\SpacesPhotoUploadSealer;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class SpacesPhotoUploadSealerTest extends TestCase
{
    public function test_head_etag_is_reused_for_conditional_get_and_valid_jpeg_is_verified(): void
    {
        $path = PhotoUploadObjectPath::staging('session-public', 'photo-public');
        $file = UploadedFile::fake()->image('photo.jpg', 20, 10);
        $bytes = file_get_contents($file->getRealPath());
        $client = new FakeSpacesObjectClient($bytes, '"etag-a"');
        $sealer = new SpacesPhotoUploadSealer($client);

        $source = $sealer->verifyStaging($path);

        $this->assertSame('"etag-a"', $source->etag);
        $this->assertSame(20, $source->width);
        $this->assertSame(10, $source->height);
        $this->assertSame('"etag-a"', $client->getIfMatch);
        $this->assertFileExists($source->temporaryPath);
        $source->release();
        $this->assertFileDoesNotExist($source->temporaryPath);
    }

    public function test_oversized_head_is_rejected_before_get(): void
    {
        $client = new FakeSpacesObjectClient('', '"etag-a"', headSize: 2 * 1024 * 1024 + 1);
        $sealer = new SpacesPhotoUploadSealer($client);

        $this->expectExceptionObject(new PhotoUploadStorageException('object_too_large'));
        $sealer->verifyStaging(PhotoUploadObjectPath::staging('session-public', 'photo-public'));

        $this->assertFalse($client->getCalled);
    }

    public function test_corrupt_content_is_rejected_and_temporary_file_is_cleaned(): void
    {
        $client = new FakeSpacesObjectClient('not a jpeg', '"etag-a"');
        $sealer = new SpacesPhotoUploadSealer($client);
        $parserWarnings = [];

        set_error_handler(function (int $severity, string $message) use (&$parserWarnings): bool {
            if (in_array($severity, [E_WARNING, E_NOTICE], true)) {
                $parserWarnings[] = $message;
            }

            return true;
        });

        try {
            $sealer->verifyStaging(PhotoUploadObjectPath::staging('session-public', 'photo-public'));
            $this->fail('Expected corrupt image content to be rejected.');
        } catch (PhotoUploadStorageException $exception) {
            $this->assertSame('invalid_image', $exception->getErrorCode());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $parserWarnings);
        $this->assertFileDoesNotExist($client->temporaryPath);
    }

    public function test_sealed_put_receives_the_exact_verified_snapshot_bytes(): void
    {
        $client = new FakeSpacesObjectClient('', '"etag-a"');
        $sealer = new SpacesPhotoUploadSealer($client);
        $sourcePath = PhotoUploadObjectPath::staging('session-public', 'photo-public');
        $sealedPath = PhotoUploadObjectPath::sealed('session-public', 'photo-public');
        $file = UploadedFile::fake()->image('photo.jpg', 20, 10);
        $bytes = file_get_contents($file->getRealPath());
        $client->downloadBytes = $bytes;

        $source = $sealer->verifyStaging($sourcePath);

        try {
            $sealer->seal($source, $sealedPath);
        } finally {
            $source->release();
        }

        $this->assertSame($sealedPath, $client->uploadedPath);
        $this->assertSame($bytes, $client->uploadedBytes);
        $this->assertSame('image/jpeg', $client->uploadedMimeType);
        $this->assertSame(strlen($bytes), $client->uploadedFileSize);
        $this->assertFileDoesNotExist($source->temporaryPath);
    }

    public function test_sealed_put_failure_can_be_cleaned_by_callers(): void
    {
        $client = new FakeSpacesObjectClient('not a jpeg', '"etag-a"');
        $sealer = new SpacesPhotoUploadSealer($client);
        $source = new VerifiedPhotoUploadSource(
            PhotoUploadObjectPath::staging('session-public', 'photo-public'),
            '"etag-a"',
            11,
            'image/jpeg',
            20,
            10,
            tempnam(sys_get_temp_dir(), 'zb-examine-test-'),
        );
        file_put_contents($source->temporaryPath, 'verified bytes');
        $client->putException = new PhotoUploadStorageException('seal_failed');

        try {
            $sealer->seal($source, PhotoUploadObjectPath::sealed('session-public', 'photo-public'));
            $this->fail('Expected the sealed PUT to fail.');
        } catch (PhotoUploadStorageException $exception) {
            $this->assertSame('seal_failed', $exception->getErrorCode());
        } finally {
            $source->release();
        }

        $this->assertFileDoesNotExist($source->temporaryPath);
    }
}

final class FakeSpacesObjectClient implements SpacesObjectClient
{
    public bool $getCalled = false;

    public ?string $getIfMatch = null;

    public ?string $temporaryPath = null;

    public string $downloadBytes;

    public ?string $uploadedPath = null;

    public ?string $uploadedBytes = null;

    public ?string $uploadedMimeType = null;

    public ?int $uploadedFileSize = null;

    public ?PhotoUploadStorageException $putException = null;

    public function __construct(
        private readonly string $bytes,
        private readonly string $etag,
        private readonly ?int $headSize = null,
    ) {
        $this->downloadBytes = $bytes;
    }

    public function head(string $storagePath): array
    {
        return ['size' => $this->headSize ?? strlen($this->bytes), 'etag' => $this->etag];
    }

    public function getToFile(string $storagePath, string $etag, string $destinationPath): array
    {
        $this->getCalled = true;
        $this->getIfMatch = $etag;
        $this->temporaryPath = $destinationPath;
        file_put_contents($destinationPath, $this->downloadBytes);

        return ['size' => strlen($this->downloadBytes), 'etag' => $this->etag];
    }

    public function putFile(string $storagePath, string $sourcePath, int $fileSize, string $mimeType): void
    {
        if ($this->putException !== null) {
            throw $this->putException;
        }

        $this->uploadedPath = $storagePath;
        $this->uploadedBytes = file_get_contents($sourcePath);
        $this->uploadedMimeType = $mimeType;
        $this->uploadedFileSize = $fileSize;
    }

    public function deleteByPath(string $storagePath): void {}
}
