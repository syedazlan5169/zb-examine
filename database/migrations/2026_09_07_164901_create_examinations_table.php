<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $table) {
            $table->id();

            $table->string('submission_no', 32)->unique();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Agent snapshot
            $table->string('agent_name');
            $table->string('agent_phone', 30);
            $table->string('agent_code', 50);
            $table->string('agent_company_name');
            $table->string('agent_station_code', 50);

            // Examination details
            $table->string('location', 50);

            $table->string('form_type', 50);
            $table->string('form_type_other')->nullable();

            $table->string('container_status', 30);

            $table->string('reason', 50)->nullable();
            $table->string('reason_other')->nullable();

            $table->string('attending_officer_type', 30);

            $table->timestamp('submitted_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examinations');
    }
};