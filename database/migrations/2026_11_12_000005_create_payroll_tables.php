<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji karyawan (FR-GJI-05) dan bonus/potongan per periode (FR-GJI-03/04).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Karyawan non-dokter: admin, perawat, dll. Akun login opsional.
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('primary_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('position', 50); // Admin, Perawat, Asisten, Kebersihan, ...
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->date('joined_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Bonus/potongan per bulan, untuk dokter maupun karyawan.
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->date('period'); // tanggal 1 bulan terkait
            $table->string('payee_type', 20); // doctor | employee
            $table->unsignedBigInteger('payee_id');
            $table->string('type', 20); // bonus | potongan
            $table->unsignedBigInteger('amount');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_id', 'period']);
            $table->index(['payee_type', 'payee_id', 'period']);
        });

        // Kategori pengeluaran "Lab gigi" untuk klinik yang sudah ada (permintaan klien).
        foreach (DB::table('clinics')->pluck('id') as $clinicId) {
            $exists = DB::table('expense_categories')->where('clinic_id', $clinicId)->where('name', 'Lab gigi')->exists();
            if (! $exists) {
                DB::table('expense_categories')->insert([
                    'clinic_id' => $clinicId, 'name' => 'Lab gigi', 'is_system' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('employees');
    }
};
