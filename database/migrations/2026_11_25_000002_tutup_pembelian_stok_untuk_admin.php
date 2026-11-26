<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grup "Manajemen stok" (25 Nov 2026): Admin/Asisten hanya melihat posisi, kartu, dan mutasi stok tanpa harga.
 * Izin "stok" (kini Pembelian & opname) yang sudah tersimpan untuk Admin ditutup; menu baru memakai bawaan.
 * Owner bisa membukanya lagi di Pengaturan › Hak akses peran. Tidak menghapus data lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', Access::KEY)->first();
        $matrix = $row ? json_decode((string) $row->value, true) : null;
        if (! is_array($matrix) || ! isset($matrix['admin']['stok'])) {
            return;
        }

        $matrix['admin']['stok'] = ['lihat' => false, 'edit' => false, 'keuangan' => false];
        DB::table('settings')->where('key', Access::KEY)->update(['value' => json_encode($matrix)]);
    }

    public function down(): void
    {
        // Sengaja tidak dikembalikan; atur ulang lewat Pengaturan › Hak akses peran.
    }
};
