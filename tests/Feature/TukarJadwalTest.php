<?php

use App\Livewire\Modules\Jadwal;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\EmployeeShiftAttendance;
use App\Models\Patient;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Support\BranchContext;
use Carbon\Carbon;
use Livewire\Livewire;

/*
 * Tukar jadwal dua dokter untuk satu tanggal (drag sel) dan tab Asisten di Jadwal (24 Nov 2026).
 */

beforeEach(function () {
    $this->seed();
    ScheduleOverride::query()->delete();
    DoctorSchedule::query()->delete();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    $this->malang = Branch::where('code', '!=', 'BTU')->firstOrFail();
    $this->a = Doctor::where('name', 'drg. Nadia Putri')->firstOrFail();
    $this->b = Doctor::where('name', 'drg. Arya Wijaya')->firstOrFail();
    $this->date = today()->next(Carbon::MONDAY);
    DoctorSchedule::create(['doctor_id' => $this->a->id, 'branch_id' => $this->batu->id, 'day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '13:00']);
    DoctorSchedule::create(['doctor_id' => $this->b->id, 'branch_id' => $this->malang->id, 'day_of_week' => 1, 'starts_at' => '15:00', 'ends_at' => '20:00']);

    $this->actingAs(User::where('username', 'owner')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all']);
});

it('menukar cabang & jam dua dokter untuk satu tanggal dan memindahkan janji temu', function () {
    $patient = Patient::first();
    $janji = Appointment::create([
        'clinic_id' => $this->batu->clinic_id, 'patient_id' => $patient->id, 'branch_id' => $this->batu->id, 'doctor_id' => $this->a->id,
        'date' => $this->date->toDateString(), 'time' => '10:30', 'status' => Appointment::SCHEDULED,
    ]);

    Livewire::test(Jadwal::class)
        ->call('prepareSwap', $this->a->id, $this->b->id, $this->date->toDateString())
        ->assertSet('form', 'swap')
        ->call('confirmSwap')
        ->assertHasNoErrors()
        ->assertSet('form', null);

    $a = Doctor::with('schedules', 'overrides')->find($this->a->id)->scheduleOn($this->date);
    $b = Doctor::with('schedules', 'overrides')->find($this->b->id)->scheduleOn($this->date);
    expect($a['branch_id'])->toBe($this->malang->id)
        ->and(substr($a['starts_at'], 0, 5))->toBe('15:00')
        ->and($b['branch_id'])->toBe($this->batu->id)
        ->and($janji->fresh()->doctor_id)->toBe($this->b->id);

    // Minggu berikutnya kembali ke jadwal rutin.
    $next = $this->date->copy()->addWeek();
    expect(Doctor::with('schedules', 'overrides')->find($this->a->id)->scheduleOn($next)['branch_id'])->toBe($this->batu->id);
});

it('dokter tanpa jadwal yang ditukar membuat partner libur', function () {
    DoctorSchedule::where('doctor_id', $this->b->id)->delete();

    Livewire::test(Jadwal::class)
        ->call('prepareSwap', $this->a->id, $this->b->id, $this->date->toDateString())
        ->set('swap.move_appointments', false)
        ->call('confirmSwap');

    expect(ScheduleOverride::where('doctor_id', $this->a->id)->value('type'))->toBe('off')
        ->and(ScheduleOverride::where('doctor_id', $this->b->id)->value('branch_id'))->toBe($this->batu->id);
});

it('tanggal lampau dan dokter yang sama ditolak', function () {
    Livewire::test(Jadwal::class)
        ->call('prepareSwap', $this->a->id, $this->b->id, today()->subDay()->toDateString())
        ->assertHasErrors('swap');
    Livewire::test(Jadwal::class)
        ->call('prepareSwap', $this->a->id, $this->a->id, $this->date->toDateString())
        ->assertHasErrors('swap');
});

it('Dokter tidak bisa menukar jadwal', function () {
    $this->actingAs($this->a->user ?? User::where('username', 'owner')->first());
    session([BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(Jadwal::class)
        ->call('prepareSwap', $this->a->id, $this->b->id, $this->date->toDateString())
        ->assertForbidden();
});

it('tab Asisten: jadwal rutin dan centang hadir shift oleh Owner', function () {
    $asisten = Employee::create([
        'clinic_id' => $this->batu->clinic_id, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Jadwal Uji',
        'position' => 'Asisten dokter', 'base_salary' => 0,
    ]);

    Livewire::test(Jadwal::class)
        ->set('team', 'asisten')
        ->call('newAssistantRoutine', $asisten->id, 1)
        ->set('routine.branch_id', $this->batu->id)
        ->call('saveAssistantRoutine')
        ->assertHasNoErrors()
        ->assertSee('Asisten Jadwal Uji')
        ->call('openAssistantDay', $asisten->id, today()->toDateString())
        ->call('toggleAssistantAttendance', 2)
        ->assertSet('attendance', [2]);

    expect($asisten->schedules()->count())->toBe(1)
        ->and(EmployeeShiftAttendance::where('employee_id', $asisten->id)->value('shift'))->toBe(2);

    // Jadwal bentrok ditolak.
    Livewire::test(Jadwal::class)
        ->call('newAssistantRoutine', $asisten->id, 1)
        ->set('routine.branch_id', $this->batu->id)
        ->set('routine.starts_at', '11:00')
        ->call('saveAssistantRoutine')
        ->assertHasErrors('routine.starts_at');
});
