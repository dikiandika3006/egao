<?php

use App\Livewire\ChangePassword;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->user = User::where('username', 'owner')->firstOrFail();
    $this->actingAs($this->user);
});

it('mengganti kata sandi sendiri dan mencatat audit', function () {
    Livewire::test(ChangePassword::class)
        ->dispatch('buka-ubah-sandi')
        ->assertSet('open', true)
        ->set('current_password', 'password')
        ->set('password', 'sandiBaru123')
        ->set('password_confirmation', 'sandiBaru123')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('toast');

    expect(Hash::check('sandiBaru123', $this->user->fresh()->password))->toBeTrue();
    expect(AuditLog::where('action', 'user.password_changed')->where('auditable_id', $this->user->id)->exists())->toBeTrue();
});

it('menolak bila kata sandi lama salah', function () {
    Livewire::test(ChangePassword::class)
        ->set('current_password', 'salah')
        ->set('password', 'sandiBaru123')
        ->set('password_confirmation', 'sandiBaru123')
        ->call('save')
        ->assertHasErrors(['current_password']);

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('menolak konfirmasi berbeda, terlalu pendek, atau sama dengan yang lama', function () {
    Livewire::test(ChangePassword::class)
        ->set('current_password', 'password')
        ->set('password', 'sandiBaru123')
        ->set('password_confirmation', 'lain12345')
        ->call('save')
        ->assertHasErrors(['password'])
        ->set('password', 'pendek')
        ->set('password_confirmation', 'pendek')
        ->call('save')
        ->assertHasErrors(['password'])
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('save')
        ->assertHasErrors(['password']);
});

it('menampilkan tombol ubah kata sandi di menu profil', function () {
    $branch = Branch::first();

    $this->withSession([
        BranchContext::SESSION_ROLE => 'owner',
        BranchContext::SESSION_BRANCH => $branch->id,
    ])->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ubah kata sandi')
        ->assertSeeLivewire(ChangePassword::class);
});
