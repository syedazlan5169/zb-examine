<?php

namespace App\Services;

use App\Models\PhotoUpload;
use Illuminate\Http\UploadedFile;

/**
 * Storage-plane operations only. A future SpacesPhotoUploadTransport (Step
 * 3B.6) implements the same operations against Spaces HEAD/PUT semantics.
 */
interface PhotoUploadTransport
{
    /**
     * Publishes storage_path exactly once. Implementations must never
     * overwrite a previously published object for this PhotoUpload — reject
     * (typically PhotoUploadInvalid('photo_state_conflict')) instead.
     */
    public function store(PhotoUpload $upload, UploadedFile $file): void;

    /**
     * Re-derive metadata from the actual stored object \u2014 never trust the client.
     *
     * @return array{mime_type: string, file_size: int, width: int, height: int}
     */
    public function verify(PhotoUpload $upload): array;

    public function delete(PhotoUpload $upload): void;

    /**
     * Delete by storage location (used by cleanup queue processor).
     *
     * Idempotent: success and already-absent are both logical success outcomes.
     * Implementations MUST return normally for logical success and MUST throw
     * for operational failure. No PhotoUpload model is needed because the DB
     * ownership row may already have been deleted.
     */
    public function deleteByPath(string $storageDisk, string $storagePath): void;
}
