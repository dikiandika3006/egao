<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dijalankan scheduler tiap menit. Hostinger Git hanya melakukan "git pull" (tanpa SSH, exec() nonaktif),
 * jadi perintah ini yang mendeteksi commit baru lalu: backup → migrate → bersihkan cache → config:cache.
 */
class AfterDeploy extends Command
{
    protected $signature = 'egao:after-deploy {--force : Jalankan juga di luar production (untuk test)}';

    protected $description = 'Setelah deploy (commit baru terdeteksi): backup, migrasi, dan segarkan cache';

    public function handle(): int
    {
        if (! app()->environment('production') && ! $this->option('force')) {
            $this->line('Dilewati: hanya berjalan di server production.');

            return self::SUCCESS;
        }

        $commit = $this->currentCommit();
        if ($commit === null) {
            $this->line('Folder .git tidak ditemukan; tidak ada yang dicek.');

            return self::SUCCESS;
        }

        $stateFile = storage_path('app/private/deployed-commit');
        if (is_file($stateFile) && trim((string) file_get_contents($stateFile)) === $commit) {
            $this->line('Tidak ada perubahan.');

            return self::SUCCESS;
        }

        $short = substr($commit, 0, 7);
        Log::info("After-deploy: commit baru {$short} terdeteksi.");

        try {
            if ($this->hasPendingMigrations()) {
                // Backup dulu; bila gagal, jangan migrasi — dicoba lagi menit berikutnya.
                if (Artisan::call('egao:backup') !== self::SUCCESS) {
                    Log::error('After-deploy: backup gagal, migrasi ditunda.', ['output' => Artisan::output()]);
                    $this->error('Backup gagal, migrasi ditunda.');

                    return self::FAILURE;
                }
                Artisan::call('migrate', ['--force' => true]);
                Log::info('After-deploy: migrasi selesai.', ['output' => trim(Artisan::output())]);
            }

            // Cache rute sengaja tidak dibuat: cache basi setelah deploy membuat halaman baru 404.
            foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear', 'clear-compiled'] as $cmd) {
                Artisan::call($cmd);
            }
            if (app()->environment('production')) {
                Artisan::call('config:cache');
            }
        } catch (Throwable $e) {
            Log::error('After-deploy gagal: '.$e->getMessage());
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_dir(dirname($stateFile))) {
            mkdir(dirname($stateFile), 0775, true);
        }
        file_put_contents($stateFile, $commit);
        Log::info("After-deploy: selesai untuk commit {$short}.");
        $this->info("Selesai untuk commit {$short}.");

        return self::SUCCESS;
    }

    /** Hash commit aktif dari .git/HEAD (cabang biasa, packed-refs, atau HEAD terlepas). */
    private function currentCommit(): ?string
    {
        $git = (string) (config('app.git_dir') ?: base_path('.git'));
        $head = @file_get_contents($git.'/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);

        if (! str_starts_with($head, 'ref:')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null;
        }

        $ref = trim(substr($head, 4));
        $hash = @file_get_contents($git.'/'.$ref);
        if ($hash !== false) {
            return trim($hash);
        }

        foreach (@file($git.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return strtok($line, ' ');
            }
        }

        return null;
    }

    private function hasPendingMigrations(): bool
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return true;
        }
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

        return array_diff(array_keys($files), $migrator->getRepository()->getRan()) !== [];
    }
}
