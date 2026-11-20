<?php

namespace App\Console\Commands;

use App\Services\SatuSehat\SatuSehatService;
use Illuminate\Console\Command;

/**
 * Kirim antrean kunjungan ke SATUSEHAT (Encounter + Condition). Dijadwalkan tiap 5 menit di routes/console.php;
 * manual: php artisan egao:satusehat-kirim
 */
class SatuSehatKirim extends Command
{
    protected $signature = 'egao:satusehat-kirim {--limit= : Jumlah kunjungan maksimal per putaran}';

    protected $description = 'Kirim kunjungan yang tertunda ke SATUSEHAT';

    public function handle(SatuSehatService $satusehat): int
    {
        if (! $satusehat->enabled()) {
            $this->info('Integrasi SATUSEHAT nonaktif (Pengaturan → SATUSEHAT).');

            return self::SUCCESS;
        }

        [$sent, $failed] = $satusehat->sendPending($this->option('limit') ? (int) $this->option('limit') : null);
        $this->info("SATUSEHAT: {$sent} terkirim, {$failed} gagal.");

        return self::SUCCESS;
    }
}
