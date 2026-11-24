<?php

use App\Livewire\MedicalRecords\Exam;
use App\Livewire\Modules\Kasir;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Visit;
use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Settings;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Settings::set('admin_fee', '0');
    Settings::set('admin_max_discount_percent', '20');
    $this->visit = Visit::whereDate('visit_date', today())
        ->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))
        ->with('doctor.user')->firstOrFail();
});

function diskonLogin(Visit $visit, string $role): void
{
    $user = match ($role) {
        'dokter' => $visit->doctor->user,
        'owner' => User::where('username', 'owner')->firstOrFail(),
        default => User::where('username', 'admin')->firstOrFail(),
    };
    test()->actingAs($user);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $visit->branch_id]);
    Access::flush();
}

function kirimKeKasir($exam)
{
    return $exam->call('confirmSend')->call('confirmChecksAndSend');
}

it('dokter memberi diskon beralasan dan tagihan di kasir ikut terpotong', function () {
    diskonLogin($this->visit, 'dokter');
    $exam = Livewire::test(Exam::class, ['visit' => $this->visit])->assertSee('Diskon');
    $subtotal = $exam->instance()->total;

    kirimKeKasir($exam->set('discount', 10_000))->assertHasErrors('discountReason');

    kirimKeKasir($exam->set('discountReason', 'Pasien keluarga karyawan'))->assertHasNoErrors();

    $invoice = $this->visit->fresh()->invoice;
    expect($invoice->status)->toBe(Invoice::STATUS_UNPAID)
        ->and($invoice->discount)->toBe(10_000)
        ->and($invoice->discount_reason)->toBe('Pasien keluarga karyawan')
        ->and($invoice->total)->toBe($subtotal - 10_000);

    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.discount']);
});

it('diskon dokter dibatasi persentase di pengaturan', function () {
    diskonLogin($this->visit, 'dokter');
    $exam = Livewire::test(Exam::class, ['visit' => $this->visit]);
    $tooMuch = (int) floor($exam->instance()->total * 0.2) + 1_000;

    kirimKeKasir($exam->set('discount', $tooMuch)->set('discountReason', 'Coba'))->assertHasErrors('discount');

    expect((int) $this->visit->fresh()->invoice?->discount)->toBe(0);
});

it('admin yang mengisi pemeriksaan tidak bisa mengubah diskon dokter', function () {
    diskonLogin($this->visit, 'dokter');
    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->set('discount', 5_000)->set('discountReason', 'Pasien lama')
        ->call('saveDraft')->assertHasNoErrors();

    diskonLogin($this->visit, 'admin');
    Livewire::test(Exam::class, ['visit' => $this->visit->fresh()])
        ->assertDontSee('Alasan diskon (wajib bila ada diskon)')
        ->assertSee('Diskon dokter')
        ->set('discount', 50_000)->set('discountReason', 'Diubah admin')
        ->call('saveDraft')->assertHasNoErrors();

    $invoice = $this->visit->fresh()->invoice;
    expect($invoice->discount)->toBe(5_000)->and($invoice->discount_reason)->toBe('Pasien lama');
});

it('admin di kasir tidak bisa memberi diskon tetapi tetap bisa menerima pembayaran', function () {
    diskonLogin($this->visit, 'dokter');
    kirimKeKasir(Livewire::test(Exam::class, ['visit' => $this->visit]))->assertHasNoErrors();
    $invoice = $this->visit->fresh()->invoice;

    diskonLogin($this->visit, 'admin');
    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->assertDontSee('Terapkan diskon')
        ->set('pay.discount', 1_000)->set('pay.discount_reason', 'Coba')
        ->call('applyDiscount')
        ->assertForbidden();

    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->call('submitPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->fresh()->discount)->toBe(0)
        ->and($invoice->receipts()->count())->toBe(1);
});

it('owner tetap bisa memberi diskon di kasir', function () {
    diskonLogin($this->visit, 'dokter');
    kirimKeKasir(Livewire::test(Exam::class, ['visit' => $this->visit]))->assertHasNoErrors();
    $invoice = $this->visit->fresh()->invoice;

    diskonLogin($this->visit, 'owner');
    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->assertSee('Terapkan diskon')
        ->set('pay.discount', 20_000)->set('pay.discount_reason', 'Promo ulang tahun')
        ->call('applyDiscount')
        ->assertHasNoErrors();

    expect($invoice->fresh()->total)->toBe($invoice->subtotal - 20_000);
});
