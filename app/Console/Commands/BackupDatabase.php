<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * Backup database ke file .sql.gz (PHP murni, tanpa mysqldump), lalu hapus backup yang lebih lama dari BACKUP_KEEP_DAYS.
 * Dijadwalkan harian di routes/console.php; bisa juga manual: php artisan egao:backup
 * File yang sama tampil di panel Backup & Restore (Bantuan & aturan, khusus Owner + PIN).
 */
class BackupDatabase extends Command
{
    protected $signature = 'egao:backup';

    protected $description = 'Backup database ke file .sql.gz dan bersihkan backup lama';

    public function handle(DatabaseBackupService $backups): int
    {
        try {
            $name = $backups->exclusive(fn () => $backups->backup());
        } catch (\Throwable $e) {
            report($e);
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $size = filesize($backups->path($name));
        $this->info("Backup tersimpan: {$backups->dir()}".DIRECTORY_SEPARATOR."{$name} (".number_format($size / 1024, 0, ',', '.').' KB)');

        if ($removed = $backups->prune(config('backup.keep_days'))) {
            $this->line("Menghapus {$removed} backup lebih lama dari ".config('backup.keep_days').' hari.');
        }

        return self::SUCCESS;
    }
}
