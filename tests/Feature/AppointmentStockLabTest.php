<?php

use App\Enums\VisitStatus;
use App\Livewire\MedicalRecords\Exam;
use App\Livewire\Modules\JanjiTemu;
use App\Livewire\Modules\Kasir;
use App\Livewire\Modules\OrderLab;
use App\Livewire\Modules\Stok;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Visit;
use App\Services\StockService;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', 'MLG')->first();
});

function asUser(string $username, string $role, string|int $branch): User
{
    $user = User::where('username', $username)->firstOrFail();
    test()->actingAs($user);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);

    return $user;
}

function stockOf(string $code, int $branchId): int
{
    return app(StockService::class)->available(Item::where('code', $code)->value('id'), $branchId);
}

// ── Janji temu ──────────────────────────────────────────────────

it('membuka halaman baru tanpa error', function (string $route) {
    asUser('owner', 'owner', 'all');
    $this->get(route($route))->assertOk();
})->with(['janji', 'stok', 'lab']);

it('admin membuat janji temu untuk tanggal mendatang', function () {
    asUser('admin', 'admin', $this->batu->id);
    $patient = Patient::where('medical_record_number', 104)->first();

    Livewire::test(JanjiTemu::class)
        ->call('create')
        ->call('pickPatient', $patient->id)
        ->set('form.date', today()->addDays(4)->toDateString())
        ->set('form.time', '14:30')
        ->set('form.purpose', 'Kontrol behel')
        ->call('save')
        ->assertHasNoErrors();

    $a = Appointment::where('patient_id', $patient->id)->where('purpose', 'Kontrol behel')->first();
    expect($a->branch_id)->toBe($this->batu->id)->and($a->status)->toBe(Appointment::SCHEDULED);
});

it('menolak janji di tanggal yang sudah lewat', function () {
    asUser('admin', 'admin', $this->batu->id);

    Livewire::test(JanjiTemu::class)
        ->call('create')
        ->call('pickPatient', Patient::first()->id)
        ->set('form.date', today()->subDay()->toDateString())
        ->call('save')
        ->assertHasErrors('form.date');
});

it('pasien datang langsung masuk antrean hari ini', function () {
    asUser('admin', 'admin', $this->batu->id);
    $patient = Patient::whereDoesntHave('visits', fn ($q) => $q->whereDate('visit_date', today()))->firstOrFail();
    $a = Appointment::create([
        'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'branch_id' => $this->batu->id,
        'date' => today()->toDateString(), 'time' => '16:00', 'purpose' => 'Kontrol',
    ]);

    Livewire::test(JanjiTemu::class)->call('checkIn', $a->id)->assertHasNoErrors();

    $a->refresh();
    $visit = Visit::find($a->visit_id);
    expect($a->status)->toBe(Appointment::ARRIVED)
        ->and($visit->status)->toBe(VisitStatus::Menunggu)
        ->and($visit->visit_date->isToday())->toBeTrue()
        ->and($visit->queue_number)->toBeGreaterThan(0);
});

it('pasien yang sudah punya kunjungan hari ini tidak mendapat nomor ganda', function () {
    asUser('admin', 'admin', $this->batu->id);
    $a = Appointment::whereDate('date', today())->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))->firstOrFail();
    $existing = Visit::where('patient_id', $a->patient_id)->whereDate('visit_date', today())->count();

    Livewire::test(JanjiTemu::class)->call('checkIn', $a->id);

    expect(Visit::where('patient_id', $a->patient_id)->whereDate('visit_date', today())->count())->toBe($existing)
        ->and($a->fresh()->status)->toBe(Appointment::ARRIVED);
});

it('janji di hari lain tidak bisa dimasukkan antrean', function () {
    asUser('admin', 'admin', $this->batu->id);
    $a = Appointment::whereDate('date', '>', today())->where('branch_id', $this->batu->id)->firstOrFail();

    Livewire::test(JanjiTemu::class)->call('checkIn', $a->id)->assertHasErrors('checkin');
});

