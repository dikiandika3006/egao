<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\DatabaseBackupService;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Panel Backup & Restore database (Bantuan & aturan › Tentang & aturan). Khusus Owner, dibuka dengan PIN
 * (config backup.pin / BACKUP_PIN). Setelah PIN benar, panel terbuka selama backup.unlock_minutes.
 * Pola mengikuti form backup project Djitu; mesin backup di App\Services\DatabaseBackupService.
 */
class BackupPanel extends Component
{
    use WithFileUploads;

    private const SESSION_KEY = 'backup_unlocked_until';

    public string $pin = '';

    /** Nama file dari riwayat yang dipilih untuk restore. */
    public ?string $restoreName = null;

    public $restoreFile = null;

    public string $confirm = '';

    public function mount(): void
    {
        $this->authorizeOwner();
    }

    // ── Kunci PIN ──────────────────────────────────────────────────

    public function unlock(): void
    {
        $this->authorizeOwner();
        $this->resetErrorBag();

        $pin = (string) config('backup.pin');
        abort_if($pin === '', 403, 'Fitur backup belum diaktifkan (BACKUP_PIN kosong).');

        $key = 'backup-pin:'.auth()->id().'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('pin', 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.');

            return;
        }

        if (! hash_equals($pin, trim($this->pin))) {
            RateLimiter::hit($key, 600);
            $this->audit('backup.pin_salah');
            $this->addError('pin', 'PIN salah.');

            return;
        }

        RateLimiter::clear($key);
        session([self::SESSION_KEY => now()->addMinutes(config('backup.unlock_minutes'))->timestamp]);
        $this->pin = '';
        $this->audit('backup.dibuka');
    }

    public function lock(): void
    {
        session()->forget(self::SESSION_KEY);
        $this->reset('restoreName', 'restoreFile', 'confirm');
    }

    // ── Backup ─────────────────────────────────────────────────────

    public function createBackup(DatabaseBackupService $backups)
    {
        $this->ensureUnlocked();

        try {
            $name = $backups->exclusive(fn () => $backups->backup());
        } catch (\Throwable $e) {
            report($e);
            $this->addError('backup', 'Backup gagal: '.$e->getMessage());

            return null;
        }

        $this->audit('backup.dibuat', ['file' => $name]);
        $this->dispatch('toast', message: 'Backup dibuat dan diunduh.');

        return response()->download($backups->path($name));
    }

    public function download(string $name, DatabaseBackupService $backups)
    {
        $this->ensureUnlocked();
        $path = $this->pathOr404($backups, $name);
        $this->audit('backup.diunduh', ['file' => $name]);

        return response()->download($path);
    }

    public function delete(string $name, DatabaseBackupService $backups): void
    {
        $this->ensureUnlocked();
        $this->pathOr404($backups, $name);
        $backups->delete($name);
        $this->audit('backup.dihapus', ['file' => $name]);

        if ($this->restoreName === $name) {
            $this->restoreName = null;
        }
        $this->dispatch('toast', message: 'File backup dihapus.');
    }

    // ── Restore ────────────────────────────────────────────────────

    public function pickRestore(string $name, DatabaseBackupService $backups): void
    {
        $this->ensureUnlocked();
        $this->pathOr404($backups, $name);
        $this->restoreName = $name;
        $this->restoreFile = null;
        $this->resetErrorBag();
    }

    public function updatedRestoreFile(): void
    {
        $this->restoreName = null;
    }

    public function restore(DatabaseBackupService $backups)
    {
        $this->ensureUnlocked();
        $this->resetErrorBag();

        if ($this->confirm !== 'RESTORE') {
            $this->addError('confirm', 'Ketik RESTORE (huruf besar) untuk konfirmasi.');

            return null;
        }

        // Semua pemeriksaan file dilakukan sebelum data apa pun diubah.
        try {
            if ($this->restoreFile) {
                $original = $this->restoreFile->getClientOriginalName();
                if (! preg_match('/\.sql(\.gz)?$/i', $original)) {
                    throw new \RuntimeException('File harus berformat .sql atau .sql.gz.');
                }
                $path = $this->restoreFile->getRealPath();
                $source = 'upload: '.$original;
            } elseif ($this->restoreName) {
                $path = $backups->path($this->restoreName);
                $source = $this->restoreName;
            } else {
                throw new \RuntimeException('Pilih file dari riwayat atau upload file .sql / .sql.gz.');
            }
            $backups->validate($path);
        } catch (\RuntimeException $e) {
            $this->addError('restore', $e->getMessage().' Database tidak diubah.');

            return null;
        }

        $user = auth()->user();
        Log::warning('Restore database dimulai', ['user_id' => $user->id, 'user' => $user->username, 'source' => $source, 'ip' => request()->ip()]);

        try {
            $result = $backups->exclusive(function () use ($backups, $path) {
                // Maintenance dulu: tidak ada transaksi baru yang lolos dari backup pengaman di bawah.
                Artisan::call('down', ['--retry' => 30]);
                try {
                    $preRestore = $backups->backup('_pre-restore');
                    $statements = $backups->restore($path);

                    // Koneksi baru, lalu naikkan skema ke versi aplikasi saat ini
                    // (backup lama bisa belum punya kolom/tabel terbaru).
                    DB::purge();
                    DB::reconnect();
                    Artisan::call('migrate', ['--force' => true]);
                } finally {
                    Artisan::call('up');
                }

                return compact('preRestore', 'statements');
            });
        } catch (\Throwable $e) {
            report($e);
            Log::error('Restore database GAGAL', ['source' => $source, 'error' => $e->getMessage()]);
            $this->addError('restore', 'Restore gagal: '.$e->getMessage().' Bila data rusak, restore ulang dari file "_pre-restore" terbaru di riwayat.');

            return null;
        }

        Log::warning('Restore database selesai', ['source' => $source] + $result);
        rescue(fn () => AuditLog::record('backup.restore', null, ['sumber' => $source] + $result), report: false);

        // Data akun bisa berbeda dengan isi backup: semua pengguna (termasuk Anda) login ulang.
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', "Restore selesai ({$result['statements']} perintah). Backup sebelum restore: {$result['preRestore']}. Silakan login ulang.");

        return $this->redirectRoute('login');
    }

    // ── Bantuan ────────────────────────────────────────────────────

    public function isUnlocked(): bool
    {
        return (string) config('backup.pin') !== '' && session(self::SESSION_KEY, 0) >= now()->timestamp;
    }

    private function authorizeOwner(): void
    {
        abort_unless(app(BranchContext::class)->is(Role::Owner), 403);
    }

    private function ensureUnlocked(): void
    {
        $this->authorizeOwner();
        abort_unless($this->isUnlocked(), 403, 'Sesi panel backup sudah berakhir. Masukkan PIN lagi.');
    }

    private function pathOr404(DatabaseBackupService $backups, string $name): string
    {
        try {
            return $backups->path($name);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    private function audit(string $action, array $data = []): void
    {
        AuditLog::record($action, null, $data);
        Log::warning("Backup database: {$action}", $data + ['user_id' => auth()->id(), 'ip' => request()->ip()]);
    }

    public function render(DatabaseBackupService $backups)
    {
        $unlocked = $this->isUnlocked();

        return view('livewire.backup-panel', [
            'enabled' => (string) config('backup.pin') !== '',
            'unlocked' => $unlocked,
            'until' => $unlocked ? \Carbon\Carbon::createFromTimestamp(session(self::SESSION_KEY)) : null,
            'backups' => $unlocked ? $backups->list() : [],
            'databaseSize' => $unlocked ? $backups->databaseSize() : 0,
        ]);
    }
}
