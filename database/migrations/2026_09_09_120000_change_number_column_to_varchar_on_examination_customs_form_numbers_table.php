<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_customs_form_numbers', function (Blueprint $table) {
            // Free-form customs form numbers (Step 3B.4 refinement): formats vary by
            // form type and are no longer constrained to the old B+11-digit shape.
            $table->string('number', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('examination_customs_form_numbers', function (Blueprint $table) {
            $table->char('number', 12)->change();
        });
    }
};
