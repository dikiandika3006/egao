<?php

namespace App\Console\Commands;

use App\Services\GoogleCalendar\GoogleCalendarSync as Sync;
use Illuminate\Console\Command;

/**
 * Kirim janji temu baru/berubah ke Google Calendar dokter & Owner. Dijadwalkan tiap menit di routes/console.php;
 * manual: php artisan egao:gcal-sync
 */
class GoogleCalendarSync extends Command
{
    protected $signature = 'egao:gcal-sync {--limit= : Jumlah janji maksimal per putaran}';

    protected $description = 'Sinkronkan janji temu ke Google Calendar';

    public function handle(Sync $sync): int
    {
        if (! $sync->enabled()) {
            $this->info('Integrasi Google Calendar nonaktif (Pengaturan → Google Calendar).');

            return self::SUCCESS;
        }

        [$ok, $failed] = $sync->syncPending($this->option('limit') ? (int) $this->option('limit') : null);
        $this->info("Google Calendar: {$ok} tersinkron, {$failed} gagal.");

        return self::SUCCESS;
    }
}
