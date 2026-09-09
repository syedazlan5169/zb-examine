<?php

namespace App\Data;

final class VerifiedPhotoUploadSource
{
    private bool $released = false;

    public function __construct(
        public string $storagePath,
        public string $etag,
        public int $fileSize,
        public string $mimeType,
        public int $width,
        public int $height,
        public string $temporaryPath,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        @unlink($this->temporaryPath);
    }

    public function __destruct()
    {
        $this->release();
    }
}
