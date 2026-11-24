<?php

use App\Models\Procedure;
use App\Models\Visit;
use App\Services\ExamService;
use App\Support\BranchContext;
use Illuminate\Validation\ValidationException;

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

it('harga di dalam range diterima dan tanpa harga memakai tarif minimal', function () {
    simpanHargaRange([]);
    expect(barisRange()->unit_price)->toBe($this->min);

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

it('harga tidak selesai dipertahankan saat Admin menyimpan ulang', function () {
    $lower = intdiv($this->min, 2);
    simpanHargaRange(['unit_price' => $lower, 'is_incomplete' => true, 'price_reason' => 'Cabut tidak selesai']);

    $admin = \App\Models\User::where('username', 'admin')->firstOrFail();
    $ctx = [BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->visit->branch_id];
    $this->actingAs($admin)->withSession($ctx);
    session($ctx);
    simpanHargaRange([]);

    expect(barisRange()->unit_price)->toBe($lower)->and(barisRange()->is_incomplete)->toBeTrue();
});

it('tarif terkunci tetap tarif minimal walau dicentang tidak selesai', function () {
    $this->proc->update(['price_locked' => true]);
    simpanHargaRange(['unit_price' => 1000, 'is_incomplete' => true, 'price_reason' => 'x']);

    expect(barisRange()->unit_price)->toBe($this->min)->and(barisRange()->is_incomplete)->toBeFalse();
});
