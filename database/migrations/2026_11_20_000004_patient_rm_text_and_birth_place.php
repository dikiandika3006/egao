<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No. RM klinik berformat huruf awal nama + nomor urut (A-021), jadi kolomnya teks.
 * Angka lama (128) otomatis menjadi teks "128". Tanggal lahir & telepon boleh kosong
 * (data lama Assist.id banyak yang tidak lengkap), ditambah tempat lahir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('medical_record_number', 20)->change();
            $table->date('birth_date')->nullable()->change();
            $table->string('phone', 20)->nullable()->change();
            $table->string('birth_place', 100)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('birth_place');
        });
    }
};
