<?php

namespace App\Console\Commands;

use App\Models\Clinic;
use App\Services\PatientImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Impor data pasien dari ekspor Assist.id ("Data Pasien.xlsx").
 * Aman dijalankan ulang: No. RM yang sudah ada dilewati.
 * Logika ada di PatientImportService (dipakai juga oleh tombol impor di Data pasien).
 */
class ImportPasienAssist extends Command
{
    protected $signature = 'egao:import-pasien {file : Path file Data Pasien.xlsx dari Assist.id}
        {--clinic= : ID klinik (default: klinik pertama)}
        {--dry-run : Hanya tampilkan ringkasan, tidak menyimpan}';

    protected $description = 'Impor data pasien dari ekspor Excel Assist.id';

    public function handle(PatientImportService $service): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        $clinicId = (int) ($this->option('clinic') ?: Clinic::query()->min('id'));
        if (! Clinic::whereKey($clinicId)->exists()) {
            $this->error('Klinik tidak ditemukan.');

            return self::FAILURE;
        }

        $result = $service->import($file, $clinicId, (bool) $this->option('dry-run'));

        if ($result['error']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $stats = $result['stats'];
        $this->table(['Ringkasan', 'Jumlah'], [
            ['Pasien baru diimpor', $stats['baru']],
            ['Dilewati (No. RM sudah ada)', $stats['dilewati']],
            ['No. RM ganda → nomor baru', $stats['rm_baru']],
            ['NIK tidak valid/ganda → dikosongkan', $stats['nik_kosong']],
            ['Tanggal lahir tidak diketahui', $stats['tgl_lahir_kosong']],
        ]);
        $this->line('Laporan detail: '.Storage::disk('local')->path($result['reportPath']));

        if ($this->option('dry-run')) {
            $this->warn('Dry run: tidak ada data yang disimpan.');

            return self::SUCCESS;
        }

        $this->info("Selesai: {$stats['baru']} pasien diimpor.");

        return self::SUCCESS;
    }
}
