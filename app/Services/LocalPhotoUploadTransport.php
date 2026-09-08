<?php

namespace App\Services;

use App\Exceptions\PhotoUploadInvalid;
use App\Models\PhotoUpload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LocalPhotoUploadTransport implements PhotoUploadTransport
{
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MiB, the locked hard cap

    /**
     * Publishes bytes to storage_path exactly once. A complete temporary file
     * is written first, then atomically published via link() \u2014 which either
     * creates the destination in one syscall or fails without touching it \u2014
     * so a previously published object is never overwritten and the final
     * path is never visible in a partially-written state.
     *
     * @throws PhotoUploadInvalid with 'photo_state_conflict' if storage_path
     *                            was already published by an earlier request
     */
    public function store(PhotoUpload $upload, UploadedFile $file): void
    {
        $finalPath = $this->absolutePath($upload);
        $directory = dirname($finalPath);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new PhotoUploadInvalid('invalid_photo');
        }

        // Written under a unique throwaway name first, on the same directory
        // tree as $finalPath, so the later link() is guaranteed same-filesystem.
        $tempPath = $directory.'/.'.Str::random(32).'.tmp';

        if (@copy($file->getRealPath(), $tempPath) === false) {
            @unlink($tempPath);

            throw new PhotoUploadInvalid('invalid_photo');
        }

        // Atomic w.r.t. destination existence on POSIX: link() either creates
        // $finalPath pointing at the fully-written temp file's inode in one
        // syscall, or fails leaving $finalPath completely untouched \u2014 never
        // a torn/overwritten/partially-visible destination either way.
        $published = @link($tempPath, $finalPath);

        @unlink($tempPath);

        if (! $published) {
            // Never overwrite, never delete an object this request didn't
            // create \u2014 a previously published object always wins.
            throw new PhotoUploadInvalid('photo_state_conflict');
        }
    }

    public function verify(PhotoUpload $upload): array
    {
        $disk = $this->disk($upload);

        if (! $disk->exists($upload->storage_path)) {
            throw new PhotoUploadInvalid('upload_not_ready');
        }

        $size = $disk->size($upload->storage_path);

        if ($size > self::MAX_BYTES) {
            throw new PhotoUploadInvalid('photo_too_large');
        }

        $path = $disk->path($upload->storage_path);

        // Magic-byte check, not the client's declared Content-Type/extension.
        if (@exif_imagetype($path) !== IMAGETYPE_JPEG) {
            throw new PhotoUploadInvalid('invalid_photo');
        }

        $dimensions = @getimagesize($path);

        if ($dimensions === false || ($dimensions['mime'] ?? null) !== 'image/jpeg' || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
            throw new PhotoUploadInvalid('invalid_photo');
        }

        return [
            'mime_type' => 'image/jpeg',
            'file_size' => $size,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ];
    }

    public function delete(PhotoUpload $upload): void
    {
        $this->disk($upload)->delete($upload->storage_path);
    }

    private function absolutePath(PhotoUpload $upload): string
    {
        return $this->disk($upload)->path($upload->storage_path);
    }

    private function disk(PhotoUpload $upload): Filesystem
    {
        return Storage::disk($upload->storage_disk);
    }
}
