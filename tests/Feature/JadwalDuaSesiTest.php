<?php

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Support\BranchContext;
use Carbon\Carbon;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    ScheduleOverride::query()->delete();
    DoctorSchedule::query()->delete();

    $this->dokter = Doctor::where('name', 'drg. Tiara')->firstOrFail();
    foreach ([['17:00', '20:00'], ['10:00', '13:00']] as [$mulai, $selesai]) {
        DoctorSchedule::create([
            'doctor_id' => $this->dokter->id, 'branch_id' => $this->batu->id,
            'day_of_week' => 5, 'starts_at' => $mulai, 'ends_at' => $selesai,
        ]);
    }
    $this->jumat = Carbon::parse('2026-11-27'); // Jumat
});

it('jadwal dua sesi dalam sehari terbaca lengkap dan urut jam', function () {
    $slot = $this->dokter->load('schedules', 'overrides')->scheduleOn($this->jumat);

    expect($slot['hours'])->toBe('10.00–13.00, 17.00–20.00')
        ->and($slot['sessions'])->toHaveCount(2)
        ->and(substr($slot['starts_at'], 0, 5))->toBe('10:00')
        ->and(substr($slot['ends_at'], 0, 5))->toBe('20:00')
        ->and($slot['branch_id'])->toBe($this->batu->id);
});

it('kisi jadwal menampilkan kedua sesi', function () {
    $this->actingAs(User::where('username', 'owner')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'owner',
        BranchContext::SESSION_BRANCH => 'all',
    ]);

    $this->get(route('jadwal', ['minggu' => '2026-11-23']))->assertOk()
        ->assertSeeInOrder(['drg. Tiara', '10.00–13.00', '17.00–20.00']);
});
