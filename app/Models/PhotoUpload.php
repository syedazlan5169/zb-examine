<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Lifecycle is represented only by `verified_at` (null = pending, set = server-
 * verified/finalization-eligible) and row existence (deleted = removed) — no
 * status column/enum exists by design (see docs/DECISIONS.md).
 */
class PhotoUpload extends Model
{
    use HasFactory;

    // Nothing is mass-assignable: public_id is generated internally, and every
    // other column is only ever set by a future locked domain service.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'display_order' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $upload): void {
            $upload->public_id ??= (string) Str::ulid();
        });
    }

    public function photoUploadSession(): BelongsTo
    {
        return $this->belongsTo(PhotoUploadSession::class);
    }
}
