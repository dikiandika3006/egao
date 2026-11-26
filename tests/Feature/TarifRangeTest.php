<?php

use App\Livewire\MedicalRecords\Exam;
use App\Models\Procedure;
use App\Models\Visit;
use App\Services\ExamService;
use App\Support\BranchContext;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Tarif tindakan berupa range (24 Nov 2026): harga bebas di dalam range, di atas maks boleh (audit),
 * di bawah minimal hanya dengan centang "tidak selesai" + alasan.
 */

beforeEach(function () {
    $this->seed();
    $this->visit = Visit::whereDate('visit_date', today())
        ->whereHas('patient', fn ($q) => $q->where('name', 'Siti Aminah'))->with('doctor.user')->firstOrFail();
    $this->proc = Procedure::with('prices')->get()->first(fn (Procedure $p) => $p->priceFor($this->visit->branch_id) > 0);
    $this->min = $this->proc->priceFor($this->visit->branch_id);
    $this->max = $this->min + 100_000;
    $this->proc->prices()->where('branch_id', $this->visit->branch_id)->update(['max_price' => $this->max]);
    $this->proc->update(['price_locked' => false, 'allow_lower_price' => false]);

    $ctx = [BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $this->visit->branch_id];
    $this->actingAs($this->visit->doctor->user)->withSession($ctx);
    session($ctx);
});

function simpanHargaRange(array $line): void
{
    $t = test();
    app(ExamService::class)->saveDraft($t->visit, [
        'complaint' => null, 'anamnesis' => null, 'blood_pressure' => null, 'current_medication' => null, 'plan' => null,
        'follow_up_label' => null, 'follow_up_date' => null, 'allergy_confirmed' => false,
        'findings' => [], 'diagnoses' => [], 'prescriptions' => [],
        'procedures' => [$line + ['procedure_id' => $t->proc->id, 'quantity' => 1]],
    ]);
}

function barisRange()
{
    return test()->visit->fresh()->invoice->items()->where('procedure_id', test()->proc->id)->first();
}

it('rangeFor mengembalikan minimal dan maksimal; maks kosong berarti satu harga', function () {
    $proc = $this->proc->fresh('prices');
    expect($proc->rangeFor($this->visit->branch_id))->toBe([$this->min, $this->max]);

    $proc->prices()->where('branch_id', $this->visit->branch_id)->update(['max_price' => null]);
    expect($proc->fresh('prices')->rangeFor($this->visit->branch_id))->toBe([$this->min, null]);
});

it('harga di dalam range diterima; draf tanpa harga tersimpan Rp0 (belum diisi)', function () {
    simpanHargaRange([]);
    expect(barisRange()->unit_price)->toBe(0)
        ->and(barisRange()->amount)->toBe(0)
        ->and(barisRange()->standard_price)->toBe($this->min);

    simpanHargaRange(['unit_price' => $this->min + 50_000]);
    expect(barisRange()->unit_price)->toBe($this->min + 50_000)
        ->and(barisRange()->standard_price)->toBe($this->min);
});

it('harga di atas maksimal tetap boleh', function () {
    simpanHargaRange(['unit_price' => $this->max + 25_000]);
    expect(barisRange()->unit_price)->toBe($this->max + 25_000);
});

it('di bawah minimal ditolak kecuali dicentang tidak selesai dan beralasan', function () {
    $lower = intdiv($this->min, 2);

    expect(fn () => simpanHargaRange(['unit_price' => $lower, 'price_reason' => 'Murah']))->toThrow(ValidationException::class);
    expect(fn () => simpanHargaRange(['unit_price' => $lower, 'is_incomplete' => true]))->toThrow(ValidationException::class);

    simpanHargaRange(['unit_price' => $lower, 'is_incomplete' => true, 'price_reason' => 'Cabut tidak selesai']);
    $line = barisRange();
    expect($line->unit_price)->toBe($lower)
        ->and($line->is_incomplete)->toBeTrue()
        ->and($line->price_reason)->toBe('Cabut tidak selesai');
});

