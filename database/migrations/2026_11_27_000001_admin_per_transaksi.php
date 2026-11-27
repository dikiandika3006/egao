<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan Owner 27 Nov 2026: biaya admin per transaksi tindakan + biaya pendaftaran sekali untuk pasien baru.
 * - patients.registration_paid: pasien sudah membayar pendaftaran (pasien lama). Semua pasien yang sudah ada
 *   (impor Assist.id dll.) dianggap pasien lama.
 * - invoice_items.fee_kind: jenis baris biaya admin ('admin' | 'pendaftaran'). Baris lama (admin sekali per pasien)
 *   dicatat sebagai pendaftaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $t) {
            $t->boolean('registration_paid')->default(false)->after('referred_by');
        });
        DB::table('patients')->update(['registration_paid' => true]);

        Schema::table('invoice_items', function (Blueprint $t) {
            $t->string('fee_kind', 20)->nullable()->after('is_admin_fee');
        });
        DB::table('invoice_items')->where('is_admin_fee', true)->update(['fee_kind' => 'pendaftaran']);
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('fee_kind'));
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('registration_paid'));
    }
};
