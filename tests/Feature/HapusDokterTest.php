<?php

use App\Livewire\Modules\Dokter;
use App\Livewire\Modules\Jadwal;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\PayrollAdjustment;
use App\Models\PayrollHour;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->clinicId = $this->batu->clinic_id;
});

function masukDokter(string $username, string $role, string|int $branch): void
{
    test()->actingAs(User::where('username', $username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

function dokterBaru(array $o = []): Doctor
{
    return Doctor::create($o + [
        'clinic_id' => test()->clinicId, 'primary_branch_id' => test()->batu->id, 'name' => 'drg. Salah Input', 'joined_at' => today(),
    ]);
}

it('dokter tanpa transaksi bisa dihapus beserta jadwalnya, tercatat di audit', function () {
    masukDokter('owner', 'owner', 'all');
    $doctor = dokterBaru();
    DoctorSchedule::create(['doctor_id' => $doctor->id, 'branch_id' => $this->batu->id, 'day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '17:00']);

    Livewire::test(Dokter::class)
        ->assertSeeHtml('deleteDoctor('.$doctor->id.')')
        ->call('deleteDoctor', $doctor->id);

    expect(Doctor::find($doctor->id))->toBeNull()
        ->and(DoctorSchedule::where('doctor_id', $doctor->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'doctor.deleted')->exists())->toBeTrue();
});

it('dokter yang sudah punya kunjungan tidak bisa dihapus dan tombolnya tidak tampil', function () {
    masukDokter('owner', 'owner', 'all');
    $used = Doctor::where('clinic_id', $this->clinicId)->whereHas('visits')->firstOrFail();

    Livewire::test(Dokter::class)
        ->assertDontSeeHtml('deleteDoctor('.$used->id.')')
        ->call('deleteDoctor', $used->id);

    expect(Doctor::find($used->id))->not->toBeNull();
});

it('data gaji dokter juga mencegah penghapusan', function () {
    masukDokter('owner', 'owner', 'all');
    $a = dokterBaru(['name' => 'drg. Ada Bonus']);
    $b = dokterBaru(['name' => 'drg. Ada Jam']);
    PayrollAdjustment::create(['clinic_id' => $this->clinicId, 'period' => '2026-06-01', 'payee_type' => PayrollAdjustment::PAYEE_DOCTOR, 'payee_id' => $a->id, 'type' => PayrollAdjustment::TYPE_DEDUCTION, 'amount' => 1000, 'note' => 'uji']);
    PayrollHour::create(['clinic_id' => $this->clinicId, 'period' => '2026-06-01', 'payee_type' => PayrollAdjustment::PAYEE_DOCTOR, 'payee_id' => $b->id, 'work_hours' => 2, 'overtime_hours' => 0]);

    Livewire::test(Dokter::class)->call('deleteDoctor', $a->id)->call('deleteDoctor', $b->id);

    expect(Doctor::whereKey([$a->id, $b->id])->count())->toBe(2);
});

it('dokter lain klinik tidak bisa dihapus', function () {
    masukDokter('owner', 'owner', 'all');
    $other = \App\Models\Clinic::create(['name' => 'Klinik Lain']);
    $doctor = Doctor::create(['clinic_id' => $other->id, 'name' => 'drg. Klinik Lain']);

    expect(fn () => Livewire::test(Dokter::class)->call('deleteDoctor', $doctor->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(Doctor::find($doctor->id))->not->toBeNull();
});

it('dokter nonaktif tidak tampil di jadwal dan ditolak saat dijadwalkan atau jadi pengganti', function () {
    masukDokter('owner', 'owner', 'all');
    $inactive = dokterBaru(['name' => 'drg. Sudah Keluar', 'is_active' => false]);
    $active = Doctor::where('clinic_id', $this->clinicId)->where('is_active', true)->firstOrFail();

    Livewire::test(Jadwal::class)
        ->assertDontSee('drg. Sudah Keluar')
        ->call('newRoutine', $inactive->id)
        ->call('saveRoutine')
        ->assertHasErrors('routine.doctor_id')
        ->call('openDay', $active->id, today()->toDateString())
        ->set('override.type', ScheduleOverride::TYPE_OFF)
        ->set('override.substitute_id', $inactive->id)
        ->call('saveOverride')
        ->assertHasErrors('override.substitute_id');

    expect(DoctorSchedule::where('doctor_id', $inactive->id)->exists())->toBeFalse();
});
