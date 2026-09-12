<?php

namespace App\Http\Controllers;

use App\Models\PhotoUpload;
use App\Services\PhotoUploadObjectPath;
use App\Services\PhotoUploadSessionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TemporaryPhotoPreviewController
{
    public function show(
        Request $request,
        string $sessionPublicId,
        string $photoPublicId,
        PhotoUploadSessionResolver $resolver,
    ): StreamedResponse {
        $session = $resolver->resolve($sessionPublicId, (string) $request->header('X-Photo-Upload-Token'));
        $resolver->assertNotFinalized($session);
        $photo = $session->photoUploads()->where('public_id', $photoPublicId)->first();

        abort_unless($photo instanceof PhotoUpload && $photo->verified_at !== null, 404);

        $isDirectStorage = $photo->storage_disk === config('zb-examine.photo_upload_direct_disk', 'photo_uploads_spaces');
        $isProxyStorage = $photo->storage_disk === config('zb-examine.photo_upload_disk', 'photo_uploads');

        abort_unless(
            ($isDirectStorage && PhotoUploadObjectPath::isSpacesFinalized($photo->storage_path))
                || ($isProxyStorage && PhotoUploadObjectPath::isLocalFinalized($photo->storage_path)),
            404,
        );

        $disk = Storage::disk($photo->storage_disk);
        abort_unless($disk->exists($photo->storage_path), 404);

        try {
            $stream = $disk->readStream($photo->storage_path);
            abort_unless(is_resource($stream), 404);

            try {
                $contentLength = $disk->size($photo->storage_path);
            } catch (Throwable) {
                $contentLength = null;
            }
        } catch (Throwable) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $photo->mime_type ?: 'image/jpeg',
            'Content-Disposition' => 'inline; filename="temporary-photo-'.$photo->public_id.'.jpg"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($contentLength !== null) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
