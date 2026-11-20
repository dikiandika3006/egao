<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen gaji per peran (permintaan klien):
 *  - biaya modal per tindakan (alat behel, x-ray) dipotong sebelum fee dokter,
 *  - asisten pendamping per kunjungan (fee asisten),
 *  - jam hadir & lembur per bulan (UD per jam, lembur per jam),
 *  - catatan pasien rujukan drg luar.
 * Semua kolom baru nullable/default 0 → hasil gaji bulan lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_amount')->default(0)->after('share_percent');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('assistant_id')->nullable()->after('doctor_id')->constrained('employees')->nullOnDelete();
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('referred_by')->nullable()->after('allergies');
        });

        Schema::create('payroll_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->date('period'); // tanggal 1 bulan terkait
            $table->string('payee_type', 20); // doctor | employee
            $table->unsignedBigInteger('payee_id');
            $table->decimal('work_hours', 6, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['clinic_id', 'period', 'payee_type', 'payee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_hours');
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('referred_by'));
        Schema::table('visits', fn (Blueprint $t) => $t->dropConstrainedForeignId('assistant_id'));
        Schema::table('procedures', fn (Blueprint $t) => $t->dropColumn('cost_amount'));
    }
};
