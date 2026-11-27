<?php

use App\Livewire\BranchSwitcher;
use App\Models\Doctor;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->doctor = Doctor::whereNotNull('user_id')->firstOrFail();
    $this->actingAs(User::findOrFail($this->doctor->user_id));
});

afterEach(fn () => Carbon::setTestNow());

it('tidak menampilkan slip bulan sebelum dokter tercatat di aplikasi', function () {
    Carbon::setTestNow('2026-11-27 10:00');
    $this->doctor->forceFill(['joined_at' => null, 'created_at' => '2026-11-27 09:00'])->save();

    Livewire::test(BranchSwitcher::class)
        ->assertSee('November 2026')
        ->assertDontSee('Oktober 2026');
});

it('menampilkan bulan lalu bila sudah bergabung sebelumnya', function () {
    Carbon::setTestNow('2026-11-27 10:00');
    $this->doctor->forceFill(['joined_at' => '2026-01-01', 'created_at' => '2026-09-01'])->save();

    Livewire::test(BranchSwitcher::class)
        ->assertSee('Oktober 2026')
        ->assertSee('November 2026');
});
