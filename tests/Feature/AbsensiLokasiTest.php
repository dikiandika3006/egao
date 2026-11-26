<?php

use App\Livewire\Modules\Absensi;
use App\Livewire\Modules\Pengaturan;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShiftAttendance;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\EmployeeShiftAttendance;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\BranchContext;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Absensi satu tombol (25 Nov 2026): check in → clock out; cabang terdekat dalam radius global (bawaan 25 m),
 * shift dari jam, mode uji. Satu sumber kehadiran (dokter → doctor_shift_attendances), lembur asisten per jam penuh.
 */

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    $this->batu->update(['latitude' => -7.8671, 'longitude' => 112.5239, 'operating_hours' => null]);
    $this->malang = Branch::where('code', 'MLG')->firstOrFail();
    $this->malang->update(['latitude' => null, 'longitude' => null, 'operating_hours' => null]);
    $this->doctor = Doctor::whereNotNull('user_id')->with('user')->firstOrFail();
    $this->assistantUser = User::create([
        'clinic_id' => $this->batu->clinic_id, 'name' => 'Asisten Uji', 'username' => 'asisten.uji', 'password' => 'rahasia123',
        'roles' => ['admin'],
    ]);
    $this->assistant = Employee::create([
        'clinic_id' => $this->batu->clinic_id, 'user_id' => $this->assistantUser->id, 'primary_branch_id' => $this->batu->id,
        'name' => 'Asisten Uji', 'position' => 'Asisten dokter', 'base_salary' => 0,
    ]);
    $this->assistantUser->branches()->attach($this->batu->id);
    $this->service = app(AttendanceService::class);
    Carbon::setTestNow('2026-11-23 10:30'); // Senin; jam kerja bawaan 10–13 & 15–20
});

afterEach(fn () => Carbon::setTestNow());

it('check in tanpa pilih cabang/shift: cabang terdekat dalam radius, shift dari jam', function () {
    $this->service->checkIn($this->doctor->user, -7.8672, 112.5239, 20);

    $row = DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->firstOrFail();
    expect($row->shift)->toBe(1)
        ->and($row->branch_id)->toBe($this->batu->id)
        ->and($row->source)->toBe('lokasi')
        ->and($row->distance_m)->toBeLessThanOrEqual(25)
        ->and($row->check_in_at->format('H:i'))->toBe('10:30');
});

it('cabang terdekat yang dipilih bila dua cabang berkoordinat', function () {
    $this->malang->update(['latitude' => -7.9666, 'longitude' => 112.6326]);
    $this->service->checkIn($this->doctor->user, -7.96661, 112.6326, 10);

    expect(DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->value('branch_id'))->toBe($this->malang->id);
});

it('menolak di luar radius 25 m, akurasi buruk, dan check in dobel', function () {
    expect(fn () => $this->service->checkIn($this->doctor->user, -7.8675, 112.5239, 10))
        ->toThrow(ValidationException::class);  // ±44 m
    expect(fn () => $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 80))
        ->toThrow(ValidationException::class);  // akurasi > 50 m
    expect(fn () => $this->service->checkIn($this->doctor->user, null, null, null))
        ->toThrow(ValidationException::class);

    $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 10);
    expect(fn () => $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 10))
        ->toThrow(ValidationException::class);  // masih check in, harus clock out dulu
});

it('radius & akurasi mengikuti setting Pengaturan › Absensi', function () {
    Settings::set('attendance_radius_m', '100');
    $this->service->checkIn($this->doctor->user, -7.8675, 112.5239, 10); // ±44 m kini lolos

    expect(DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->count())->toBe(1);
});

it('shift terdeteksi dari jam: 60 menit sebelum mulai, sore, dan di luar jam ditolak', function () {
    expect($this->service->detectShift($this->batu, Carbon::parse('2026-11-23 09:10')))->toBe(1)
        ->and($this->service->detectShift($this->batu, Carbon::parse('2026-11-23 14:30')))->toBe(2)  // jeda istirahat → shift sore
        ->and($this->service->detectShift($this->batu, Carbon::parse('2026-11-23 16:00')))->toBe(2);

    expect(fn () => $this->service->detectShift($this->batu, Carbon::parse('2026-11-23 08:00')))->toThrow(ValidationException::class);
    expect(fn () => $this->service->detectShift($this->batu, Carbon::parse('2026-11-23 21:00')))->toThrow(ValidationException::class);
});

it('menolak bila koordinat cabang belum diatur', function () {
    $this->batu->update(['latitude' => null, 'longitude' => null]);
    expect(fn () => $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 10))->toThrow(ValidationException::class);
});

it('centang manual Owner dilengkapi jam absen, bukan baris kedua', function () {
    DoctorShiftAttendance::create(['doctor_id' => $this->doctor->id, 'branch_id' => $this->batu->id, 'date' => '2026-11-23', 'shift' => 1]);
    $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 10);

    expect(DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->count())->toBe(1)
        ->and(DoctorShiftAttendance::first()->check_in_at)->not->toBeNull();
});

