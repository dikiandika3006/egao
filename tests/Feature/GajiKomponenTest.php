<?php

use App\Livewire\Modules\Gaji;
use App\Livewire\Modules\Kasir;
use App\Livewire\Modules\Pasien;
use App\Livewire\Modules\Pengaturan;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PayrollAdjustment;
use App\Models\PayrollHour;
use App\Models\Procedure;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use App\Support\Payroll;
use App\Support\Settings;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->clinicId = $this->batu->clinic_id;
    $this->month = Carbon::create(2026, 6, 1); // bulan tanpa data contoh
    $this->doctor = Doctor::create(['clinic_id' => $this->clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'drg. Uji Gaji', 'base_salary' => 0]);
    $this->assistant = Employee::create(['clinic_id' => $this->clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Uji', 'position' => 'Asisten dokter', 'base_salary' => 1_000_000]);
    $this->adminStaff = Employee::create(['clinic_id' => $this->clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'Admin Uji', 'position' => 'Admin', 'base_salary' => 2_000_000]);
    $this->patient = Patient::where('clinic_id', $this->clinicId)->first();
});

function gajiLogin(string $username, string $role, string|int $branch): User
{
    $user = User::where('username', $username)->firstOrFail();
    test()->actingAs($user);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);

    return $user;
}

/** Tagihan kunjungan lunas berisi satu tindakan. */
function paidVisit(array $o): Visit
{
    $t = test();
    $visit = Visit::create([
        'patient_id' => $t->patient->id, 'doctor_id' => $t->doctor->id, 'branch_id' => $t->batu->id,
        'assistant_id' => $o['assistant_id'] ?? null, 'visit_date' => $t->month->copy()->addDays(4),
        'queue_number' => random_int(100, 9999), 'status' => 'selesai',
    ]);
    $invoice = Invoice::create([
        'visit_id' => $visit->id, 'branch_id' => $t->batu->id, 'subtotal' => $o['amount'], 'total' => $o['amount'],
        'paid' => $o['amount'], 'status' => $o['status'] ?? Invoice::STATUS_PAID, 'paid_at' => $t->month->copy()->addDays(4)->setTime(10, 0),
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'procedure_id' => $o['procedure_id'], 'doctor_id' => $t->doctor->id,
        'name' => 'Tindakan uji', 'quantity' => $o['quantity'] ?? 1, 'unit_price' => $o['amount'], 'amount' => $o['amount'],
    ]);

    return $visit;
}

function doctorRow(): array
{
    return Payroll::forMonth(test()->clinicId, test()->month)->first(fn ($r) => $r['doctor']->id === test()->doctor->id);
}

function employeeRow(Employee $e): array
{
    return Payroll::employeesForMonth(test()->clinicId, test()->month)->first(fn ($r) => $r['employee']->id === $e->id);
}

it('fee dokter dihitung setelah biaya modal tindakan dan potongan order lab', function () {
    Settings::set('share_percent_global', '35');
    $behel = Procedure::create(['clinic_id' => $this->clinicId, 'code' => 'UJIBHL', 'name' => 'Pasang behel uji', 'cost_amount' => 2_000_000]);
    paidVisit(['procedure_id' => $behel->id, 'amount' => 6_000_000]);
    LabOrder::create([
        'clinic_id' => $this->clinicId, 'branch_id' => $this->batu->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'lab_name' => 'Lab', 'work' => 'Mahkota', 'sent_at' => $this->month->copy()->addDays(2), 'cost' => 1_000_000, 'status' => 'dikirim',
    ]);
    LabOrder::create([ // batal → tidak dipotong
        'clinic_id' => $this->clinicId, 'branch_id' => $this->batu->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'lab_name' => 'Lab', 'work' => 'Batal', 'sent_at' => $this->month->copy()->addDays(3), 'cost' => 5_000_000, 'status' => 'batal',
    ]);

    $row = doctorRow();

    // (6 jt − 2 jt) × 35% = 1,4 jt; lab 1 jt × 35% = 350 rb → fee 1,05 jt
    expect($row['cost'])->toBe(2_000_000)
        ->and($row['lab'])->toBe(1_000_000)
        ->and($row['lab_deduction'])->toBe(350_000)
        ->and($row['share'])->toBe(1_050_000)
        ->and($row['total'])->toBe(1_050_000);
});

it('UD dokter dihitung dari jam hadir yang diisi di menu Gaji', function () {
    Settings::set('doctor_hourly_rate', '50000');
    gajiLogin('owner', 'owner', 'all');

    Livewire::test(Gaji::class)
        ->set('month', $this->month->format('Y-m'))
        ->call('toggle', 'doctor-'.$this->doctor->id)
        ->set('hours.work_hours', '40,5')
        ->call('saveHours')
        ->assertHasNoErrors();

    $row = doctorRow();
    expect($row['hours'])->toBe(40.5)
        ->and($row['ud'])->toBe(2_025_000)
        ->and($row['total'])->toBe(2_025_000);
});

