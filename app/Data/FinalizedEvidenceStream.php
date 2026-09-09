<?php

namespace App\Data;

final class FinalizedEvidenceStream
{
    /** @param resource $stream */
    public function __construct(
        public mixed $stream,
        public readonly string $mimeType,
        public readonly ?int $contentLength,
        public readonly string $filename,
    ) {}

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
