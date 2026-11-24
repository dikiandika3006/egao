<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeShiftAttendance;
use App\Models\PayrollHour;
use App\Support\Payroll;
use App\Support\Settings;
use Carbon\Carbon;

/*
 * Gaji asisten (24 Nov 2026): upah per shift hadir (pagi/sore, bisa per asisten), lembur dari absensi,
 * bulan tanpa absensi memakai jam manual (cara lama).
 */

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    $this->clinicId = $this->batu->clinic_id;
    Settings::set('assistant_morning_rate', '15000');
    Settings::set('assistant_evening_rate', '20000');
    Settings::set('assistant_overtime_rate', '5000');
    Settings::set('assistant_hourly_rate', '10000');
    $this->asisten = Employee::create([
        'clinic_id' => $this->clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Gaji Uji',
        'position' => 'Asisten dokter', 'base_salary' => 0,
    ]);
});

function hadirAsisten(string $date, int $shift, int $overtimeMinutes = 0): void
{
    EmployeeShiftAttendance::create([
        'employee_id' => test()->asisten->id, 'branch_id' => test()->batu->id, 'date' => $date, 'shift' => $shift,
        'check_in_at' => $date.' 10:00', 'check_out_at' => $date.' 13:00', 'overtime_minutes' => $overtimeMinutes,
    ]);
}

function gajiAsisten(): array
{
    return Payroll::employeesForMonth(test()->clinicId, Carbon::parse('2026-05-01'))
        ->first(fn ($r) => $r['employee']->id === test()->asisten->id);
}

it('upah shift pagi & sore plus lembur dari absensi', function () {
    hadirAsisten('2026-05-04', 1);
    hadirAsisten('2026-05-05', 1, 120);
    hadirAsisten('2026-05-05', 2);

    $row = gajiAsisten();
    expect($row['from_attendance'])->toBeTrue()
        ->and($row['shift_morning'])->toBe(2)
        ->and($row['shift_evening'])->toBe(1)
        ->and($row['ud'])->toBe(2 * 15000 + 20000)
        ->and($row['overtime_hours'])->toEqual(2.0)
        ->and($row['overtime'])->toBe(10000)
        ->and($row['total'])->toBe(50000 + 10000);
});

it('tarif shift per asisten mengalahkan tarif global', function () {
    $this->asisten->update(['morning_shift_rate' => 18000]);
    hadirAsisten('2026-05-04', 1);
    hadirAsisten('2026-05-04', 2);

    expect(gajiAsisten()['ud'])->toBe(18000 + 20000);
});

it('bulan tanpa absensi tetap memakai jam manual, tanpa hitung ganda', function () {
    PayrollHour::create(['clinic_id' => $this->clinicId, 'period' => '2026-05-01', 'payee_type' => 'employee',
        'payee_id' => $this->asisten->id, 'work_hours' => 10, 'overtime_hours' => 3]);

    $row = gajiAsisten();
    expect($row['from_attendance'])->toBeFalse()
        ->and($row['ud'])->toBe(100000)
        ->and($row['overtime'])->toBe(15000);

    // Begitu ada absensi, jam manual tidak dipakai lagi.
    hadirAsisten('2026-05-04', 1);
    $row = gajiAsisten();
    expect($row['ud'])->toBe(15000)->and($row['overtime'])->toBe(0);
});
