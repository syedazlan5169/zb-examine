<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_upload_cleanup_queue', function (Blueprint $table): void {
            if (! Schema::hasColumn('photo_upload_cleanup_queue', 'deletion_started_at')) {
                $table->timestamp('deletion_started_at')->nullable()->after('delete_after');
            }
        });
    }

    public function down(): void
    {
        Schema::table('photo_upload_cleanup_queue', function (Blueprint $table): void {
            if (Schema::hasColumn('photo_upload_cleanup_queue', 'deletion_started_at')) {
                $table->dropColumn('deletion_started_at');
            }
        });
    }
};