it('dokter hanya bisa melihat janji temu, tidak membuat', function () {
    asUser('nadia', 'dokter', $this->batu->id);
    Livewire::test(JanjiTemu::class)->call('create')->assertForbidden();
});

it('rencana evaluasi dokter otomatis menjadi janji kontrol', function () {
    $visit = Visit::whereDate('visit_date', today())->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))->with('doctor.user')->firstOrFail();
    asUser($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('followUp', '2 weeks')
        ->set('allergyConfirmed', true)
        ->call('confirmSend');

    $a = Appointment::where('source_visit_id', $visit->id)->first();
    expect($a)->not->toBeNull()
        ->and($a->source)->toBe('kontrol')
        ->and($a->date->toDateString())->toBe(today()->addWeeks(2)->toDateString())
        ->and($a->doctor_id)->toBe($visit->doctor_id);
});

// ── Stok ────────────────────────────────────────────────────────

it('pembelian menambah stok dan mencatat pengeluaran', function () {
    asUser('admin', 'admin', $this->batu->id);
    $item = Item::where('code', 'PCT500')->first();
    $before = stockOf('PCT500', $this->batu->id);

    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->set('purchase.supplier', 'Apotek Sehat')
        ->set('purchase.lines.0.item_id', (string) $item->id)
        ->set('purchase.lines.0.quantity', 100)
        ->set('purchase.lines.0.unit_cost', 300)
        ->call('savePurchase')
        ->assertHasNoErrors();

    expect(stockOf('PCT500', $this->batu->id))->toBe($before + 100)
        ->and(Expense::where('description', 'like', '[STOK] Apotek Sehat%')->value('amount'))->toBe(30000)
        ->and($item->fresh()->buy_price)->toBe(300);
});

it('alur penuh: resep dokter → kasir lunas → stok berkurang', function () {
    $visit = Visit::whereDate('visit_date', today())->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))->with('doctor.user')->firstOrFail();
    $drug = Item::where('code', 'AMX500')->first();
    $before = stockOf('AMX500', $this->batu->id);

    asUser($visit->doctor->user->username, 'dokter', $this->batu->id);
    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('addPrescription')
        ->set('prescriptions.0.item_id', (string) $drug->id)
        ->assertSet('prescriptions.0.drug_name', 'Amoksisilin 500 mg')
        ->set('prescriptions.0.item_quantity', 10)
        ->set('prescriptions.0.dose', '3×1')
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertHasNoErrors();

    $invoice = $visit->fresh()->invoice()->with('items')->first();
    $line = $invoice->items->firstWhere('item_id', $drug->id);
    expect($line->amount)->toBe(20000)->and($line->doctor_id)->toBeNull()
        ->and($invoice->total)->toBe(320000)
        ->and(stockOf('AMX500', $this->batu->id))->toBe($before); // belum lunas → belum berkurang

    asUser('admin', 'admin', $this->batu->id);
    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->set('pay.amount', $invoice->total)
        ->call('submitPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and(stockOf('AMX500', $this->batu->id))->toBe($before - 10)
        ->and((int) StockMovement::where('invoice_id', $invoice->id)->where('type', 'jual')->sum('quantity'))->toBe(-10);
});

it('kasir menjual barang tambahan dan menolak bila stok kurang', function () {
    asUser('admin', 'admin', $this->batu->id);
    $invoice = Invoice::where('branch_id', $this->batu->id)->where('status', Invoice::STATUS_UNPAID)->firstOrFail();
    $brush = Item::where('code', 'SKG')->first();
    $stock = stockOf('SKG', $this->batu->id);

    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->set('goods.item_id', (string) $brush->id)
        ->set('goods.quantity', $stock + 1)
        ->call('addGoods')
        ->assertHasErrors('goods.quantity')
        ->set('goods.quantity', 2)
        ->call('addGoods')
        ->assertHasNoErrors();

    expect($invoice->fresh()->total)->toBe($invoice->total + 40000);
});

