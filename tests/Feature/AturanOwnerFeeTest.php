<?php

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShiftAttendance;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use App\Models\Visit;
use App\Services\ExamService;
use App\Services\StockService;
use App\Support\BranchContext;
use App\Support\Payroll;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/*
 * Aturan Owner 24 Nov 2026: fee dari tindakan (bukan uang masuk), diskon proporsional, bahan resep dipotong sebelum %,
 * uang duduk per shift, fee asisten nominal, biaya admin sekali, tarif terkunci, harga di bawah standar beralasan.
 */

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->clinicId = $this->batu->clinic_id;
    $this->doctor = Doctor::create(['clinic_id' => $this->clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'drg. Uji Aturan', 'base_salary' => 0]);
    $this->patient = Patient::where('clinic_id', $this->clinicId)->first();
    $this->proc = Procedure::create(['clinic_id' => $this->clinicId, 'code' => 'UJIKTR', 'name' => 'Kontrol uji']);
    Settings::set('share_percent_global', '35');
    Settings::set('doctor_sitting_fee', '40000');
    Settings::set('doctor_sitting_threshold', '100000');
});

/** Tagihan kunjungan dengan satu baris tindakan pada tanggal & jam tertentu. */
function tindakanPada(int $amount, string $at, string $status = Invoice::STATUS_UNPAID, int $discount = 0, ?Procedure $p = null, array $visitAttrs = []): InvoiceItem
{
    $t = test();
    $p ??= $t->proc;
    $when = Carbon::parse($at);
    $visit = Visit::create($visitAttrs + [
        'patient_id' => $t->patient->id, 'doctor_id' => $t->doctor->id, 'branch_id' => $t->batu->id,
        'visit_date' => $when->toDateString(), 'queue_number' => nomorUji(), 'status' => 'selesai',
        'examined_at' => $when,
    ]);
    $invoice = Invoice::create([
        'visit_id' => $visit->id, 'branch_id' => $t->batu->id, 'subtotal' => $amount, 'discount' => $discount,
        'total' => $amount - $discount, 'paid' => $status === Invoice::STATUS_PAID ? $amount - $discount : 0,
        'status' => $status, 'paid_at' => $status === Invoice::STATUS_PAID ? $when : null,
    ]);

    return InvoiceItem::create([
        'invoice_id' => $invoice->id, 'procedure_id' => $p->id, 'doctor_id' => $t->doctor->id,
        'name' => $p->name, 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount,
    ]);
}

/** Nomor antrean unik per test (kolom smallint). */
function nomorUji(): int
{
    static $n = 500;

    return ++$n;
}

function gajiDokter(string $month = '2026-05-01'): array
{
    return Payroll::forMonth(test()->clinicId, Carbon::parse($month))->first(fn ($r) => $r['doctor']->id === test()->doctor->id);
}

function hadir(string $date, int $shift): void
{
    DoctorShiftAttendance::create(['doctor_id' => test()->doctor->id, 'branch_id' => test()->batu->id, 'date' => $date, 'shift' => $shift]);
}

it('fee dihitung dari tindakan yang belum lunas, menurut tanggal kunjungan', function () {
    tindakanPada(200_000, '2026-05-10 10:00');                       // belum dibayar
    tindakanPada(100_000, '2026-05-11 10:00', Invoice::STATUS_DRAFT); // draf: belum dikirim ke kasir
    tindakanPada(100_000, '2026-05-12 10:00', Invoice::STATUS_VOID);  // batal

    expect(gajiDokter()['share'])->toBe(70_000)
        ->and(gajiDokter('2026-06-01')['share'])->toBe(0);
});

it('diskon tagihan mengurangi dasar fee secara proporsional', function () {
    tindakanPada(200_000, '2026-05-10 10:00', Invoice::STATUS_PAID, 20_000);

    expect(gajiDokter()['share'])->toBe(63_000)   // (200 rb − 20 rb) × 35%
        ->and(gajiDokter()['discount'])->toBe(20_000);
});

