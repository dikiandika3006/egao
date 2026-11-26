<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pricelist 2024 dari Owner (26 Nov 2026): Pemeriksaan, Pedodonsia, Periodonsia, Konservasi.
 * Hanya memperbarui tarif tindakan yang kodenya cocok (semua cabang) dan menambah tindakan yang belum ada.
 * Tidak menghapus data; kode yang tidak ditemukan dilewati. Harga bertanda "up" = satu harga (boleh dinaikkan Dokter/Owner).
 * Tarif range [min, maks]: harga default di pemeriksaan = maks.
 */
return new class extends Migration
{
    /** kode => [min, maks|null, nama baru|null] */
    private const UPDATES = [
        'KONS' => [35_000, null, null],
        'CA' => [200_000, null, null],
        'CBTA' => [200_000, null, null],
        'TAKG' => [150_000, 200_000, null],
        'SCAL' => [200_000, null, null],
        'TGD' => [250_000, 500_000, null],
        'TMBL' => [250_000, 500_000, null],
        'PSAT' => [250_000, null, null],
        'PSAG' => [350_000, null, 'Preparasi Saluran Akar Ganda (2-3 akar)'],
    ];

    /** kode => [nama, kategori, min, maks|null] */
    private const NEW = [
        'TGCL' => ['Tambal Gigi GIC/Komposit + Cal LC', 'Konservasi', 300_000, null],
        'PSA45' => ['Preparasi Saluran Akar (4-5 akar)', 'Konservasi', 400_000, null],
        'PSA5P' => ['Preparasi Saluran Akar (lebih dari 5 akar)', 'Konservasi', 450_000, null],
        'PDVA' => ['Pulpektomi/Pulpotomi Anak - Devitalisasi', 'Pedodonti', 150_000, null],
        'PPSA' => ['Pulpektomi/Pulpotomi Anak - Preparasi Saluran Akar', 'Pedodonti', 350_000, 450_000],
        'PSTA' => ['Pulpektomi/Pulpotomi Anak - Sterilisasi', 'Pedodonti', 150_000, null],
        'PPGA' => ['Pulpektomi/Pulpotomi Anak - Pengisian Saluran Akar', 'Pedodonti', 200_000, null],
    ];

    public function up(): void
    {
        // Hanya klinik yang sudah punya master tindakan (DB testing kosong = tidak ada perubahan).
        $clinicIds = DB::table('procedures')->distinct()->pluck('clinic_id');

        DB::transaction(function () use ($clinicIds) {
            $now = now();

            foreach ($clinicIds as $clinicId) {
                $branchIds = DB::table('branches')->where('clinic_id', $clinicId)->pluck('id');
                $codes = DB::table('procedures')->where('clinic_id', $clinicId)->pluck('id', 'code')
                    ->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id]);

                $setPrices = function (int $procedureId, int $min, ?int $max) use ($branchIds, $now) {
                    foreach ($branchIds as $branchId) {
                        $exists = DB::table('procedure_prices')->where('procedure_id', $procedureId)->where('branch_id', $branchId)->exists();
                        if ($exists) {
                            DB::table('procedure_prices')->where('procedure_id', $procedureId)->where('branch_id', $branchId)
                                ->update(['price' => $min, 'max_price' => $max, 'updated_at' => $now]);
                        } else {
                            DB::table('procedure_prices')->insert([
                                'procedure_id' => $procedureId, 'branch_id' => $branchId,
                                'price' => $min, 'max_price' => $max, 'created_at' => $now, 'updated_at' => $now,
                            ]);
                        }
                    }
                };

                foreach (self::UPDATES as $code => [$min, $max, $name]) {
                    if (! $id = $codes->get($code)) {
                        continue;
                    }
                    if ($name) {
                        DB::table('procedures')->where('id', $id)->update(['name' => $name, 'updated_at' => $now]);
                    }
                    $setPrices($id, $min, $max);
                }

                foreach (self::NEW as $code => [$name, $category, $min, $max]) {
                    if ($codes->has($code)) {
                        continue;
                    }
                    $id = DB::table('procedures')->insertGetId([
                        'clinic_id' => $clinicId, 'code' => $code, 'name' => $name, 'category' => $category,
                        'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $setPrices($id, $min, $max);
                }
            }
        });
    }

    /** Perubahan data tarif tidak dibalik otomatis (bisa diubah lewat Master data › Tindakan & tarif). */
    public function down(): void {}
};
