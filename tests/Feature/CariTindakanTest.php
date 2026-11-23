<?php

use App\Livewire\Modules\Pengaturan;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Procedure;
use App\Models\User;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->actingAs(User::where('username', 'owner')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => Branch::where('code', 'BTU')->value('id')]);
    $this->clinicId = Clinic::value('id');
});

it('daftar tindakan bisa dicari berdasarkan kode atau nama', function () {
    Procedure::create(['clinic_id' => $this->clinicId, 'code' => 'ZZCARI', 'name' => 'Tindakan Uji Pencarian', 'is_active' => true]);
    $lain = Procedure::where('clinic_id', $this->clinicId)->where('code', '!=', 'ZZCARI')->firstOrFail();

    Livewire::test(Pengaturan::class, ['tab' => 'tindakan'])
        ->set('procedureSearch', 'uji pencarian')
        ->assertSee('Tindakan Uji Pencarian')->assertDontSee($lain->name)
        ->set('procedureSearch', 'zzcari')
        ->assertSee('Tindakan Uji Pencarian')
        ->set('procedureSearch', 'tidak-ada-xyz')
        ->assertSee('Tidak ada tindakan yang cocok.');
});

it('daftar tindakan dipaginasi 25 per halaman', function () {
    foreach (range(1, 30) as $i) {
        Procedure::create(['clinic_id' => $this->clinicId, 'code' => sprintf('PG%02d', $i), 'name' => sprintf('Paginasi %02d', $i), 'is_active' => true]);
    }

    Livewire::test(Pengaturan::class, ['tab' => 'tindakan'])
        ->set('procedureSearch', 'Paginasi')
        ->assertSee('Paginasi 01')->assertDontSee('Paginasi 30')
        ->call('gotoPage', 2)
        ->assertSee('Paginasi 30')->assertDontSee('Paginasi 01');
});

it('pilihan dasar fee di formulir memuat semua tindakan, bukan hanya halaman aktif', function () {
    foreach (range(1, 30) as $i) {
        Procedure::create(['clinic_id' => $this->clinicId, 'code' => sprintf('OP%02d', $i), 'name' => sprintf('Opsi Fee %02d', $i), 'is_active' => true]);
    }

    Livewire::test(Pengaturan::class, ['tab' => 'tindakan'])
        ->set('procedureSearch', 'Opsi Fee 01')
        ->call('editProcedure')
        ->assertSee('Opsi Fee 30');
});
