<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable deletion intent queue.
 *
 * Records storage objects that the application database has decided must
 * eventually be physically deleted. Created when DB ownership is removed
 * (explicit remove or expired-session cleanup).
 *
 * Storage deletion is deferred until delete_after, allowing in-flight uploads
 * to complete (late-publication race protection). Processed by cleanup queue
 * processor outside any DB transaction/lock.
 */
class PhotoUploadCleanupQueue extends Model
{
    protected $table = 'photo_upload_cleanup_queue';

    protected $fillable = [
        'storage_disk',
        'storage_path',
        'source_session_public_id',
        'delete_after',
        'deletion_started_at',
        'attempt_count',
        'last_attempt_at',
        'last_failed_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'delete_after' => 'datetime',
            'deletion_started_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }
}
