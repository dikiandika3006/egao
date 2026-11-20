<?php

use App\Enums\VisitStatus;
use App\Livewire\Modules\Antrean;
use App\Livewire\Modules\Gaji;
use App\Livewire\Modules\Jadwal;
use App\Livewire\Modules\Kasir;
use App\Livewire\Modules\Pasien;
use App\Livewire\Modules\Pengeluaran;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', 'MLG')->first();
});

function moduleLogin(string $username, string $role, string|int $branch): void
{
    test()->actingAs(User::where('username', $username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

it('membuka semua halaman modul tanpa error', function (string $route) {
    moduleLogin('owner', 'owner', 'all');
    $this->get(route($route))->assertOk()->assertDontSee('sedang disiapkan');
})->with(['antrean', 'pasien', 'kasir', 'jadwal', 'laporan', 'pengeluaran', 'gaji', 'pengaturan']);

it('mendaftarkan pasien baru dengan nomor RM berikutnya', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $next = Patient::nextMedicalRecordNumber(auth()->user()->clinic_id, 'Lina Marlina');

    Livewire::test(Pasien::class)
        ->call('create')
        ->set('patient.name', 'Lina Marlina')
        ->set('patient.birth_date', '1990-02-03')
        ->set('patient.phone', '0812 1111 2222')
        ->call('save')
        ->assertHasNoErrors();

    expect(Patient::where('name', 'Lina Marlina')->first()->medical_record_number)->toBe($next);
});

it('mendaftarkan kunjungan dan menolak pasien yang masih antre', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $patient = Patient::where('medical_record_number', 132)->first(); // Budi, masih menunggu
    $fresh = Patient::whereDoesntHave('visits', fn ($q) => $q->whereDate('visit_date', today()))->first();
    $doctor = Doctor::first();

    Livewire::test(Antrean::class)
        ->call('register', $patient->id)
        ->set('visit.doctor_id', $doctor->id)
        ->set('visit.purpose', 'Konsultasi')
        ->call('save')
        ->assertHasErrors('visit.patient_id');

    Livewire::test(Antrean::class)
        ->call('register', $fresh->id)
        ->set('visit.doctor_id', $doctor->id)
        ->set('visit.purpose', 'Konsultasi')
        ->call('save')
        ->assertHasNoErrors();

    $visit = Visit::where('patient_id', $fresh->id)->whereDate('visit_date', today())->first();
    expect($visit->status)->toBe(VisitStatus::Menunggu)
        ->and($visit->branch_id)->toBe($this->batu->id)
        ->and($visit->queue_number)->toBe(Visit::where(['branch_id' => $this->batu->id, 'doctor_id' => $doctor->id])->whereDate('visit_date', today())->max('queue_number'));
});

it('melunasi tagihan, menyelesaikan kunjungan, dan menerbitkan kwitansi', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $invoice = Invoice::where('branch_id', $this->batu->id)->where('status', Invoice::STATUS_UNPAID)->firstOrFail();

    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->set('pay.method', 'qris')
        ->set('pay.amount', $invoice->total)
        ->call('submitPayment')
        ->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->visit->status)->toBe(VisitStatus::Selesai)
        ->and($invoice->receipts()->count())->toBe(1);

    $this->get(route('kasir.kwitansi', $invoice->receipts()->first()))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('mewajibkan alasan untuk diskon', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $invoice = Invoice::where('branch_id', $this->batu->id)->where('status', Invoice::STATUS_UNPAID)->firstOrFail();

    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->set('pay.discount', 50_000)
        ->call('applyDiscount')
        ->assertHasErrors('pay.discount_reason')
        ->set('pay.discount_reason', 'Pasien lama')
        ->call('applyDiscount')
        ->assertHasNoErrors();

    expect($invoice->fresh()->total)->toBe($invoice->subtotal - 50_000);
});

