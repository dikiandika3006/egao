<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga standar tindakan saat disimpan. Dokter/Owner boleh menaikkan harga (unit_price ≥ standard_price);
 * kolom ini menyimpan jejaknya. Data lama dibiarkan null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->unsignedInteger('standard_price')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('standard_price');
        });
    }
};
