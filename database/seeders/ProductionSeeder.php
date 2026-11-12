<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\ExpenseCategory;
use App\Models\Procedure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data awal untuk server asli: klinik, cabang, master klinis (kondisi gigi, ICD-10), daftar tindakan & tarif awal,
 * dan kategori pengeluaran. TANPA pasien/akun contoh. Akun Owner dibuat terpisah: php artisan egao:owner
 *
 * Aman dijalankan ulang: berhenti bila klinik sudah ada.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        if (Clinic::exists()) {
            $this->command?->warn('Data klinik sudah ada, ProductionSeeder dilewati.');

            return;
        }

        DB::transaction(function () {
            $clinic = Clinic::create(['name' => 'Egao Dental Care']);

            $footer = 'Terima kasih telah mempercayakan senyum Anda kepada Egao.';
            $branches = [
                $clinic->branches()->create([
                    'code' => 'BTU', 'name' => 'Cabang Batu', 'city' => 'Batu', 'color' => 'batu',
                    'address' => 'Jl. Panglima Sudirman No.226, Tlekung, Junrejo, Kota Batu', 'receipt_footer' => $footer,
                ]),
                $clinic->branches()->create([
                    'code' => 'MLG', 'name' => 'Cabang Malang', 'city' => 'Malang', 'color' => 'malang',
                    'address' => 'Kota Malang', 'receipt_footer' => $footer,
                ]),
            ];

            DB::table('settings')->insertOrIgnore([
                ['key' => 'share_percent_global', 'value' => '20'],
                ['key' => 'session_idle_hours', 'value' => '8'],
            ]);

            (new ClinicalMasterSeeder)->run($clinic->id);

            // Tarif awal: silakan sesuaikan di Pengaturan › Tindakan & tarif.
            foreach ([
                ['KONS', 'Konsultasi', 'Umum', 50_000],
                ['PRKS', 'Pemeriksaan rutin', 'Umum', 100_000],
                ['SCAL', 'Scaling', 'Periodonti', 250_000],
                ['TMBL', 'Tambal gigi', 'Konservasi', 300_000],
                ['CBUT', 'Cabut gigi', 'Bedah mulut', 350_000],
                ['CBTA', 'Cabut gigi anak', 'Pedodonti', 150_000],
                ['BHEL', 'Pasang behel', 'Ortodonti', 5_000_000],
                ['KBHL', 'Kontrol behel', 'Ortodonti', 200_000],
                ['GTSL', 'Gigi tiruan lepasan', 'Prostodonti', 1_500_000],
            ] as [$code, $name, $category, $price]) {
                $procedure = Procedure::create(['clinic_id' => $clinic->id, 'code' => $code, 'name' => $name, 'category' => $category]);
                foreach ($branches as $branch) {
                    $procedure->prices()->create(['branch_id' => $branch->id, 'price' => $price]);
                }
            }

            foreach (['Listrik dan air', 'Sewa', 'Bahan habis pakai', 'Lab gigi', 'Lain-lain'] as $name) {
                ExpenseCategory::create(['clinic_id' => $clinic->id, 'name' => $name]);
            }
            ExpenseCategory::create(['clinic_id' => $clinic->id, 'name' => 'Gaji dan bagi hasil', 'is_system' => true]);
        });

        $this->command?->info('Data awal dibuat. Lanjutkan: php artisan egao:owner');
    }
}
