<?php

namespace App\Services;

use App\Data\FinalizedEvidenceRedirect;
use App\Data\FinalizedEvidenceStream;
use App\Exceptions\FinalizedEvidenceDeliveryUnavailable;
use App\Exceptions\FinalizedEvidenceNotFound;
use App\Exceptions\SpacesGetPresigningException;
use App\Models\ExaminationPhoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FinalizedEvidenceAccessService
{
    public function __construct(private readonly SpacesGetPresigner $spacesGetPresigner) {}

    public function open(ExaminationPhoto $photo): FinalizedEvidenceStream|FinalizedEvidenceRedirect
    {
        if ($photo->storage_disk === 'photo_uploads_spaces') {
            if (! PhotoUploadObjectPath::isSpacesFinalized($photo->storage_path) || $photo->mime_type !== 'image/jpeg') {
                $this->logNotFound($photo, 'invalid_storage_metadata');

                throw new FinalizedEvidenceNotFound;
            }

            try {
                return new FinalizedEvidenceRedirect(
                    url: $this->spacesGetPresigner->presign(
                        $photo->storage_path,
                        $this->previewTtlSeconds(),
                        "evidence-{$photo->id}.jpg",
                    ),
                );
            } catch (SpacesGetPresigningException $exception) {
                Log::error('Finalized evidence redirect generation failed', [
                    'examination_photo_id' => $photo->id,
                    'examination_id' => $photo->examination_id,
                    'storage_disk' => $photo->storage_disk,
                    'failure_category' => 'presigning_failure',
                ]);

                throw new FinalizedEvidenceDeliveryUnavailable(previous: $exception);
            }
        }

        if ($photo->storage_disk !== 'photo_uploads' || ! PhotoUploadObjectPath::isLocalFinalized($photo->storage_path) || $photo->mime_type !== 'image/jpeg') {
            $this->logNotFound($photo, 'invalid_storage_metadata');

            throw new FinalizedEvidenceNotFound;
        }

        $stream = null;

        try {
            $disk = Storage::disk($photo->storage_disk);

            if (! $disk->exists($photo->storage_path)) {
                $this->logNotFound($photo, 'object_missing');

                throw new FinalizedEvidenceNotFound;
            }

            try {
                $contentLength = $disk->size($photo->storage_path);
            } catch (Throwable) {
                $contentLength = null;
            }

            $stream = $disk->readStream($photo->storage_path);

            if (! is_resource($stream)) {
                $this->logNotFound($photo, 'object_unreadable');

                throw new FinalizedEvidenceNotFound;
            }

            return new FinalizedEvidenceStream(
                stream: $stream,
                mimeType: $photo->mime_type,
                contentLength: $contentLength,
                filename: "evidence-{$photo->id}.jpg",
            );
        } catch (FinalizedEvidenceNotFound $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            Log::error('Finalized evidence retrieval failed', [
                'examination_photo_id' => $photo->id,
                'examination_id' => $photo->examination_id,
                'storage_disk' => $photo->storage_disk,
                'failure_category' => 'filesystem_failure',
            ]);

            throw $exception;
        }
    }

    private function logNotFound(ExaminationPhoto $photo, string $failureCategory): void
    {
        Log::warning('Finalized evidence is unavailable', [
            'examination_photo_id' => $photo->id,
            'examination_id' => $photo->examination_id,
            'storage_disk' => $photo->storage_disk,
            'failure_category' => $failureCategory,
        ]);
    }

    private function previewTtlSeconds(): int
    {
        $configured = config('zb-examine.photo_preview_presign_ttl_seconds', 120);
        $seconds = match (true) {
            is_int($configured) => $configured,
            is_string($configured) && preg_match('/^-?\d+$/D', $configured) === 1 => (int) $configured,
            default => 120,
        };

        return max(60, min(300, $seconds));
    }
}
