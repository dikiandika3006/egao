<?php

namespace App\Livewire\Auth;

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
}