it('fee asisten dari tindakan yang ia dampingi (termasuk belum lunas), plus UD dan lembur', function () {
    Settings::set('assistant_share_percent', '2');
    Settings::set('assistant_hourly_rate', '10000');
    Settings::set('assistant_overtime_rate', '15000');
    $proc = Procedure::where('clinic_id', $this->clinicId)->first();

    paidVisit(['procedure_id' => $proc->id, 'amount' => 1_000_000, 'assistant_id' => $this->assistant->id]);
    paidVisit(['procedure_id' => $proc->id, 'amount' => 500_000]); // tanpa asisten
    paidVisit(['procedure_id' => $proc->id, 'amount' => 800_000, 'assistant_id' => $this->assistant->id, 'status' => Invoice::STATUS_PARTIAL]);
    PayrollHour::create(['clinic_id' => $this->clinicId, 'period' => $this->month, 'payee_type' => 'employee', 'payee_id' => $this->assistant->id, 'work_hours' => 100, 'overtime_hours' => 4]);

    $row = employeeRow($this->assistant);

    expect($row['role'])->toBe(Payroll::ROLE_ASSISTANT)
        ->and($row['assisted'])->toBe(1_800_000)
        ->and($row['assistant_share'])->toBe(36_000)
        ->and($row['ud'])->toBe(1_000_000)
        ->and($row['overtime'])->toBe(60_000)
        ->and($row['total'])->toBe(1_000_000 + 1_000_000 + 36_000 + 60_000);
});

it('admin hanya gaji pokok + lembur; jam hadir tidak menambah UD; THR dihitung bonus', function () {
    Settings::set('admin_overtime_rate', '20000');
    Settings::set('assistant_hourly_rate', '10000');
    PayrollHour::create(['clinic_id' => $this->clinicId, 'period' => $this->month, 'payee_type' => 'employee', 'payee_id' => $this->adminStaff->id, 'work_hours' => 160, 'overtime_hours' => 5]);
    PayrollAdjustment::create(['clinic_id' => $this->clinicId, 'period' => $this->month, 'payee_type' => 'employee', 'payee_id' => $this->adminStaff->id, 'type' => PayrollAdjustment::TYPE_THR, 'amount' => 2_000_000]);
    PayrollAdjustment::create(['clinic_id' => $this->clinicId, 'period' => $this->month, 'payee_type' => 'employee', 'payee_id' => $this->adminStaff->id, 'type' => PayrollAdjustment::TYPE_DEDUCTION, 'amount' => 100_000]);

    $row = employeeRow($this->adminStaff);

    expect($row['role'])->toBe(Payroll::ROLE_ADMIN)
        ->and($row['ud'])->toBe(0)
        ->and($row['overtime'])->toBe(100_000)
        ->and($row['bonus'])->toBe(2_000_000)
        ->and($row['total'])->toBe(2_000_000 + 100_000 + 2_000_000 - 100_000);
});

it('bulan tanpa data & setting kosong: tanpa komponen tambahan', function () {
    $row = employeeRow($this->assistant);
    expect($row['ud'] + $row['overtime'] + $row['assistant_share'])->toBe(0)
        ->and($row['total'])->toBe(1_000_000)
        ->and(doctorRow()['total'])->toBe(0);
});

it('menambah THR dan bonus akhir tahun lewat form Gaji', function () {
    gajiLogin('owner', 'owner', 'all');

    Livewire::test(Gaji::class)
        ->set('month', $this->month->format('Y-m'))
        ->call('setTab', 'karyawan')
        ->call('toggle', 'employee-'.$this->adminStaff->id)
        ->set('adjustment.type', 'thr')->set('adjustment.amount', '1500000')->call('addAdjustment')->assertHasNoErrors()
        ->set('adjustment.type', 'bonus_tahunan')->set('adjustment.amount', '500000')->call('addAdjustment')->assertHasNoErrors()
        ->set('adjustment.type', 'ngawur')->set('adjustment.amount', '1')->call('addAdjustment')->assertHasErrors('adjustment.type');

    expect(employeeRow($this->adminStaff)['bonus'])->toBe(2_000_000);
});

it('catat ke pengeluaran: total tercatat sama dengan total gaji meski ada fee asisten & lab', function () {
    Settings::set('assistant_share_percent', '2');
    $proc = Procedure::where('clinic_id', $this->clinicId)->first();
    paidVisit(['procedure_id' => $proc->id, 'amount' => 1_000_000, 'assistant_id' => $this->assistant->id]);
    gajiLogin('owner', 'owner', 'all');

    Livewire::test(Gaji::class)->set('month', $this->month->format('Y-m'))->call('post');

    $posted = (int) Expense::where('description', 'like', '[GJI 2026-06]%')->sum('amount');
    $expected = Payroll::forMonth($this->clinicId, $this->month)->sum('total') + Payroll::employeesForMonth($this->clinicId, $this->month)->sum('total');
    expect($posted)->toBe($expected)
        ->and((int) Expense::where('description', 'like', '[GJI 2026-06] Asisten Uji%')->sum('amount'))->toBe(1_020_000);
});

