<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal ulang janji temu (riwayat + alasan) dan alasan batal / tidak datang.
 * Hanya menambah tabel & kolom nullable; data lama tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->date('from_date');
            $table->time('from_time')->nullable();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->date('to_date');
            $table->time('to_time')->nullable();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->string('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['appointment_id', 'created_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('status_reason')->nullable()->after('status'); // alasan batal / tidak datang
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('status_reason'));
        Schema::dropIfExists('appointment_reschedules');
    }
};
