<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Backup database MySQL ke file .sql.gz bertanggal, lalu hapus backup yang lebih lama dari BACKUP_KEEP_DAYS.
 * Dijadwalkan harian di routes/console.php; bisa juga dijalankan manual: php artisan egao:backup
 */
class BackupDatabase extends Command
{
    protected $signature = 'egao:backup {--path= : Folder tujuan (default config backup.path)}';

    protected $description = 'Backup database ke file .sql.gz dan bersihkan backup lama';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'));

        if (($db['driver'] ?? null) !== 'mysql' && ($db['driver'] ?? null) !== 'mariadb') {
            $this->error('Backup otomatis hanya mendukung MySQL/MariaDB.');

            return self::FAILURE;
        }

        $binary = $this->mysqldump();
        if (! $binary) {
            $this->error('mysqldump tidak ditemukan. Isi BACKUP_MYSQLDUMP di .env dengan lokasi mysqldump.');

            return self::FAILURE;
        }

        $dir = rtrim($this->option('path') ?: config('backup.path'), '\\/');
        File::ensureDirectoryExists($dir);

        $name = $db['database'].'-'.now()->format('Y-m-d_His');
        $sql = $dir.DIRECTORY_SEPARATOR.$name.'.sql';
        $gz = $sql.'.gz';

        $process = new Process([
            $binary,
            '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'],
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$sql,
            $db['database'],
        ], null, ['MYSQL_PWD' => (string) $db['password']], null, 600);

        $process->run();

        if (! $process->isSuccessful() || ! is_file($sql) || filesize($sql) === 0) {
            @unlink($sql);
            $this->error('Backup gagal: '.trim($process->getErrorOutput() ?: $process->getOutput()));

            return self::FAILURE;
        }

        $this->compress($sql, $gz);
        @unlink($sql);

        $this->info('Backup tersimpan: '.$gz.' ('.number_format(filesize($gz) / 1024, 0, ',', '.').' KB)');

        $removed = $this->prune($dir, $db['database']);
        if ($removed) {
            $this->line("Menghapus {$removed} backup lebih lama dari ".config('backup.keep_days').' hari.');
        }

        return self::SUCCESS;
    }

    private function mysqldump(): ?string
    {
        if ($configured = config('backup.mysqldump')) {
            return is_file($configured) ? $configured : null;
        }

        if ($found = (new ExecutableFinder)->find('mysqldump')) {
            return $found;
        }

        // Laragon (Windows): ambil versi MySQL terbaru yang terpasang.
        $candidates = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [];
        rsort($candidates);

        return $candidates[0] ?? null;
    }

    private function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        while (! feof($in)) {
            gzwrite($out, fread($in, 1024 * 512));
        }

        fclose($in);
        gzclose($out);
    }

    private function prune(string $dir, string $database): int
    {
        $limit = now()->subDays(max(1, config('backup.keep_days')))->getTimestamp();
        $removed = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.$database.'-*.sql.gz') ?: [] as $file) {
            if (filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
