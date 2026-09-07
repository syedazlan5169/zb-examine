<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examination_customs_form_numbers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('examination_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->char('number', 12);
            $table->unsignedTinyInteger('display_order');

            $table->timestamps();

            $table->index('number');

            $table->unique([
                'examination_id',
                'number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_customs_form_numbers');
    }
};