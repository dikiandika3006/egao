<?php

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Visit;
use App\Support\Payroll;
use App\Support\Settings;
use Carbon\Carbon;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->clinicId = $this->batu->clinic_id;
    $this->doctor = Doctor::create(['clinic_id' => $this->clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'drg. Uji Bertahap', 'base_salary' => 0]);
    $this->patient = Patient::where('clinic_id', $this->clinicId)->first();
    Settings::set('share_percent_global', '35');
});

/** Tindakan lunas pada tanggal tertentu (tagihan kunjungan, patient_id lewat visit). */
function lunasPada(Procedure $p, int $amount, string $date): InvoiceItem
{
    $t = test();
    $at = Carbon::parse($date)->setTime(10, 0);
    $visit = Visit::create([
        'patient_id' => $t->patient->id, 'doctor_id' => $t->doctor->id, 'branch_id' => $t->batu->id,
        'visit_date' => $at->toDateString(), 'queue_number' => random_int(100, 9999), 'status' => 'selesai',
    ]);
    $invoice = Invoice::create([
        'visit_id' => $visit->id, 'branch_id' => $t->batu->id, 'subtotal' => $amount, 'total' => $amount,
        'paid' => $amount, 'status' => Invoice::STATUS_PAID, 'paid_at' => $at,
    ]);

    return InvoiceItem::create([
        'invoice_id' => $invoice->id, 'procedure_id' => $p->id, 'doctor_id' => $t->doctor->id,
        'name' => $p->name, 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount,
    ]);
}

function labPasien(int $cost, string $sentAt, string $status = 'dikirim'): LabOrder
{
    $t = test();

    return LabOrder::create([
        'clinic_id' => $t->clinicId, 'branch_id' => $t->batu->id, 'patient_id' => $t->patient->id, 'doctor_id' => $t->doctor->id,
        'lab_name' => 'Lab', 'work' => 'Gigi tiruan', 'sent_at' => $sentAt, 'cost' => $cost, 'status' => $status,
    ]);
}

function barisDokter(string $month): array
{
    return Payroll::forMonth(test()->clinicId, Carbon::parse($month))->first(fn ($r) => $r['doctor']->id === test()->doctor->id);
}

function gigiPalsu(): array
{
    $cetak = Procedure::create(['clinic_id' => test()->clinicId, 'code' => 'UJICGT', 'name' => 'Cetak gigi tiruan', 'share_percent' => 0]);
    $insersi = Procedure::create([
        'clinic_id' => test()->clinicId, 'code' => 'UJIINS', 'name' => 'Insersi gigi tiruan',
        'fee_from_procedure_id' => $cetak->id, 'fee_minus_lab' => true,
    ]);

    return [$cetak, $insersi];
}

it('gigi palsu: DP cetak ditahan, fee saat insersi = 35% × (total bayar − lab)', function () {
    [$cetak, $insersi] = gigiPalsu();
    lunasPada($cetak, 700_000, '2026-05-10');
    labPasien(240_000, '2026-05-12');
    labPasien(9_000_000, '2026-05-13', 'batal');
    lunasPada($insersi, 500_000, '2026-06-05');

    $mei = barisDokter('2026-05-01');
    expect($mei['share'])->toBe(0)->and($mei['lab'])->toBe(0);

    // (700 rb + 500 rb − 240 rb) × 35% = 336 rb (contoh Sep'26 r57 di Excel)
    $juni = barisDokter('2026-06-01');
    expect($juni['carry'])->toBe(700_000)
        ->and($juni['lab'])->toBe(240_000)
        ->and($juni['lab_deduction'])->toBe(84_000)
        ->and($juni['share'])->toBe(336_000)
        ->and($juni['total'])->toBe(336_000);
});

it('gigi palsu: kasus berikutnya tidak menghitung ulang DP & lab kasus lama', function () {
    [$cetak, $insersi] = gigiPalsu();
    lunasPada($cetak, 700_000, '2026-04-10');
    labPasien(240_000, '2026-04-12');
    lunasPada($insersi, 500_000, '2026-04-20');

    lunasPada($cetak, 1_000_000, '2026-06-01');
    labPasien(400_000, '2026-06-03');
    lunasPada($insersi, 0, '2026-06-20');

    $juni = barisDokter('2026-06-01');
    expect($juni['carry'])->toBe(1_000_000)
        ->and($juni['lab'])->toBe(400_000)
        ->and($juni['share'])->toBe(210_000); // (1 jt − 400 rb) × 35%
});

