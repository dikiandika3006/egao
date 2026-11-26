<?php

use App\Livewire\Modules\Dokter;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\User;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
});

function actingForDoctors(string $username, string $role, string|int $branch)
{
    return test()->actingAs(User::where('username', $username)->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => $role,
        BranchContext::SESSION_BRANCH => $branch,
    ]);
}

it('menampilkan data dokter beserta tanggal bergabung', function () {
    actingForDoctors('owner', 'owner', 'all')
        ->get(route('dokter'))
        ->assertOk()
        ->assertSee('drg. Nadia Putri')
        ->assertSee('1 Maret 2021');
});

it('bawaan: hanya owner yang membuka data dokter (Admin/Asisten & dokter ditolak)', function () {
    actingForDoctors('owner', 'owner', $this->batu->id)->get(route('dokter'))->assertOk();
    actingForDoctors('admin', 'admin', $this->batu->id)->get(route('dokter'))->assertForbidden();
    actingForDoctors('nadia', 'dokter', $this->batu->id)->get(route('dokter'))->assertForbidden();
});

it('menambah dokter baru dengan tanggal bergabung', function () {
    actingForDoctors('owner', 'owner', $this->batu->id);

    Livewire::test(Dokter::class)
        ->call('create')
        ->set('name', 'drg. Bima Santoso')
        ->set('joined_at', '2024-02-10')
        ->set('primary_branch_id', (string) $this->batu->id)
        ->call('save')
        ->assertHasNoErrors();

    $doctor = Doctor::where('name', 'drg. Bima Santoso')->first();
    expect($doctor->joined_at->toDateString())->toBe('2024-02-10')
        ->and($doctor->primary_branch_id)->toBe($this->batu->id);
});

it('menolak tanggal bergabung kosong atau di masa depan', function () {
    actingForDoctors('owner', 'owner', 'all');

    Livewire::test(Dokter::class)
        ->call('create')
        ->set('name', 'drg. Uji')
        ->set('joined_at', '')
        ->call('save')
        ->assertHasErrors(['joined_at' => 'required'])
        ->set('joined_at', today()->addDay()->toDateString())
        ->call('save')
        ->assertHasErrors(['joined_at' => 'before_or_equal']);
});

it('mengubah dan menonaktifkan dokter', function () {
    actingForDoctors('owner', 'owner', 'all');
    $doctor = Doctor::where('name', 'drg. Sinta Dewi')->first();

    Livewire::test(Dokter::class)
        ->call('edit', $doctor->id)
        ->assertSet('joined_at', '2025-01-06')
        ->set('joined_at', '2024-12-01')
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    $doctor->refresh();
    expect($doctor->joined_at->toDateString())->toBe('2024-12-01')
        ->and($doctor->is_active)->toBeFalse();
});

// ── Akun login dokter (Owner yang juga praktik, dokter baru) ─────────────

it('owner menghubungkan dokter ke akun yang sudah ada dan akun itu mendapat peran dokter', function () {
    actingForDoctors('owner', 'owner', 'all');
    $admin = User::where('username', 'admin')->first();

    Livewire::test(Dokter::class)
        ->call('create')
        ->set('name', 'drg. Tika')
        ->set('joined_at', today()->toDateString())
        ->set('account', (string) $admin->id)
        ->call('save')
        ->assertHasNoErrors();

    $admin->refresh();
    expect(Doctor::where('name', 'drg. Tika')->value('user_id'))->toBe($admin->id)
        ->and($admin->roles)->toContain('dokter')->toContain('admin')
        ->and($admin->branches()->count())->toBe(Branch::where('clinic_id', $admin->clinic_id)->count());
});

it('owner membuat akun baru untuk dokter', function () {
    actingForDoctors('owner', 'owner', 'all');

    Livewire::test(Dokter::class)
        ->call('create')
        ->set('name', 'drg. Baru')
        ->set('joined_at', today()->toDateString())
        ->set('account', 'new')
        ->set('new_username', 'Bad Name')
        ->set('new_password', '123')
        ->call('save')
        ->assertHasErrors(['new_username', 'new_password'])
        ->set('new_username', 'drgbaru')
        ->set('new_password', 'rahasia123')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('username', 'drgbaru')->first();
    expect($user->roles)->toBe(['dokter'])
        ->and(Doctor::where('name', 'drg. Baru')->value('user_id'))->toBe($user->id)
        ->and($user->branches()->count())->toBeGreaterThan(0);
});

it('akun yang sudah tertaut ke dokter lain ditolak', function () {
    actingForDoctors('owner', 'owner', 'all');
    $nadiaUser = User::where('username', 'nadia')->first();

    Livewire::test(Dokter::class)
        ->call('create')
        ->set('name', 'drg. Lain')
        ->set('joined_at', today()->toDateString())
        ->set('account', (string) $nadiaUser->id)
        ->call('save')
        ->assertHasErrors('account');
});

it('admin tidak bisa membuat atau mengambil akun lewat Data dokter', function () {
    // Owner membuka menu Data dokter untuk Admin/Asisten lewat Hak akses peran.
    $matrix = \App\Support\Access::matrix();
    $matrix['admin']['dokter'] = ['lihat' => true, 'edit' => true, 'keuangan' => true];
    \App\Support\Access::save($matrix);
    actingForDoctors('admin', 'admin', Branch::where('code', 'BTU')->value('id'));
    $owner = User::where('username', 'owner')->first();

    Livewire::test(Dokter::class)
        ->assertDontSee('Akun login')
        ->call('create')
        ->set('name', 'drg. Coba')
        ->set('joined_at', today()->toDateString())
        ->set('account', (string) $owner->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Doctor::where('name', 'drg. Coba')->value('user_id'))->toBeNull();
});

it('owner yang juga dokter tercatat di gaji dan bisa membuka slipnya sendiri', function () {
    $owner = User::where('username', 'owner')->first();
    $tiara = Doctor::where('user_id', $owner->id)->first();

    expect($tiara)->not->toBeNull()
        ->and(App\Support\Payroll::forMonth($owner->clinic_id, now())->contains(fn ($r) => $r['doctor']->id === $tiara->id))->toBeTrue();

    actingForDoctors('owner', 'dokter', Branch::where('code', 'BTU')->value('id'))
        ->get(route('gaji.slip', ['type' => 'doctor', 'id' => $tiara->id]))
        ->assertOk();
});
