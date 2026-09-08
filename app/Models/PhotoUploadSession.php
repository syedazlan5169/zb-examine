<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Parent row of the future session-row mutex (Step 3B.2+): every operation that
 * adds/completes/removes/reorders photos or finalizes the session must
 * lockForUpdate() this row before mutating it or its photoUploads.
 *
 * `examination_id` is the sole finalization signal (null = temporary/unclaimed,
 * set = finalized) — there is no separate finalized_at/status column.
 */
class PhotoUploadSession extends Model
{
    use HasFactory;

    // Nothing is mass-assignable: public_id/token_hash are generated internally,
    // examination_id is only ever set by a future locked finalize step.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * The single canonical way a session/token pair is ever created — the raw
     * bearer token exists only in this method's return value, never persisted.
     *
     * @return array{session: self, token: string}
     */
    public static function issue(): array
    {
        $token = Str::random(64);

        $session = new self;
        $session->token_hash = hash('sha256', $token);
        $session->expires_at = now()->addDay();
        $session->save();

        return ['session' => $session, 'token' => $token];
    }

    public function photoUploads(): HasMany
    {
        return $this->hasMany(PhotoUpload::class)->orderBy('display_order');
    }

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }
}