it('behel: cetak 35% setelah biaya analisis, pemasangan 10% dari harga behel total', function () {
    $cetak = Procedure::create(['clinic_id' => $this->clinicId, 'code' => 'UJICBH', 'name' => 'Cetak behel', 'cost_amount' => 100_000]);
    $pasang = Procedure::create([
        'clinic_id' => $this->clinicId, 'code' => 'UJIPBH', 'name' => 'Pasang behel', 'share_percent' => 10,
        'fee_from_procedure_id' => $cetak->id,
    ]);
    lunasPada($cetak, 300_000, '2026-06-02');
    lunasPada($pasang, 3_200_000, '2026-06-24');

    $row = barisDokter('2026-06-01');
    $lines = $row['lines']->keyBy('name');

    // Cetak: (300 rb − 100 rb) × 35% = 70 rb. Pasang: (3,2 jt + 300 rb) × 10% = 350 rb (contoh Jul'26 r24).
    expect($lines['Cetak behel']['share'])->toBe(70_000)
        ->and($lines['Pasang behel']['carry'])->toBe(300_000)
        ->and($lines['Pasang behel']['share'])->toBe(350_000)
        ->and($row['share'])->toBe(420_000);
});

it('tanpa tindakan bertanda potong lab, potongan lab tetap cara lama (bulan dikirim)', function () {
    $tambal = Procedure::create(['clinic_id' => $this->clinicId, 'code' => 'UJITBL', 'name' => 'Tambal uji']);
    lunasPada($tambal, 1_000_000, '2026-06-05');
    labPasien(200_000, '2026-06-06');

    $row = barisDokter('2026-06-01');
    expect($row['lab'])->toBe(200_000)
        ->and($row['lab_deduction'])->toBe(70_000)
        ->and($row['share'])->toBe(280_000);
});

function masukSebagai(string $username, string $role, string|int $branch): void
{
    test()->actingAs(\App\Models\User::where('username', $username)->firstOrFail());
    session([\App\Support\BranchContext::SESSION_ROLE => $role, \App\Support\BranchContext::SESSION_BRANCH => $branch]);
}

it('owner mengatur aturan fee bertahap di form tindakan', function () {
    masukSebagai('owner', 'owner', 'all');
    [$cetak, $insersi] = gigiPalsu();
    $insersi->update(['fee_from_procedure_id' => null, 'fee_minus_lab' => false]);

    \Livewire\Livewire::test(\App\Livewire\Modules\Pengaturan::class)
        ->call('setTab', 'tindakan')
        ->call('editProcedure', $insersi->id)
        ->assertSee('Aturan fee bertahap')
        ->set('data.fee_from_procedure_id', (string) $cetak->id)
        ->set('data.fee_minus_lab', true)
        ->call('saveProcedure')
        ->assertHasNoErrors();

    expect($insersi->fresh())->fee_from_procedure_id->toBe($cetak->id)->fee_minus_lab->toBeTrue();

    // Tidak boleh merujuk dirinya sendiri.
    \Livewire\Livewire::test(\App\Livewire\Modules\Pengaturan::class)
        ->call('setTab', 'tindakan')
        ->call('editProcedure', $insersi->id)
        ->set('data.fee_from_procedure_id', (string) $insersi->id)
        ->call('saveProcedure')
        ->assertHasErrors('data.fee_from_procedure_id');
});

it('admin tidak bisa mengubah aturan fee bertahap walau mengirim nilainya', function () {
    [$cetak, $insersi] = gigiPalsu();
    // Owner membuka menu Tindakan & tarif untuk Admin/Asisten lewat Hak akses peran.
    $matrix = \App\Support\Access::matrix();
    $matrix['admin']['tindakan'] = ['lihat' => true, 'edit' => true, 'keuangan' => true];
    \App\Support\Access::save($matrix);
    masukSebagai('admin', 'admin', $this->batu->id);

    \Livewire\Livewire::test(\App\Livewire\Modules\Pengaturan::class)
        ->call('setTab', 'tindakan')
        ->call('editProcedure', $insersi->id)
        ->assertDontSee('Aturan fee bertahap')
        ->set('data.fee_from_procedure_id', '')
        ->set('data.fee_minus_lab', false)
        ->call('saveProcedure');

    expect($insersi->fresh())->fee_from_procedure_id->toBe($cetak->id)->fee_minus_lab->toBeTrue();
});
