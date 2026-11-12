<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarif bagi hasil khusus per dokter × tindakan (FR-GJI). Prioritas tertinggi saat menghitung bagi hasil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_procedure_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 5, 2);
            $table->timestamps();

            $table->unique(['doctor_id', 'procedure_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_procedure_shares');
    }
};
