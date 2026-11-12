<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Membuat akun Owner pertama, atau mengganti kata sandi akun yang sudah ada (mis. akun contoh berkata sandi "password").
 * Kata sandi dibuat acak dan hanya ditampilkan sekali.
 *
 *   php artisan egao:owner                 → buat Owner baru (ditanya username & nama)
 *   php artisan egao:owner owner --reset   → ganti kata sandi akun "owner"
 */
class CreateOwner extends Command
{
    protected $signature = 'egao:owner {username? : Username akun} {--name= : Nama tampilan} {--reset : Hanya ganti kata sandi akun yang sudah ada}';

    protected $description = 'Buat akun Owner pertama atau ganti kata sandi akun dengan kata sandi acak yang kuat';

    public function handle(): int
    {
        $username = Str::lower(trim($this->argument('username') ?: $this->ask('Username (huruf kecil, angka, titik, strip)', 'owner')));

        if (! preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            $this->error('Username hanya huruf kecil, angka, titik, strip (3–50 karakter).');

            return self::FAILURE;
        }

        $password = Str::password(14, symbols: false);
        $user = User::where('username', $username)->first();

        if ($this->option('reset')) {
            if (! $user) {
                $this->error("Akun \"{$username}\" tidak ditemukan.");

                return self::FAILURE;
            }

            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $this->info("Kata sandi akun \"{$username}\" sudah diganti.");
            $this->printPassword($username, $password);

            return self::SUCCESS;
        }

        if ($user) {
            $this->error("Username \"{$username}\" sudah dipakai. Pakai --reset untuk mengganti kata sandinya.");

            return self::FAILURE;
        }

        $clinic = Clinic::first();
        if (! $clinic) {
            $this->error('Data klinik belum ada. Jalankan dulu: php artisan db:seed --class=ProductionSeeder');

            return self::FAILURE;
        }

        $user = User::create([
            'clinic_id' => $clinic->id,
            'name' => $this->option('name') ?: $this->ask('Nama tampilan', 'Owner'),
            'username' => $username,
            'password' => $password,
            'roles' => [Role::Owner->value, Role::Admin->value],
            'is_active' => true,
        ]);
        $user->branches()->sync(Branch::where('clinic_id', $clinic->id)->pluck('id'));

        $this->info("Akun Owner \"{$username}\" dibuat.");
        $this->printPassword($username, $password);

        return self::SUCCESS;
    }

    private function printPassword(string $username, string $password): void
    {
        $this->newLine();
        $this->line("  Username   : <options=bold>{$username}</>");
        $this->line("  Kata sandi : <options=bold>{$password}</>");
        $this->newLine();
        $this->warn('Catat sekarang — kata sandi ini tidak akan ditampilkan lagi. Ganti setelah login bila perlu.');
    }
}
