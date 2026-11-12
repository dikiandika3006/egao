<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan langsung di kasir tanpa kunjungan (permintaan klien: dental floss, behel per satuan untuk pasien rujukan).
 * Tagihan boleh tanpa visit: pembeli = pasien terdaftar (patient_id) atau nama bebas (buyer_name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('visit_id')->nullable()->change();
            $table->foreignId('patient_id')->nullable()->after('visit_id')->constrained()->nullOnDelete();
            $table->string('buyer_name')->nullable()->after('patient_id');
        });
    }

    public function down(): void
    {
        // visit_id sengaja dibiarkan nullable: mengembalikan NOT NULL akan gagal/menghapus data penjualan langsung.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_id');
            $table->dropColumn('buyer_name');
        });
    }
};
