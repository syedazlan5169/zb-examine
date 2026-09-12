<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examinations', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('deleted_by_user_id')
                ->nullable()
                ->after('deleted_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('examinations', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by_user_id']);
            $table->dropColumn('deleted_by_user_id');
            $table->dropSoftDeletes();
        });
    }
};
