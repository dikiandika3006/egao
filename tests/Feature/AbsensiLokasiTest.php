<?php

use App\Livewire\Modules\Absensi;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShiftAttendance;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\EmployeeShiftAttendance;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Absensi berbasis lokasi per shift (24 Nov 2026): radius cabang, akurasi GPS, jendela shift,
 * satu sumber kehadiran (dokter → doctor_shift_attendances), lembur asisten per jam penuh.
 */

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    $this->batu->update(['latitude' => -7.8671, 'longitude' => 112.5239, 'attendance_radius_m' => 100, 'operating_hours' => null]);
    $this->doctor = Doctor::whereNotNull('user_id')->with('user')->firstOrFail();
    $this->assistantUser = User::create([
        'clinic_id' => $this->batu->clinic_id, 'name' => 'Asisten Uji', 'username' => 'asisten.uji', 'password' => 'rahasia123',
        'roles' => ['asisten'],
    ]);
    $this->assistant = Employee::create([
        'clinic_id' => $this->batu->clinic_id, 'user_id' => $this->assistantUser->id, 'primary_branch_id' => $this->batu->id,
        'name' => 'Asisten Uji', 'position' => 'Asisten dokter', 'base_salary' => 0,
    ]);
    $this->service = app(AttendanceService::class);
    Carbon::setTestNow('2026-11-23 10:30'); // Senin; jam kerja bawaan 10–13 & 15–20
});

afterEach(fn () => Carbon::setTestNow());

it('dokter absen masuk di dalam radius menjadi tanda hadir shift (dasar uang duduk)', function () {
    $this->service->checkIn($this->doctor->user, $this->batu, 1, -7.8672, 112.5239, 20);

    $row = DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->firstOrFail();
    expect($row->shift)->toBe(1)
        ->and($row->source)->toBe('lokasi')
        ->and($row->distance_m)->toBeLessThan(100)
        ->and($row->check_in_at->format('H:i'))->toBe('10:30');
});

it('menolak absen di luar radius, akurasi buruk, dan absen dobel', function () {
    expect(fn () => $this->service->checkIn($this->doctor->user, $this->batu, 1, -7.8771, 112.5239, 20))
        ->toThrow(ValidationException::class);  // ±1,1 km
    expect(fn () => $this->service->checkIn($this->doctor->user, $this->batu, 1, -7.8671, 112.5239, 500))
        ->toThrow(ValidationException::class);

    $this->service->checkIn($this->doctor->user, $this->batu, 1, -7.8671, 112.5239, 10);
    expect(fn () => $this->service->checkIn($this->doctor->user, $this->batu, 1, -7.8671, 112.5239, 10))
        ->toThrow(ValidationException::class);
});

it('menolak absen di luar jendela shift dan cabang tanpa koordinat', function () {
    Carbon::setTestNow('2026-11-23 08:00'); // > 60 menit sebelum 10:00
    expect(fn () => $this->service->checkIn($this->doctor->user, $this->batu, 1, -7.8671, 112.5239, 10))->toThrow(ValidationException::class);

    Carbon::setTestNow('2026-11-23 10:30');
    $this->batu->update(['latitude' => null, 'longitude' => null]);
    expect(fn () => $this->service->checkIn($this->doctor->user, $this->batu->fresh(), 1, -7.8671, 112.5239, 10))->toThrow(ValidationException::class);
});

it('centang manual Owner dilengkapi jam absen, bukan baris kedua', function () {
    DoctorShiftAttendance::create(['doctor_id' => $this->doctor->id, 'branch_id' => $this->batu->id, 'date' => '2026-11-23', 'shift' => 1]);
    $this->service->checkIn($this->doctor->user, $this->batu, 1, -7.8671, 112.5239, 10);

    expect(DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->count())->toBe(1)
        ->and(DoctorShiftAttendance::first()->check_in_at)->not->toBeNull();
});

it('lembur asisten dihitung per jam penuh setelah akhir jadwal', function () {
    EmployeeSchedule::create(['employee_id' => $this->assistant->id, 'branch_id' => $this->batu->id, 'day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '13:00']);
    $this->service->checkIn($this->assistantUser, $this->batu, 1, -7.8671, 112.5239, 10);

    Carbon::setTestNow('2026-11-23 14:50'); // 1 jam 50 menit lewat → 1 jam
    $this->service->checkOut($this->assistantUser, $this->batu, 1, -7.8671, 112.5239, 10);

    $row = EmployeeShiftAttendance::where('employee_id', $this->assistant->id)->firstOrFail();
    expect($row->overtime_minutes)->toBe(60)
        ->and($row->workedMinutes())->toBe(260);
});

it('absen pulang tanpa absen masuk ditolak; akun tanpa data karyawan ditolak', function () {
    expect(fn () => $this->service->checkOut($this->assistantUser, $this->batu, 1, -7.8671, 112.5239, 10))->toThrow(ValidationException::class);

    $tanpaData = User::create(['clinic_id' => $this->batu->clinic_id, 'name' => 'Tanpa Data', 'username' => 'tanpa.data', 'password' => 'rahasia123', 'roles' => ['admin']]);
    expect(fn () => $this->service->checkIn($tanpaData, $this->batu, 1, -7.8671, 112.5239, 10))->toThrow(ValidationException::class);
});

it('jarak haversine wajar', function () {
    expect(AttendanceService::distance(-7.8671, 112.5239, -7.8771, 112.5239))->toBeGreaterThan(1000)->toBeLessThan(1200);
});

it('halaman Absensi tampil untuk Owner dan koreksi tercatat', function () {
    $row = EmployeeShiftAttendance::create(['employee_id' => $this->assistant->id, 'branch_id' => $this->batu->id, 'date' => '2026-11-23', 'shift' => 1]);

    $this->actingAs(User::where('username', 'owner')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(Absensi::class)
        ->assertSee('Asisten Uji')
        ->call('editRow', 'employee-'.$row->id)
        ->set('edit.check_in', '10:00')
        ->set('edit.check_out', '15:00')
        ->set('edit.overtime_hours', '2')
        ->call('saveRow')
        ->assertHasNoErrors();

    expect($row->fresh()->overtime_minutes)->toBe(120)
        ->and($row->fresh()->workedMinutes())->toBe(300);
});

it('halaman Absensi terbuka untuk dokter dengan tombol absen', function () {
    $this->actingAs($this->doctor->user);
    session([BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $this->batu->id]);

    $this->get(route('absensi'))->assertOk()->assertSee('Absen masuk')->assertSee('Shift Pagi');
});

it('Asisten lain tidak bisa mengoreksi absensi', function () {
    $row = EmployeeShiftAttendance::create(['employee_id' => $this->assistant->id, 'branch_id' => $this->batu->id, 'date' => '2026-11-23', 'shift' => 1]);
    $this->actingAs($this->assistantUser);
    session([BranchContext::SESSION_ROLE => 'asisten', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(Absensi::class)->call('editRow', 'employee-'.$row->id)->assertForbidden();
});
