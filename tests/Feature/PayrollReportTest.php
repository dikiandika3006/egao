<?php

use App\Livewire\Modules\Gaji;
use App\Livewire\Modules\Laporan;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Payroll;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
});

function payrollLogin(string $username, string $role, string|int $branch): User
{
    $user = User::where('username', $username)->firstOrFail();
    test()->actingAs($user);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);

    return $user;
}

// ── Gaji karyawan, bonus, potongan ─────────────────────────────

it('menambah karyawan dan menghitung gaji karyawan (FR-GJI-05)', function () {
    payrollLogin('owner', 'owner', 'all');

    Livewire::test(Gaji::class)
        ->call('setTab', 'karyawan')
        ->call('newEmployee')
        ->set('employee.name', 'Rani Perawat')
        ->set('employee.position', 'Perawat')
        ->set('employee.primary_branch_id', (string) $this->batu->id)
        ->set('employee.base_salary', '2600000')
        ->call('saveEmployee')
        ->assertHasNoErrors()
        ->assertSee('Rani Perawat');

    $row = Payroll::employeesForMonth(auth()->user()->clinic_id, now())->first(fn ($r) => $r['employee']->name === 'Rani Perawat');
    expect($row['total'])->toBe(2600000);
});

it('bonus dan potongan masuk ke total gaji dokter (FR-GJI-04)', function () {
    payrollLogin('owner', 'owner', 'all');
    $doctor = Doctor::where('name', 'drg. Nadia Putri')->first();
    $before = Payroll::forMonth(auth()->user()->clinic_id, now())->first(fn ($r) => $r['doctor']->id === $doctor->id)['total'];

    Livewire::test(Gaji::class)
        ->call('toggle', 'doctor-'.$doctor->id)
        ->set('adjustment.type', 'bonus')->set('adjustment.amount', '300000')->set('adjustment.note', 'Target tercapai')
        ->call('addAdjustment')
        ->assertHasNoErrors()
        ->set('adjustment.type', 'potongan')->set('adjustment.amount', '100000')
        ->call('addAdjustment');

    $after = Payroll::forMonth(auth()->user()->clinic_id, now())->first(fn ($r) => $r['doctor']->id === $doctor->id);

    expect($after['total'])->toBe($before + 200000)
        ->and($after['bonus'])->toBe(300000)
        ->and($after['deduction'])->toBe(100000);
});

it('mencatat gaji karyawan ke pengeluaran cabang utamanya', function () {
    payrollLogin('owner', 'owner', 'all');
    Livewire::test(Gaji::class)->call('post');

    $dewi = Employee::where('name', 'Dewi Anggraini')->first();
    $row = Expense::where('description', 'like', '[GJI '.now()->format('Y-m').'] Dewi Anggraini%')->first();

    expect($row->amount)->toBe(3000000) // 2,8 jt + bonus 200 rb
        ->and($row->branch_id)->toBe($dewi->primary_branch_id);
});

it('bonus tidak bisa ditambahkan untuk klinik lain / baris tidak dikenal', function () {
    payrollLogin('owner', 'owner', 'all');

    Livewire::test(Gaji::class)
        ->call('toggle', 'doctor-999999')
        ->set('adjustment.amount', '1000')
        ->call('addAdjustment')
        ->assertNotFound();

    expect(PayrollAdjustment::where('payee_id', 999999)->exists())->toBeFalse();
});

// ── Slip gaji PDF ───────────────────────────────────────────────

it('owner mencetak slip gaji dokter dan karyawan (FR-GJI-03)', function () {
    payrollLogin('owner', 'owner', 'all');
    $doctor = Doctor::first();
    $employee = Employee::first();

    $this->get(route('gaji.slip', ['type' => 'doctor', 'id' => $doctor->id, 'bulan' => now()->format('Y-m')]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('gaji.slip', ['type' => 'employee', 'id' => $employee->id]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('dokter hanya bisa membuka slip miliknya sendiri', function () {
    $user = payrollLogin('nadia', 'dokter', $this->batu->id);
    $own = Doctor::where('user_id', $user->id)->first();
    $other = Doctor::where('user_id', '!=', $user->id)->first();

    $this->get(route('gaji.slip', ['type' => 'doctor', 'id' => $own->id]))->assertOk();
    $this->get(route('gaji.slip', ['type' => 'doctor', 'id' => $other->id]))->assertForbidden();
});

it('admin melihat slip miliknya, bukan slip dokter', function () {
    $user = payrollLogin('admin', 'admin', $this->batu->id);
    $own = Employee::where('user_id', $user->id)->first();

    $this->get(route('gaji.slip', ['type' => 'employee', 'id' => $own->id]))->assertOk();
    $this->get(route('gaji.slip', ['type' => 'doctor', 'id' => Doctor::first()->id]))->assertForbidden();
});

// ── Laporan pembayaran & pengeluaran ────────────────────────────

it('menampilkan rincian pembayaran per transaksi', function () {
    payrollLogin('owner', 'owner', 'all');

    Livewire::test(Laporan::class)
        ->call('preset', 'hari')
        ->call('setTab', 'pembayaran')
        ->assertSee('Total pemasukan')
        ->assertSee('RM-');
});

it('memfilter pengeluaran per kategori: listrik & air dan lab gigi', function () {
    payrollLogin('owner', 'owner', 'all');
    $lab = ExpenseCategory::where('name', 'Lab gigi')->first();
    $listrik = ExpenseCategory::where('name', 'Listrik dan air')->first();

    Livewire::test(Laporan::class)
        ->call('preset', 'bulan')
        ->call('filterCategory', $lab->id)
        ->assertSet('tab', 'pengeluaran')
        ->assertSee('retainer Hawley')
        ->assertDontSee('Sewa ruko bulanan')
        ->call('filterCategory', $listrik->id)
        ->assertSee('Rp2.150.000'); // Batu 1,2 jt + Malang 950 rb
});

it('mengekspor laporan pembayaran ke Excel', function () {
    payrollLogin('owner', 'owner', 'all');

    Livewire::test(Laporan::class)->call('setTab', 'pembayaran')->call('export')->assertFileDownloaded();
});

it('mengunduh laporan PDF (FR-LAP-06)', function () {
    payrollLogin('owner', 'owner', 'all');

    $this->get(route('laporan.pdf', ['dari' => now()->startOfMonth()->toDateString(), 'sampai' => today()->toDateString()]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('dokter tidak bisa membuka laporan PDF', function () {
    payrollLogin('nadia', 'dokter', $this->batu->id);
    $this->get(route('laporan.pdf'))->assertForbidden();
});
