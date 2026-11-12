<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\Hash;

it('ProductionSeeder membuat data master tanpa data contoh', function () {
    $this->seed(ProductionSeeder::class);

    expect(Clinic::count())->toBe(1)
        ->and(Clinic::first()->branches()->count())->toBe(2)
        ->and(Procedure::count())->toBeGreaterThan(0)
        ->and(User::count())->toBe(0)
        ->and(Patient::count())->toBe(0);

    // Aman dijalankan ulang.
    $this->seed(ProductionSeeder::class);
    expect(Clinic::count())->toBe(1);
});

it('egao:owner membuat akun owner dengan kata sandi acak', function () {
    $this->seed(ProductionSeeder::class);

    $this->artisan('egao:owner', ['username' => 'tiara', '--name' => 'Tiara'])
        ->expectsOutputToContain('Akun Owner "tiara" dibuat.')
        ->assertSuccessful();

    $user = User::where('username', 'tiara')->first();
    expect($user->roles)->toContain('owner')
        ->and($user->branches()->count())->toBe(2)
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

it('egao:owner --reset mengganti kata sandi contoh', function () {
    $this->seed();
    expect(Hash::check('password', User::where('username', 'admin')->first()->password))->toBeTrue();

    $this->artisan('egao:owner', ['username' => 'admin', '--reset' => true])->assertSuccessful();

    expect(Hash::check('password', User::where('username', 'admin')->first()->password))->toBeFalse();
});

it('egao:owner menolak username yang sudah dipakai', function () {
    $this->seed();

    $this->artisan('egao:owner', ['username' => 'owner', '--name' => 'X'])->assertFailed();
});