it('tidak bisa membuka tagihan cabang lain', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $other = Invoice::where('branch_id', $this->malang->id)->where('status', '!=', Invoice::STATUS_DRAFT)->firstOrFail();

    expect(fn () => Livewire::test(Kasir::class)->call('open', $other->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('menolak jadwal rutin yang bentrok di hari yang sama', function () {
    moduleLogin('owner', 'owner', 'all');
    $nadia = Doctor::where('name', 'drg. Nadia Putri')->first(); // Senin di Batu 10–17

    Livewire::test(Jadwal::class)
        ->call('newRoutine', $nadia->id, 1)
        ->set('routine.branch_id', $this->malang->id)
        ->set('routine.starts_at', '15:00')
        ->set('routine.ends_at', '19:00')
        ->call('saveRoutine')
        ->assertHasErrors('routine.starts_at');

    Livewire::test(Jadwal::class)
        ->call('newRoutine', $nadia->id, 1)
        ->set('routine.branch_id', $this->malang->id)
        ->set('routine.starts_at', '18:00')
        ->set('routine.ends_at', '20:00')
        ->call('saveRoutine')
        ->assertHasNoErrors();

    expect(DoctorSchedule::where('doctor_id', $nadia->id)->where('day_of_week', 1)->count())->toBe(2);
});

it('menandai dokter libur tanpa mengubah jadwal rutin', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $nadia = Doctor::where('name', 'drg. Nadia Putri')->with('schedules')->first();
    $before = $nadia->schedules->count();

    Livewire::test(Jadwal::class)
        ->call('openDay', $nadia->id, '2026-11-23')
        ->set('override.type', 'off')
        ->call('saveOverride')
        ->assertHasNoErrors();

    $nadia->load('schedules', 'overrides');
    expect($nadia->scheduleOn(Carbon\Carbon::parse('2026-11-23')))->toBeNull()
        ->and($nadia->schedules->count())->toBe($before)
        ->and(ScheduleOverride::where('doctor_id', $nadia->id)->count())->toBe(1);
});

it('dokter tidak bisa mengubah jadwal', function () {
    moduleLogin('nadia', 'dokter', $this->batu->id);

    Livewire::test(Jadwal::class)->call('newRoutine')->assertForbidden();
});

it('mencatat pengeluaran dan menolak kategori sistem', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $system = ExpenseCategory::where('is_system', true)->first();
    $normal = ExpenseCategory::where('is_system', false)->first();

    Livewire::test(Pengeluaran::class)
        ->call('create')
        ->set('expense.expense_category_id', $system->id)
        ->set('expense.amount', 100_000)
        ->call('save')
        ->assertHasErrors('expense.expense_category_id')
        ->set('expense.expense_category_id', $normal->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Expense::where('expense_category_id', $normal->id)->where('amount', 100_000)->where('branch_id', $this->batu->id)->exists())->toBeTrue();
});

it('menghitung bagi hasil dan mencatatnya ke pengeluaran tanpa dobel', function () {
    moduleLogin('owner', 'owner', 'all');

    $component = Livewire::test(Gaji::class)->call('post');
    $first = Expense::where('description', 'like', '[GJI '.now()->format('Y-m').']%')->sum('amount');
    $component->call('post');
    $second = Expense::where('description', 'like', '[GJI '.now()->format('Y-m').']%')->sum('amount');

    // Dokter (bagi hasil + gaji pokok ± bonus) + karyawan (FR-GJI-05).
    $expected = App\Support\Payroll::forMonth(auth()->user()->clinic_id, now())->sum('total')
        + App\Support\Payroll::employeesForMonth(auth()->user()->clinic_id, now())->sum('total');

    expect((int) $first)->toBe($expected)->and((int) $second)->toBe($expected)->and($expected)->toBeGreaterThan(0);
});

it('admin tidak bisa membuka gaji', function () {
    moduleLogin('admin', 'admin', $this->batu->id);
    $this->get(route('gaji'))->assertForbidden();
});

it('owner di semua cabang memilih cabang saat mendaftarkan kunjungan', function () {
    moduleLogin('owner', 'owner', 'all');
    $fresh = Patient::whereDoesntHave('visits', fn ($q) => $q->whereDate('visit_date', today()))->first();
    $doctor = Doctor::first();

    $component = Livewire::test(Antrean::class)
        ->call('register', $fresh->id)
        ->set('visit.doctor_id', $doctor->id)
        ->set('visit.purpose', 'Konsultasi')
        ->call('save')
        ->assertHasErrors('branchPick')
        ->set('branchPick', (string) $this->malang->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Visit::where('patient_id', $fresh->id)->whereDate('visit_date', today())->value('branch_id'))->toBe($this->malang->id);
});
