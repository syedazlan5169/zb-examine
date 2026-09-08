<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_upload_sessions', function (Blueprint $table) {
            $table->id();

            // Non-secret identifier, safe for future object paths/API payloads.
            $table->char('public_id', 26)->unique();

            // Raw bearer token is never persisted, only its sha256 hex digest.
            $table->char('token_hash', 64)->unique();

            // Null = temporary/unclaimed. Set = finalized; the sole finalization signal.
            $table->foreignId('examination_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['examination_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_upload_sessions');
    }
};
