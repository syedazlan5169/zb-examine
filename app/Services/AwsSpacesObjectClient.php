<?php

namespace App\Services;

use App\Exceptions\PhotoUploadStorageException;
use Aws\Exception\AwsException;
use Throwable;

final class AwsSpacesObjectClient implements SpacesObjectClient
{
    public function __construct(private readonly SpacesS3ClientFactory $clients) {}

    public function head(string $storagePath): array
    {
        try {
            $result = $this->clients->make()->headObject([
                'Bucket' => $this->clients->bucket(),
                'Key' => $storagePath,
            ]);

            return [
                'size' => (int) $result['ContentLength'],
                'etag' => (string) $result['ETag'],
            ];
        } catch (Throwable $exception) {
            throw $this->mapException($exception, 'object_not_found');
        }
    }

    public function getToFile(string $storagePath, string $etag, string $destinationPath): array
    {
        $stream = null;

        try {
            $result = $this->clients->make()->getObject([
                'Bucket' => $this->clients->bucket(),
                'Key' => $storagePath,
                'IfMatch' => $etag,
            ]);

            $stream = fopen($destinationPath, 'wb');

            if ($stream === false) {
                throw new PhotoUploadStorageException('storage_unavailable');
            }

            $body = $result['Body'];
            $bytes = 0;

            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    break;
                }

                $written = fwrite($stream, $chunk);

                if ($written === false) {
                    throw new PhotoUploadStorageException('storage_unavailable');
                }

                $bytes += $written;
            }

            return [
                'size' => $bytes,
                'etag' => isset($result['ETag']) ? (string) $result['ETag'] : null,
            ];
        } catch (Throwable $exception) {
            if ($exception instanceof PhotoUploadStorageException) {
                throw $exception;
            }

            throw $this->mapException($exception, 'storage_unavailable');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function putFile(string $storagePath, string $sourcePath, int $fileSize, string $mimeType): void
    {
        $stream = null;

        try {
            $stream = fopen($sourcePath, 'rb');

            if ($stream === false) {
                throw new PhotoUploadStorageException('seal_failed');
            }

            $this->clients->make()->putObject([
                'Bucket' => $this->clients->bucket(),
                'Key' => $storagePath,
                'Body' => $stream,
                'ContentLength' => $fileSize,
                'ContentType' => $mimeType,
            ]);
        } catch (Throwable $exception) {
            if ($exception instanceof PhotoUploadStorageException) {
                throw $exception;
            }

            throw $this->mapException($exception, 'seal_failed');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function deleteByPath(string $storagePath): void
    {
        try {
            $this->clients->make()->deleteObject([
                'Bucket' => $this->clients->bucket(),
                'Key' => $storagePath,
            ]);
        } catch (Throwable $exception) {
            if ($this->isNotFound($exception)) {
                return;
            }

            throw $this->mapException($exception, 'storage_unavailable');
        }
    }

    private function mapException(Throwable $exception, string $fallback): PhotoUploadStorageException
    {
        if ($this->isNotFound($exception)) {
            return new PhotoUploadStorageException('object_not_found', previous: $exception);
        }

        if ($exception instanceof AwsException) {
            $code = $exception->getAwsErrorCode();

            if (in_array($code, ['PreconditionFailed', 'ConditionalRequestConflict'], true)) {
                return new PhotoUploadStorageException('source_changed', previous: $exception);
            }

            if (in_array($exception->getStatusCode(), [401, 403], true)) {
                return new PhotoUploadStorageException('access_denied', previous: $exception);
            }
        }

        return new PhotoUploadStorageException($fallback, previous: $exception);
    }

    private function isNotFound(Throwable $exception): bool
    {
        return $exception instanceof AwsException
            && in_array($exception->getAwsErrorCode(), ['NotFound', 'NoSuchKey', 'NoSuchBucket'], true);
    }
}
