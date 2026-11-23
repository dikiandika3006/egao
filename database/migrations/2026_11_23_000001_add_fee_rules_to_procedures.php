<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan fee tindakan bertahap (gigi palsu & behel):
 * - fee_from_procedure_id: pembayaran tindakan tahap awal (mis. Cetak) pasien yang sama ikut menjadi dasar fee tindakan ini.
 * - fee_minus_lab: biaya order lab pasien dipotong dari dasar fee saat tindakan ini lunas (mis. Insersi).
 * Data lama tidak berubah: kolom nullable/default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->foreignId('fee_from_procedure_id')->nullable()->after('cost_amount')->constrained('procedures')->nullOnDelete();
            $table->boolean('fee_minus_lab')->default(false)->after('fee_from_procedure_id');
        });
    }

    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_from_procedure_id');
            $table->dropColumn('fee_minus_lab');
        });
    }
};
