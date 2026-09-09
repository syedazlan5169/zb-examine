<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_upload_cleanup_queue', function (Blueprint $table) {
            $table->id();

            // Storage location identifiers (immutable, the deletion target)
            $table->string('storage_disk', 50);
            $table->string('storage_path', 512);

            // Which expired session triggered this deletion intent (optional tracking)
            $table->char('source_session_public_id', 26)->nullable();

            // Authoritative settle time: physical deletion must not occur before this
            $table->timestamp('delete_after');

            // Retry tracking for operational visibility
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();

            // Sanitized error code if implementation tracks failure reasons
            $table->string('last_error_code', 100)->nullable();

            $table->timestamps();

            // Exact path is immutable; prevent duplicate intents for same object
            $table->unique(['storage_disk', 'storage_path']);

            // Process oldest-due-first
            $table->index(['delete_after', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_upload_cleanup_queue');
    }
};
