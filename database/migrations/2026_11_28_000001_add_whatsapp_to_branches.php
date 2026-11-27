<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('whatsapp', 30)->nullable()->after('phone');
        });

        // Backfill: nomor WA global (Pengaturan › Aplikasi) dipakai sebagai nilai awal semua cabang.
        $wa = DB::table('settings')->where('key', 'clinic_whatsapp')->value('value');
        if (filled($wa)) {
            DB::table('branches')->whereNull('whatsapp')->update(['whatsapp' => $wa]);
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('whatsapp');
        });
    }
};
