<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examination_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('examination_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('storage_disk', 50);
            $table->string('storage_path', 1024);

            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            $table->unsignedTinyInteger('display_order');

            $table->timestamps();

            $table->index([
                'examination_id',
                'display_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_photos');
    }
};