it('biaya bahan resep dipotong sebelum bagi hasil', function () {
    $line = tindakanPada(200_000, '2026-05-10 10:00');
    $line->update(['material_cost' => 20_000]);

    expect(gajiDokter()['share'])->toBe(63_000);  // (200 rb − 20 rb) × 35%
});

it('uang duduk per shift: di bawah ambang dapat UD, tepat/lebih ambang dapat 35%', function () {
    // 10 Mei shift 1: satu kontrol 35 rb → UD 40 rb, fee 0.
    hadir('2026-05-10', 1);
    tindakanPada(35_000, '2026-05-10 09:00');
    // 11 Mei shift 1: 3 × 35 rb = 105 rb → 35% = 36.750, tanpa UD.
    hadir('2026-05-11', 1);
    foreach (['09:00', '10:00', '11:00'] as $jam) {
        tindakanPada(35_000, "2026-05-11 {$jam}");
    }
    // 12 Mei shift 1: tepat 100 rb → 35%.
    hadir('2026-05-12', 1);
    tindakanPada(100_000, '2026-05-12 10:00');
    // 13 Mei tanpa tanda hadir → fee normal.
    tindakanPada(20_000, '2026-05-13 10:00');

    $row = gajiDokter();
    expect($row['sitting'])->toBe(40_000)
        ->and($row['sitting_shifts'])->toBe(1)
        ->and($row['share'])->toBe(36_750 + 35_000 + 7_000)
        ->and($row['total'])->toBe(40_000 + 36_750 + 35_000 + 7_000);
});

it('dua shift sehari dihitung terpisah memakai jam pemeriksaan', function () {
    hadir('2026-05-10', 1);
    hadir('2026-05-10', 2);
    tindakanPada(60_000, '2026-05-10 09:00');   // shift 1: 60 rb → UD
    tindakanPada(150_000, '2026-05-10 16:00');  // shift 2: 150 rb → 35%

    $row = gajiDokter();
    expect($row['sitting'])->toBe(40_000)
        ->and($row['share'])->toBe(52_500)
        ->and($row['shifts'])->toHaveCount(2);
});

it('shift hadir tanpa pasien tetap mendapat uang duduk', function () {
    hadir('2026-05-10', 1);

    expect(gajiDokter()['sitting'])->toBe(40_000)->and(gajiDokter()['total'])->toBe(40_000);
});

