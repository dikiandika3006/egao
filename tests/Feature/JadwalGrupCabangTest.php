<?php

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Support\BranchContext;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    $this->malang = Branch::where('code', 'MLG')->firstOrFail();
    ScheduleOverride::query()->delete();
    DoctorSchedule::query()->delete();

    $jadwal = fn (string $nama, Branch $cabang, int $hari) => DoctorSchedule::create([
        'doctor_id' => Doctor::where('name', $nama)->value('id'), 'branch_id' => $cabang->id,
        'day_of_week' => $hari, 'starts_at' => '10:00', 'ends_at' => '13:00',
    ]);

    $jadwal('drg. Nadia Putri', $this->batu, 1);      // hanya Batu
    $jadwal('drg. Arya Wijaya', $this->malang, 2);    // hanya Malang
    $jadwal('drg. Sinta Dewi', $this->batu, 3);       // dua cabang → perbatasan
    $jadwal('drg. Sinta Dewi', $this->malang, 4);
    // drg. Tiara: tanpa jadwal, cabang utama Batu.

    $this->actingAs(User::where('username', 'owner')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'owner',
        BranchContext::SESSION_BRANCH => 'all',
    ]);
});

it('mengelompokkan kisi jadwal: Batu di atas, dokter dua cabang di perbatasan, Malang di bawah', function () {
    $this->get(route('jadwal'))->assertOk()->assertSeeInOrder([
        'Cabang Batu', 'drg. Nadia Putri', 'drg. Tiara',
        'Batu &amp; Malang', 'drg. Sinta Dewi',
        'Cabang Malang', 'drg. Arya Wijaya',
        'Jadwal rutin mingguan',
    ], false);
});

it('dokter tanpa jadwal tetap di blok cabang utama, di bagian bawah', function () {
    Doctor::where('name', 'drg. Tiara')->update(['name' => 'drg. Aaa Tanpa Jadwal']);

    $this->get(route('jadwal'))->assertOk()->assertSeeInOrder([
        'Cabang Batu', 'drg. Nadia Putri', 'drg. Aaa Tanpa Jadwal', 'Batu &amp; Malang',
    ], false);
});
