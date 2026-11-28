<?php

use App\Livewire\MedicalRecords\Exam;
use App\Livewire\Modules\Antrean;
use App\Models\Branch;
use App\Models\Procedure;
use App\Models\User;
use App\Models\Visit;
use App\Services\ExamService;
use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Settings;
use Livewire\Livewire;

/**
 * 28 Nov 2026: Admin/Asisten boleh memeriksa seperti dokter bila Owner mencentang Rekam medis › Edit
 * (ubah harga butuh Keuangan juga). Bawaan tetap hanya baca.
 */
beforeEach(function () {
    $this->seed();
    Settings::set('admin_fee', '0');
    Settings::set('admin_max_discount_percent', '20');
    $this->visit = Visit::whereDate('visit_date', today())
        ->whereHas('patient', fn ($q) => $q->where('name', 'Siti Aminah'))->with('doctor.user')->firstOrFail();
    $this->procedure = Procedure::with('prices')->get()
        ->first(fn (Procedure $p) => $p->priceFor($this->visit->branch_id) !== null && ! $p->price_locked);
    $this->standard = $this->procedure->priceFor($this->visit->branch_id);
});

function adminPeriksaLogin(?int $branch = null): void
{
    $branch ??= test()->visit->branch_id;
    test()->actingAs(User::where('username', 'admin')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $branch]);
    Access::flush();
}

function izinkanAdminPeriksa(bool $keuangan): void
{
    $matrix = Access::matrix();
    $matrix['admin']['rekam-medis'] = ['lihat' => true, 'edit' => true, 'keuangan' => $keuangan];
    Access::save($matrix);
    Access::flush();
}

function adminExam()
{
    return Livewire::test(Exam::class, ['visit' => test()->visit])
        ->set('newProcedureId', test()->procedure->id)
        ->set('newQuantity', 1)
        ->call('addProcedure');
}

it('bawaan: admin tidak bisa memeriksa dari antrean', function () {
    adminPeriksaLogin();

    expect(app(ExamService::class)->canExamine())->toBeFalse()
        ->and(app(ExamService::class)->canEditClinical($this->visit))->toBeFalse();
    Livewire::test(Antrean::class)->call('examine', $this->visit->id)->assertForbidden();
});

it('admin berizin Edit memeriksa, menyimpan tindakan, dan bagi hasil tetap milik dokter kunjungan', function () {
    izinkanAdminPeriksa(keuangan: false);
    adminPeriksaLogin();
    $doctorId = $this->visit->doctor_id;

    Livewire::test(Antrean::class)->call('examine', $this->visit->id)
        ->assertRedirect(route('rekam-medis.periksa', $this->visit));

    adminExam()->call('saveDraft')->assertHasNoErrors();

    $visit = $this->visit->fresh();
    expect($visit->doctor_id)->toBe($doctorId)
        ->and($visit->invoice->items()->where('procedure_id', $this->procedure->id)->exists())->toBeTrue();
});

it('admin tanpa izin Keuangan tidak bisa mengubah harga', function () {
    izinkanAdminPeriksa(keuangan: false);
    adminPeriksaLogin();

    expect(app(ExamService::class)->canRaisePrice())->toBeFalse();
    adminExam()->set('procedures.0.unit_price', (string) ($this->standard + 10_000))->assertForbidden();
});

it('admin berizin Edit + Keuangan boleh menaikkan harga', function () {
    izinkanAdminPeriksa(keuangan: true);
    adminPeriksaLogin();

    adminExam()->set('procedures.0.unit_price', (string) ($this->standard + 10_000))
        ->call('saveDraft')->assertHasNoErrors();

    $item = $this->visit->fresh()->invoice->items()->where('procedure_id', $this->procedure->id)->firstOrFail();
    expect($item->unit_price)->toBe($this->standard + 10_000);
});

it('diskon admin dibatasi persentase yang sama dengan dokter', function () {
    izinkanAdminPeriksa(keuangan: true);
    adminPeriksaLogin();

    $exam = adminExam();
    $tooMuch = (int) floor($exam->instance()->total * 0.2) + 1_000;
    $exam->set('discount', $tooMuch)->set('discountReason', 'Coba')->call('saveDraft')->assertHasErrors('discount');

    $exam->set('discount', 1_000)->set('discountReason', 'Pasien lama')->call('saveDraft')->assertHasNoErrors();
    expect((int) $this->visit->fresh()->invoice->discount)->toBe(1_000);
});

it('admin berizin tidak bisa memeriksa kunjungan cabang lain', function () {
    izinkanAdminPeriksa(keuangan: true);
    $other = Branch::where('id', '!=', $this->visit->branch_id)->firstOrFail();
    adminPeriksaLogin($other->id);

    expect(app(ExamService::class)->canEditClinical($this->visit))->toBeFalse()
        ->and(app(ExamService::class)->canGiveDiscount($this->visit))->toBeFalse();
});
