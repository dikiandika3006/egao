<?php

namespace App\Livewire;

use App\Models\AuditLog;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Ubah kata sandi akun sendiri dari menu profil (semua peran). Wajib memasukkan kata sandi lama.
 */
class ChangePassword extends Component
{
    public bool $open = false;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[On('buka-ubah-sandi')]
    public function openForm(): void
    {
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->resetValidation();
        $this->open = true;
    }

    public function closeForm(): void
    {
        $this->reset();
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ], [
            'current_password.current_password' => 'Kata sandi lama salah.',
            'password.different' => 'Kata sandi baru harus berbeda dari kata sandi lama.',
        ], [
            'current_password' => 'kata sandi lama',
            'password' => 'kata sandi baru',
        ]);

        $user = auth()->user();
        $user->update(['password' => $this->password]);
        session()->regenerate();

        AuditLog::record('user.password_changed', $user);

        $this->closeForm();
        $this->dispatch('toast', message: 'Kata sandi berhasil diubah.');
    }

    public function render()
    {
        return view('livewire.change-password');
    }
}