it('harga tidak selesai tetap utuh; Admin/Asisten tidak bisa menyimpan pemeriksaan', function () {
    $lower = intdiv($this->min, 2);
    simpanHargaRange(['unit_price' => $lower, 'is_incomplete' => true, 'price_reason' => 'Cabut tidak selesai']);

    $admin = \App\Models\User::where('username', 'admin')->firstOrFail();
    $ctx = [BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->visit->branch_id];
    $this->actingAs($admin)->withSession($ctx);
    session($ctx);
    expect(fn () => simpanHargaRange([]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(barisRange()->unit_price)->toBe($lower)->and(barisRange()->is_incomplete)->toBeTrue();
});

it('tarif terkunci tetap tarif minimal walau dicentang tidak selesai', function () {
    $this->proc->update(['price_locked' => true]);
    simpanHargaRange(['unit_price' => 1000, 'is_incomplete' => true, 'price_reason' => 'x']);

    expect(barisRange()->unit_price)->toBe($this->min)->and(barisRange()->is_incomplete)->toBeFalse();
});

it('tarif satu harga tanpa harga diisi tetap memakai tarif tunggal', function () {
    $this->proc->prices()->where('branch_id', $this->visit->branch_id)->update(['max_price' => null]);
    simpanHargaRange([]);

    expect(barisRange()->unit_price)->toBe($this->min);
});

it('dokter menurunkan harga range sampai tarif minimal tanpa alasan', function () {
    simpanHargaRange(['unit_price' => $this->min]);

    expect(barisRange()->unit_price)->toBe($this->min)->and(barisRange()->price_reason)->toBeNull();
});

function kirimHargaRange(array $line): void
{
    $t = test();
    app(ExamService::class)->sendToCashier($t->visit, [
        'complaint' => 'Uji', 'anamnesis' => null, 'blood_pressure' => null, 'current_medication' => null, 'plan' => null,
        'follow_up_label' => null, 'follow_up_date' => null, 'allergy_confirmed' => true,
        'findings' => [], 'diagnoses' => [['code' => 'K02.1', 'primary' => true]], 'prescriptions' => [],
        'procedures' => [$line + ['procedure_id' => $t->proc->id, 'quantity' => 1]],
    ]);
}

it('kirim ke kasir ditolak bila harga tarif range belum diisi, termasuk dari draf Rp0', function () {
    expect(fn () => kirimHargaRange([]))->toThrow(ValidationException::class);

    simpanHargaRange([]);
    expect(fn () => kirimHargaRange([]))->toThrow(ValidationException::class);

    kirimHargaRange(['unit_price' => $this->min + 10_000]);
    expect(barisRange()->unit_price)->toBe($this->min + 10_000);
});

it('halaman pemeriksaan: harga range wajib diisi manual sebelum kirim dan tetap saat dibuka ulang', function () {
    $c = Livewire::test(Exam::class, ['visit' => $this->visit])
        ->set('newProcedureId', $this->proc->id)
        ->call('addProcedure');
    $i = array_key_last($c->get('procedures'));
    $row = fn () => $c->get('procedures')[$i];

    expect($c->instance()->needsPrice($row()))->toBeTrue()
        ->and($c->instance()->priceOf($row()))->toBe(0)
        ->and($c->instance()->zeroPriceProcedures->pluck('procedure_id'))->not->toContain($this->proc->id);

    $c->call('confirmSend')->assertHasErrors("procedures.{$i}.unit_price");

    $c->set("procedures.{$i}.unit_price", (string) $this->min);
    expect($row()['unit_price'])->toBe($this->min)
        ->and($c->instance()->priceOf($row()))->toBe($this->min)
        ->and($c->instance()->needsPrice($row()))->toBeFalse();

    // Draf tanpa harga dibuka ulang tetap "belum diisi"; harga yang diisi dimuat apa adanya.
    simpanHargaRange([]);
    $draft = collect(Livewire::test(Exam::class, ['visit' => $this->visit->fresh()])->get('procedures'))->firstWhere('procedure_id', $this->proc->id);
    expect($draft['unit_price'])->toBeNull();

    simpanHargaRange(['unit_price' => $this->min]);
    $saved = collect(Livewire::test(Exam::class, ['visit' => $this->visit->fresh()])->get('procedures'))->firstWhere('procedure_id', $this->proc->id);
    expect($saved['unit_price'])->toBe($this->min);
});

it('baris tindakan seperti nota: tarif tunggal langsung terisi, gigi & qty diketik di baris', function () {
    $this->proc->prices()->where('branch_id', $this->visit->branch_id)->update(['max_price' => null]);
    $c = Livewire::test(Exam::class, ['visit' => $this->visit])
        ->set('newProcedureId', $this->proc->id)
        ->call('addProcedure');
    $i = array_key_last($c->get('procedures'));
    expect($c->get('procedures')[$i]['unit_price'])->toBe($this->min);

    $c->set("procedures.{$i}.quantity", '3')->set("procedures.{$i}.tooth", ' 36 ');
    expect($c->get('procedures')[$i]['quantity'])->toBe(3)->and($c->get('procedures')[$i]['tooth'])->toBe('36');

    $c->set("procedures.{$i}.quantity", '0');
    expect($c->get('procedures')[$i]['quantity'])->toBe(1);

    // Harga dikosongkan → tarif tunggal terisi lagi.
    $c->set("procedures.{$i}.unit_price", $this->min + 5000)->set("procedures.{$i}.unit_price", null);
    expect($c->get('procedures')[$i]['unit_price'])->toBe($this->min);

    $c->set("procedures.{$i}.quantity", '2')->call('saveDraft');
    $line = barisRange();
    expect($line->quantity)->toBe(2)->and($line->tooth)->toBe('36')->and($line->amount)->toBe($this->min * 2);
});
