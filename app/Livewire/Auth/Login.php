<?php

namespace App\Livewire\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Masuk')]
class Login extends Component
{
    #[Validate('required|string|max:50', as: 'username')]
    public string $username = '';

    #[Validate('required', as: 'kata sandi')]
    public string $password = '';

    public bool $remember = false;

    /** PIN reset darurat (hanya terisi saat panel Alt+F7 dipakai). */
    public string $resetPin = '';

    public function login()
    {
        $this->validate();

        $key = Str::lower($this->username).'|'.request()->ip();

        // NFR-SEC-01: batasi percobaan masuk.
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        if (! Auth::attempt(['username' => $this->username, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['username' => 'Username atau kata sandi tidak cocok.']);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return $this->redirectRoute('select-branch', navigate: true);
    }

    /**
     * Reset darurat: set kata sandi akun (sesuai username yang diketik) menjadi "password".
     * Disingkap lewat Alt+F7 di halaman login, tapi hanya jalan bila PIN cocok.
     * Mati total bila LOGIN_RESET_PIN kosong. Keamanan bergantung penuh pada kerahasiaan PIN.
     */
    public function resetPassword()
    {
        $pin = (string) config('auth.reset_pin');

        if ($pin === '') {
            throw ValidationException::withMessages(['resetPin' => 'Fitur reset tidak aktif.']);
        }

        $key = 'login-reset:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'resetPin' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        $this->validate([
            'username' => 'required|string|max:50',
            'resetPin' => 'required|string',
        ], attributes: ['username' => 'username', 'resetPin' => 'PIN']);

        if (! hash_equals($pin, trim($this->resetPin))) {
            RateLimiter::hit($key, 600);
            AuditLog::record('auth.reset_darurat_gagal', null, ['username' => $this->username]);

            throw ValidationException::withMessages(['resetPin' => 'PIN salah.']);
        }

        RateLimiter::clear($key);

        $user = User::where('username', $this->username)->first();

        if (! $user) {
            throw ValidationException::withMessages(['username' => 'Akun tidak ditemukan.']);
        }

        $user->forceFill(['password' => 'password', 'remember_token' => Str::random(60)])->save();
        AuditLog::record('auth.reset_darurat', $user, ['username' => $user->username]);

        $this->reset('resetPin', 'password');
        session()->flash('status', 'Kata sandi akun "'.$user->username.'" direset menjadi: password');
    }
}