it('fee asisten memakai nominal tindakan bila diisi, selain itu persen', function () {
    Settings::set('assistant_share_percent', '2');
    $behel = Procedure::create(['clinic_id' => $this->clinicId, 'code' => 'UJIBHL', 'name' => 'Pasang behel uji', 'assistant_fee_amount' => 8000]);
    $asisten = Employee::create(['clinic_id' => $this->clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Uji', 'position' => 'Asisten dokter', 'base_salary' => 0]);

    tindakanPada(5_000_000, '2026-05-10 10:00', p: $behel, visitAttrs: ['assistant_id' => $asisten->id]);
    tindakanPada(100_000, '2026-05-11 10:00', visitAttrs: ['assistant_id' => $asisten->id]);

    $row = Payroll::employeesForMonth($this->clinicId, Carbon::parse('2026-05-01'))->first(fn ($r) => $r['employee']->id === $asisten->id);
    expect($row['assistant_share'])->toBe(8_000 + 2_000);
});

it('pemakaian bahan pecahan memotong stok tiap genap satu satuan dan kembali saat batal', function () {
    $komposit = Item::create(['clinic_id' => $this->clinicId, 'code' => 'UJIKMP', 'name' => 'Komposit uji', 'category' => 'bahan', 'unit' => 'tube', 'buy_price' => 200_000]);
    $this->proc->materials()->create(['item_id' => $komposit->id, 'quantity' => 0.5]);
    $stock = app(StockService::class);
    $stock->move($komposit->id, $this->batu->id, 5, 'masuk', ['unit_cost' => 200_000]);

    $a = tindakanPada(150_000, '2026-05-10 10:00')->invoice;
    $stock->syncMaterials($a);
    expect($stock->available($komposit->id, $this->batu->id))->toBe(5);  // saldo 0,5

    $b = tindakanPada(150_000, '2026-05-10 11:00')->invoice;
    $stock->syncMaterials($b);
    $stock->syncMaterials($b);  // simpan ulang tidak memotong dua kali
    expect($stock->available($komposit->id, $this->batu->id))->toBe(4);  // genap 1 tube

    $b->update(['status' => Invoice::STATUS_VOID]);
    expect($stock->available($komposit->id, $this->batu->id))->toBe(5);
});

describe('pemeriksaan', function () {
    beforeEach(function () {
        $this->visit = Visit::whereDate('visit_date', today())
            ->whereHas('patient', fn ($q) => $q->where('name', 'Siti Aminah'))->with('doctor.user')->firstOrFail();
        $this->exam = Procedure::with('prices')->get()->first(fn (Procedure $p) => $p->priceFor($this->visit->branch_id) > 0);
        $this->standard = $this->exam->priceFor($this->visit->branch_id);
        $user = $this->visit->doctor->user;
        $ctx = [BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $this->visit->branch_id];
        $this->actingAs($user)->withSession($ctx);
        session($ctx);
    });

    function bayarPeriksa(array $procedures): array
    {
        return [
            'complaint' => null, 'anamnesis' => null, 'blood_pressure' => null, 'current_medication' => null, 'plan' => null,
            'follow_up_label' => null, 'follow_up_date' => null, 'allergy_confirmed' => false,
            'findings' => [], 'diagnoses' => [], 'procedures' => $procedures, 'prescriptions' => [],
        ];
    }

    /** Jenis baris biaya admin di tagihan kunjungan → [fee_kind => amount]. */
    function biayaAdmin(Visit $visit): array
    {
        return $visit->fresh()->invoice->items()->where('is_admin_fee', true)->pluck('amount', 'fee_kind')->map(fn ($a) => (int) $a)->all();
    }

    function sebagaiKasir(int $branchId): void
    {
        test()->actingAs(User::where('username', 'admin')->firstOrFail());
        session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $branchId]);
    }

    function kunjunganLagi(Visit $visit, int $days = 1): Visit
    {
        return Visit::create([
            'patient_id' => $visit->patient_id, 'doctor_id' => $visit->doctor_id, 'branch_id' => $visit->branch_id,
            'visit_date' => today()->addDays($days)->toDateString(), 'queue_number' => 900 + $days, 'status' => 'diperiksa',
        ]);
    }

    it('pasien lama: biaya admin tiap transaksi tindakan, tanpa pendaftaran', function () {
        Settings::set('admin_fee', '5000');
        Settings::set('registration_fee', '5000');
        expect($this->visit->patient->registration_paid)->toBeTrue();

        app(ExamService::class)->saveDraft($this->visit, bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1]]));
        expect(biayaAdmin($this->visit))->toBe([InvoiceItem::FEE_ADMIN => 5000])
            ->and($this->visit->fresh()->invoice->total)->toBe($this->standard + 5000);

        // Kunjungan berikutnya tetap kena admin.
        $next = kunjunganLagi($this->visit);
        app(ExamService::class)->saveDraft($next, bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1]]));
        expect(biayaAdmin($next))->toBe([InvoiceItem::FEE_ADMIN => 5000]);
    });

    it('pasien baru: admin + pendaftaran sekali, lalu tercatat pasien lama setelah membayar', function () {
        Settings::set('admin_fee', '5000');
        Settings::set('registration_fee', '5000');
        $this->visit->patient->update(['registration_paid' => false]);

        app(ExamService::class)->saveDraft($this->visit, bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1]]));
        expect(biayaAdmin($this->visit))->toBe([InvoiceItem::FEE_ADMIN => 5000, InvoiceItem::FEE_REGISTRATION => 5000])
            ->and($this->visit->fresh()->invoice->total)->toBe($this->standard + 10_000);

        // Belum dibayar: kunjungan lain tidak menagih pendaftaran dua kali.
        $next = kunjunganLagi($this->visit);
        app(ExamService::class)->saveDraft($next, bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1]]));
        expect(biayaAdmin($next))->toBe([InvoiceItem::FEE_ADMIN => 5000]);

        // Bayar di Kasir → pasien lama.
        $invoice = $this->visit->fresh()->invoice;
        $invoice->update(['status' => Invoice::STATUS_UNPAID]);
        sebagaiKasir($this->visit->branch_id);
        Livewire\Livewire::test(\App\Livewire\Modules\Kasir::class)
            ->call('open', $invoice->id)
            ->set('pay.method', array_key_first(Settings::paymentMethods()))
            ->set('pay.amount', 10_000)
            ->call('submitPayment');
        expect($this->visit->patient->fresh()->registration_paid)->toBeTrue();
    });

    it('tindakan Rp0 (garansi) dan tagihan tanpa tindakan tidak kena biaya admin', function () {
        Settings::set('admin_fee', '5000');
        Settings::set('registration_fee', '5000');
        $this->visit->patient->update(['registration_paid' => false]);
        $this->exam->update(['allow_lower_price' => true]);

        app(ExamService::class)->saveDraft($this->visit, bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1, 'unit_price' => 0, 'price_reason' => 'Garansi tambalan']]));
        expect(biayaAdmin($this->visit))->toBe([]);

        $next = kunjunganLagi($this->visit);
        app(ExamService::class)->saveDraft($next, bayarPeriksa([]));
        expect($next->fresh()->invoice?->items()->where('is_admin_fee', true)->exists() ?? false)->toBeFalse();
    });

    it('Kasir menghapus biaya pendaftaran → pasien dicatat pasien lama', function () {
        Settings::set('admin_fee', '5000');
        Settings::set('registration_fee', '5000');
        $this->visit->patient->update(['registration_paid' => false]);
        app(ExamService::class)->saveDraft($this->visit, bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1]]));
        $invoice = $this->visit->fresh()->invoice;
        $invoice->update(['status' => Invoice::STATUS_UNPAID]);
        $line = $invoice->items()->where('fee_kind', InvoiceItem::FEE_REGISTRATION)->firstOrFail();

        sebagaiKasir($this->visit->branch_id);
        Livewire\Livewire::test(\App\Livewire\Modules\Kasir::class)
            ->call('open', $invoice->id)
            ->call('removeAdminFee', $line->id);

        expect(biayaAdmin($this->visit))->toBe([InvoiceItem::FEE_ADMIN => 5000])
            ->and($this->visit->patient->fresh()->registration_paid)->toBeTrue();
    });

    it('tarif terkunci selalu memakai harga standar', function () {
        $this->exam->update(['price_locked' => true]);
        app(ExamService::class)->saveDraft($this->visit, bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1, 'unit_price' => $this->standard + 50_000]]));

        expect($this->visit->fresh()->invoice->items()->where('procedure_id', $this->exam->id)->value('unit_price'))->toBe($this->standard);
    });

    it('harga di bawah standar hanya untuk tindakan berizin dan wajib alasan', function () {
        $lower = intdiv($this->standard, 2);
        $payload = fn (?string $reason) => bayarPeriksa([['procedure_id' => $this->exam->id, 'quantity' => 1, 'unit_price' => $lower, 'price_reason' => $reason]]);

        expect(fn () => app(ExamService::class)->saveDraft($this->visit, $payload('Tidak selesai')))->toThrow(ValidationException::class);

        $this->exam->update(['allow_lower_price' => true]);
        expect(fn () => app(ExamService::class)->saveDraft($this->visit, $payload(null)))->toThrow(ValidationException::class);

        app(ExamService::class)->saveDraft($this->visit, $payload('Cabut tidak selesai, lanjut minggu depan'));
        $line = $this->visit->fresh()->invoice->items()->where('procedure_id', $this->exam->id)->first();
        expect($line->unit_price)->toBe($lower)
            ->and($line->price_reason)->toBe('Cabut tidak selesai, lanjut minggu depan')
            ->and($line->isLowered())->toBeTrue();
    });
});
