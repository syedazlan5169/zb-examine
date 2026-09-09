<?php

namespace App\Services;

use App\Data\FinalizedEvidenceStream;
use App\Exceptions\FinalizedEvidenceDeliveryUnavailable;
use App\Exceptions\FinalizedEvidenceNotFound;
use App\Models\ExaminationPhoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class FinalizedEvidenceAccessService
{
    public function open(ExaminationPhoto $photo): FinalizedEvidenceStream
    {
        if ($photo->storage_disk === 'photo_uploads_spaces') {
            throw new FinalizedEvidenceDeliveryUnavailable;
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
}