it('clock out menutup check in; lembur asisten per jam penuh setelah akhir jadwal', function () {
    EmployeeSchedule::create(['employee_id' => $this->assistant->id, 'branch_id' => $this->batu->id, 'day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '13:00']);
    $this->service->checkIn($this->assistantUser, -7.8671, 112.5239, 10);

    Carbon::setTestNow('2026-11-23 14:50'); // 1 jam 50 menit lewat → 1 jam
    $this->service->clockOut($this->assistantUser, -7.8671, 112.5239, 10);

    $row = EmployeeShiftAttendance::where('employee_id', $this->assistant->id)->firstOrFail();
    expect($row->shift)->toBe(1)
        ->and($row->overtime_minutes)->toBe(60)
        ->and($row->workedMinutes())->toBe(260);
});

it('clock out harus dalam radius cabang tempat check in', function () {
    $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 10);
    Carbon::setTestNow('2026-11-23 13:00');

    expect(fn () => $this->service->clockOut($this->doctor->user, -7.8771, 112.5239, 10))->toThrow(ValidationException::class);
});

it('setelah clock out pagi, check in sore tercatat sebagai shift 2', function () {
    $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 10);
    Carbon::setTestNow('2026-11-23 13:05');
    $this->service->clockOut($this->doctor->user, -7.8671, 112.5239, 10);
    Carbon::setTestNow('2026-11-23 14:45');
    $this->service->checkIn($this->doctor->user, -7.8671, 112.5239, 10);

    expect(DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->orderBy('shift')->pluck('shift')->all())->toBe([1, 2]);
});

it('clock out tanpa check in ditolak; akun tanpa data karyawan ditolak', function () {
    expect(fn () => $this->service->clockOut($this->assistantUser, -7.8671, 112.5239, 10))->toThrow(ValidationException::class);

    $tanpaData = User::create(['clinic_id' => $this->batu->clinic_id, 'name' => 'Tanpa Data', 'username' => 'tanpa.data', 'password' => 'rahasia123', 'roles' => ['admin']]);
    expect(fn () => $this->service->checkIn($tanpaData, -7.8671, 112.5239, 10))->toThrow(ValidationException::class);
});

it('mode uji: tanpa lokasi & di luar jam, cabang dari header, sumber "uji"', function () {
    Settings::set('attendance_test_mode', '1');
    Carbon::setTestNow('2026-11-23 22:00');

    expect(fn () => $this->service->checkIn($this->doctor->user, null, null, null))->toThrow(ValidationException::class); // tanpa cabang header

    $row = $this->service->checkIn($this->doctor->user, null, null, null, $this->malang);
    expect($row->source)->toBe('uji')->and($row->branch_id)->toBe($this->malang->id)->and($row->shift)->toBe(2);

    $this->service->clockOut($this->doctor->user, null, null, null);
    expect($row->fresh()->check_out_at)->not->toBeNull();
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

it('halaman Absensi dokter: satu tombol Check in lalu Clock out', function () {
    $this->actingAs($this->doctor->user);
    session([BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(Absensi::class)
        ->assertSee('Check in')->assertDontSee('Clock out')
        ->call('checkIn', -7.8671, 112.5239, 10)
        ->assertHasNoErrors()
        ->assertSee('Clock out');
});

it('tab Pengaturan › Absensi menyimpan radius & mode uji, khusus Owner', function () {
    $this->actingAs(User::where('username', 'owner')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(Pengaturan::class)->call('setTab', 'absensi')
        ->assertSee('Radius lokasi')
        ->set('attendance.radius_m', '40')
        ->set('attendance.test_mode', true)
        ->call('saveAttendance')
        ->assertHasNoErrors();
    expect(Settings::int('attendance_radius_m'))->toBe(40)->and(Settings::bool('attendance_test_mode'))->toBeTrue();

    $this->actingAs(User::where('username', 'admin')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id]);
    Livewire::test(Pengaturan::class)->call('setTab', 'absensi')->assertForbidden();
    Livewire::test(Pengaturan::class)->call('saveAttendance')->assertForbidden();
});

it('Asisten lain tidak bisa mengoreksi absensi', function () {
    $row = EmployeeShiftAttendance::create(['employee_id' => $this->assistant->id, 'branch_id' => $this->batu->id, 'date' => '2026-11-23', 'shift' => 1]);
    $this->actingAs($this->assistantUser);
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(Absensi::class)->call('editRow', 'employee-'.$row->id)->assertForbidden();
});

it('tombol Check in/Clock out di header untuk akun terhubung, tidak untuk akun tak terhubung', function () {
    $this->actingAs($this->doctor->user);
    session([BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(AttendanceButton::class)
        ->assertSee('Check in')
        ->call('checkIn', -7.8671, 112.5239, 10)
        ->assertDispatched('absensi-diperbarui')
        ->assertSee('Clock out');
    Carbon::setTestNow('2026-11-23 13:00');
    Livewire::test(AttendanceButton::class)->call('clockOut', -7.8671, 112.5239, 10)->assertSee('Check in');
    expect(DoctorShiftAttendance::where('doctor_id', $this->doctor->id)->first()->check_out_at)->not->toBeNull();

    // Di luar radius: ditolak sebagai toast, tidak ada absen baru.
    Livewire::test(AttendanceButton::class)->call('checkIn', -7.8771, 112.5239, 10)->assertDispatched('toast')->assertNotDispatched('absensi-diperbarui');

    $tanpaData = User::create(['clinic_id' => $this->batu->clinic_id, 'name' => 'Tanpa Data', 'username' => 'tanpa.data', 'password' => 'rahasia123', 'roles' => ['admin']]);
    $tanpaData->branches()->attach($this->batu->id);
    $this->actingAs($tanpaData);
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id]);
    Livewire::test(AttendanceButton::class)->assertDontSee('Check in');
    $this->get(route('dashboard'))->assertOk();
});