it('owner menyimpan setting gaji per peran dan biaya modal tindakan', function () {
    gajiLogin('owner', 'owner', 'all');
    $proc = Procedure::where('clinic_id', $this->clinicId)->first();

    Livewire::test(Pengaturan::class)
        ->call('setTab', 'bagi-hasil')
        ->set('shares.global', '35')
        ->set('shares.roles.doctor_hourly_rate', 50000)
        ->set('shares.roles.assistant_hourly_rate', 15000)
        ->set('shares.roles.assistant_share_percent', '2,5')
        ->set('shares.roles.assistant_overtime_rate', 20000)
        ->set('shares.roles.admin_overtime_rate', 18000)
        ->set('shares.roles.annual_revenue_target', 1_000_000_000)
        ->set("shares.costs.{$proc->id}", 250000)
        ->call('saveShares')
        ->assertHasNoErrors();

    expect(Settings::globalShare())->toBe(35.0)
        ->and(Settings::int('doctor_hourly_rate'))->toBe(50000)
        ->and(Settings::float('assistant_share_percent'))->toBe(2.5)
        ->and(Settings::int('admin_overtime_rate'))->toBe(18000)
        ->and(Settings::int('annual_revenue_target'))->toBe(1_000_000_000)
        ->and($proc->fresh()->cost_amount)->toBe(250000);
});

it('setting gaji menolak nilai negatif', function () {
    gajiLogin('owner', 'owner', 'all');

    Livewire::test(Pengaturan::class)
        ->call('setTab', 'bagi-hasil')
        ->set('shares.roles.assistant_share_percent', '150')
        ->set('shares.roles.admin_overtime_rate', -5)
        ->call('saveShares')
        ->assertHasErrors(['shares.roles.assistant_share_percent', 'shares.roles.admin_overtime_rate']);
});

it('admin tidak bisa membuka tab gaji & bagi hasil maupun mengisi jam', function () {
    gajiLogin('admin', 'admin', $this->batu->id);

    Livewire::test(Pengaturan::class)->call('setTab', 'bagi-hasil')->assertForbidden();
    $this->get(route('gaji'))->assertForbidden();
});

it('kasir memilih asisten pendamping, hanya karyawan berjabatan asisten', function () {
    $proc = Procedure::where('clinic_id', $this->clinicId)->first();
    $visit = paidVisit(['procedure_id' => $proc->id, 'amount' => 100_000]);
    gajiLogin('admin', 'admin', $this->batu->id);

    Livewire::test(Kasir::class)->call('open', $visit->invoice->id)
        ->call('assignAssistant', $this->assistant->id)
        ->assertSee('Asisten Uji');
    expect($visit->fresh()->assistant_id)->toBe($this->assistant->id);

    Livewire::test(Kasir::class)->call('open', $visit->invoice->id)
        ->call('assignAssistant', $this->adminStaff->id)->assertNotFound();
    expect($visit->fresh()->assistant_id)->toBe($this->assistant->id);

    Livewire::test(Kasir::class)->call('open', $visit->invoice->id)->call('assignAssistant', '');
    expect($visit->fresh()->assistant_id)->toBeNull();
});

it('mencatat pasien rujukan drg luar dan memfilternya', function () {
    gajiLogin('admin', 'admin', $this->batu->id);

    Livewire::test(Pasien::class)
        ->call('create')
        ->set('patient.name', 'Pasien Rujukan Uji')
        ->set('patient.birth_date', '1995-05-05')
        ->set('patient.phone', '081234567890')
        ->set('patient.referred_by', 'drg. Budi – Klinik Sehat')
        ->call('save')
        ->assertHasNoErrors()
        ->set('filter', 'rujukan')
        ->assertSee('Pasien Rujukan Uji')
        ->assertSee('Rujukan drg. Budi');

    expect(Patient::where('name', 'Pasien Rujukan Uji')->value('referred_by'))->toBe('drg. Budi – Klinik Sehat');
});

it('slip PDF memuat komponen baru tanpa error', function () {
    Settings::set('assistant_hourly_rate', '10000');
    PayrollHour::create(['clinic_id' => $this->clinicId, 'period' => $this->month, 'payee_type' => 'employee', 'payee_id' => $this->assistant->id, 'work_hours' => 10, 'overtime_hours' => 2]);
    gajiLogin('owner', 'owner', 'all');

    $this->get(route('gaji.slip', ['type' => 'employee', 'id' => $this->assistant->id, 'bulan' => '2026-06']))->assertOk();
    $this->get(route('gaji.slip', ['type' => 'doctor', 'id' => $this->doctor->id, 'bulan' => '2026-06']))->assertOk();
});
