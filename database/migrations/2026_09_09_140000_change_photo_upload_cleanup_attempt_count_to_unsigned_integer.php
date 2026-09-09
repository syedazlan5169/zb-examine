<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_upload_cleanup_queue', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_count')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('photo_upload_cleanup_queue', function (Blueprint $table): void {
            $table->unsignedTinyInteger('attempt_count')->default(0)->change();
        });
    }
};
