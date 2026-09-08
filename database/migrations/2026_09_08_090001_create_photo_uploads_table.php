<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_uploads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('photo_upload_session_id')
                ->constrained()
                ->cascadeOnDelete();

            // Non-secret identifier; also the immutable object-path filename component.
            $table->char('public_id', 26)->unique();

            $table->string('storage_disk', 50);

            // Kept well under MySQL's utf8mb4 unique-index byte limit (unlike
            // examination_photos.storage_path, this column carries a unique index).
            $table->string('storage_path', 512)->unique();

            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Null = pending/not finalization-eligible. Set = server-verified. No status column.
            $table->timestamp('verified_at')->nullable();

            $table->unsignedTinyInteger('display_order');

            $table->timestamps();

            $table->index(['photo_upload_session_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_uploads');
    }
};