it('bagi hasil dokter tidak menghitung penjualan obat', function () {
    $visit = Visit::whereDate('visit_date', today())->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))->with('doctor.user')->firstOrFail();
    $before = App\Support\Payroll::forMonth($visit->doctor->clinic_id, now())->firstWhere('doctor.id', $visit->doctor_id)['gross'];

    $invoice = $visit->invoice;
    $invoice->items()->create(['item_id' => Item::where('code', 'PCT500')->value('id'), 'name' => 'Parasetamol', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000]);
    $invoice->recalculate();
    $invoice->update(['status' => Invoice::STATUS_PAID, 'paid' => $invoice->total, 'paid_at' => now()]);

    $after = App\Support\Payroll::forMonth($visit->doctor->clinic_id, now())->firstWhere('doctor.id', $visit->doctor_id)['gross'];
    expect($after - $before)->toBe(300000); // hanya Scaling + Konsultasi
});

it('stok opname menyesuaikan stok ke jumlah fisik', function () {
    asUser('admin', 'admin', $this->batu->id);
    $item = Item::where('code', 'WAX')->first();

    Livewire::test(Stok::class)
        ->call('setTab', 'pakai')
        ->set('usage.mode', 'opname')
        ->set('usage.item_id', (string) $item->id)
        ->set('usage.quantity', 7)
        ->set('usage.note', 'Opname akhir bulan')
        ->call('saveUsage')
        ->assertHasNoErrors();

    expect(stockOf('WAX', $this->batu->id))->toBe(7);
});

it('pemakaian tidak boleh melebihi stok', function () {
    asUser('admin', 'admin', $this->batu->id);
    $item = Item::where('code', 'CMP')->first();

    Livewire::test(Stok::class)
        ->call('setTab', 'pakai')
        ->set('usage.item_id', (string) $item->id)
        ->set('usage.quantity', 9999)
        ->call('saveUsage')
        ->assertHasErrors('stock');
});

it('laporan jual-beli stok bisa diekspor', function () {
    asUser('owner', 'owner', 'all');
    Livewire::test(Stok::class)->call('setTab', 'laporan')->assertSee('Laba kotor')->call('export')->assertFileDownloaded();
});

it('owner di "Semua cabang" wajib memilih cabang di form, lalu bisa mencatat', function () {
    asUser('owner', 'owner', 'all');
    $item = Item::where('code', 'PCT500')->first();
    $before = stockOf('PCT500', $this->malang->id);

    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->set('purchase.lines.0.item_id', (string) $item->id)
        ->set('purchase.lines.0.quantity', 20)
        ->set('purchase.lines.0.unit_cost', 250)
        ->call('savePurchase')
        ->assertHasErrors('branchPick')
        ->set('branchPick', (string) $this->malang->id)
        ->call('savePurchase')
        ->assertHasNoErrors();

    expect(stockOf('PCT500', $this->malang->id))->toBe($before + 20);
});

// ── Order lab ───────────────────────────────────────────────────

it('membuat order lab dan membayar lab menjadi pengeluaran Lab gigi', function () {
    asUser('admin', 'admin', $this->batu->id);
    $patient = Patient::where('medical_record_number', 87)->first();

    Livewire::test(OrderLab::class)
        ->call('create')
        ->call('pickPatient', $patient->id)
        ->set('form.lab_name', 'Lab Sinar Dental')
        ->set('form.work', 'Retainer Hawley')
        ->set('form.cost', 450000)
        ->call('save')
        ->assertHasNoErrors();

    $order = LabOrder::where('work', 'Retainer Hawley')->first();
    Livewire::test(OrderLab::class)->call('markPaid', $order->id);

    $order->refresh();
    $expense = Expense::find($order->expense_id);
    expect($order->paid_at)->not->toBeNull()
        ->and($expense->amount)->toBe(450000)
        ->and($expense->category->name)->toBe('Lab gigi')
        ->and($expense->branch_id)->toBe($this->batu->id);

    Livewire::test(OrderLab::class)->call('unmarkPaid', $order->id);
    expect(Expense::find($expense->id))->toBeNull()->and($order->fresh()->paid_at)->toBeNull();
});

it('dokter tidak bisa membuka order lab', function () {
    asUser('nadia', 'dokter', $this->batu->id);
    $this->get(route('lab'))->assertForbidden();
});
