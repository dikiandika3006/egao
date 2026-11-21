<?php

use App\Livewire\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    RateLimiter::clear('login-reset:127.0.0.1');
});

it('mereset kata sandi menjadi "password" bila PIN benar', function () {
    config(['auth.reset_pin' => 'RAHASIA-123']);
    $user = User::where('username', 'owner')->firstOrFail();
    $user->forceFill(['password' => 'awalnya-lain'])->save();

    Livewire::test(Login::class)
        ->set('username', 'owner')
        ->set('resetPin', 'RAHASIA-123')
        ->call('resetPassword')
        ->assertHasNoErrors();

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    expect(AuditLog::where('action', 'auth.reset_darurat')->count())->toBe(1);
});

it('menolak PIN salah dan tidak mengubah kata sandi', function () {
    config(['auth.reset_pin' => 'RAHASIA-123']);
    $user = User::where('username', 'owner')->firstOrFail();
    $user->forceFill(['password' => 'tetap-aman'])->save();

    Livewire::test(Login::class)
        ->set('username', 'owner')
        ->set('resetPin', 'salah')
        ->call('resetPassword')
        ->assertHasErrors('resetPin');

    expect(Hash::check('tetap-aman', $user->fresh()->password))->toBeTrue();
    expect(AuditLog::where('action', 'auth.reset_darurat_gagal')->count())->toBe(1);
});

it('mati total bila LOGIN_RESET_PIN kosong', function () {
    config(['auth.reset_pin' => '']);
    $user = User::where('username', 'owner')->firstOrFail();
    $user->forceFill(['password' => 'tetap-aman'])->save();

    Livewire::test(Login::class)
        ->set('username', 'owner')
        ->set('resetPin', 'apa pun')
        ->call('resetPassword')
        ->assertHasErrors('resetPin');

    expect(Hash::check('tetap-aman', $user->fresh()->password))->toBeTrue();
});
