<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jam kerja per cabang (JSON: hari ISO 1–7 → daftar sesi [mulai, selesai]).
 * Cabang yang ada diisi jam default: Sen–Jum 10–13 & 15–20, Sab–Min 10–17.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->json('operating_hours')->nullable()->after('phone');
        });

        $weekday = [['10:00', '13:00'], ['15:00', '20:00']];
        $weekend = [['10:00', '17:00']];
        $default = json_encode([1 => $weekday, 2 => $weekday, 3 => $weekday, 4 => $weekday, 5 => $weekday, 6 => $weekend, 7 => $weekend]);

        DB::table('branches')->whereNull('operating_hours')->update(['operating_hours' => $default]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('operating_hours');
        });
    }
};
