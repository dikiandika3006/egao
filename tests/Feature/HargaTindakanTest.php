<?php

use App\Livewire\MedicalRecords\Exam;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Procedure;
use App\Models\User;
use App\Models\Visit;
use App\Services\ExamService;
use App\Support\BranchContext;
use App\Support\Payroll;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->visit = Visit::whereDate('visit_date', today())
        ->whereHas('patient', fn ($q) => $q->where('name', 'Siti Aminah'))->with('doctor.user')->firstOrFail();
    $this->procedure = Procedure::with('prices')->get()->first(fn (Procedure $p) => $p->priceFor($this->visit->branch_id) !== null);
    $this->standard = $this->procedure->priceFor($this->visit->branch_id);
});

function hargaLogin(string $username, string $role): void
{
    $branch = test()->visit->branch_id;
    test()->actingAs(User::where('username', $username)->firstOrFail())
        ->withSession([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

function hargaExam()
{
    return Livewire::test(Exam::class, ['visit' => test()->visit])
        ->set('newProcedureId', test()->procedure->id)
        ->set('newQuantity', 2)
        ->call('addProcedure');
}

/** Data lengkap seperti yang dikirim komponen Exam ke ExamService. */
function examPayload(array $procedures, array $extra = []): array
{
    return $extra + [
        'complaint' => null, 'anamnesis' => null, 'blood_pressure' => null, 'current_medication' => null, 'plan' => null,
        'follow_up_label' => null, 'follow_up_date' => null, 'allergy_confirmed' => false,
        'findings' => [], 'diagnoses' => [], 'procedures' => $procedures, 'prescriptions' => [],
    ];
}

function savedItem(): App\Models\InvoiceItem
{
    return test()->visit->fresh()->invoice->items()->where('procedure_id', test()->procedure->id)->firstOrFail();
}

it('dokter menaikkan harga tindakan dan tersimpan di tagihan', function () {
    hargaLogin($this->visit->doctor->user->username, 'dokter');
    $raised = $this->standard + 50000;

    hargaExam()
        ->assertSeeHtml('data-price-input')
        ->set('procedures.0.unit_price', (string) $raised)
        ->assertHasNoErrors()
        ->call('saveDraft')
        ->assertHasNoErrors();

    $item = savedItem();
    expect($item->unit_price)->toBe($raised)
        ->and($item->standard_price)->toBe($this->standard)
        ->and($item->amount)->toBe($raised * 2)
        ->and($item->isRaised())->toBeTrue();
});

it('harga di bawah standar ditolak di form dan di server', function () {
    hargaLogin($this->visit->doctor->user->username, 'dokter');

    hargaExam()
        ->set('procedures.0.unit_price', (string) ($this->standard - 1000))
        ->assertHasErrors('procedures.0.unit_price')
        ->assertSet('procedures.0.unit_price', $this->standard);

    // Lewati komponen: service tetap menolak.
    expect(fn () => app(ExamService::class)->saveDraft($this->visit, examPayload(
        [['procedure_id' => $this->procedure->id, 'quantity' => 1, 'unit_price' => $this->standard - 1000]],
    )))->toThrow(ValidationException::class);
});

it('owner boleh menaikkan harga', function () {
    hargaLogin('owner', 'owner');

    hargaExam()
        ->set('procedures.0.unit_price', (string) ($this->standard + 10000))
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(savedItem()->unit_price)->toBe($this->standard + 10000);
});

it('admin tidak bisa mengubah harga tetapi kenaikan dokter tetap terjaga', function () {
    hargaLogin($this->visit->doctor->user->username, 'dokter');
    hargaExam()->set('procedures.0.unit_price', (string) ($this->standard + 25000))->call('saveDraft');

    hargaLogin('admin', 'admin');

    // Admin mengirim harga → ditolak.
    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->assertDontSeeHtml('data-price-input')
        ->set('procedures.0.unit_price', '1')
        ->assertForbidden();

    // Admin/Asisten tidak memeriksa: simpan lewat service pun ditolak; kenaikan dokter tetap.
    expect(fn () => app(ExamService::class)->saveDraft($this->visit->fresh(), examPayload(
        [['procedure_id' => $this->procedure->id, 'quantity' => 2, 'unit_price' => $this->standard + 999999]],
    )))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(savedItem()->unit_price)->toBe($this->standard + 25000);
});

it('bagi hasil dokter dihitung dari harga yang dinaikkan', function () {
    $doctor = $this->visit->doctor;
    $before = Payroll::forMonth($doctor->clinic_id, now())->firstWhere('doctor.id', $doctor->id)['gross'] ?? 0;

    hargaLogin($doctor->user->username, 'dokter');
    $raised = $this->standard + 40000;
    hargaExam()->set('procedures.0.unit_price', (string) $raised)->call('saveDraft');

    $invoice = $this->visit->fresh()->invoice;
    $invoice->update(['status' => Invoice::STATUS_PAID, 'paid' => $invoice->total, 'paid_at' => now()]);

    $after = Payroll::forMonth($doctor->clinic_id, now())->firstWhere('doctor.id', $doctor->id)['gross'];
    expect($after - $before)->toBe((int) $invoice->items->whereNotNull('procedure_id')->sum('amount'))
        ->and($invoice->items->firstWhere('procedure_id', $this->procedure->id)->amount)->toBe($raised * 2);
});

it('kenaikan harga tercatat di audit saat dikirim ke kasir', function () {
    hargaLogin($this->visit->doctor->user->username, 'dokter');

    app(ExamService::class)->sendToCashier($this->visit, examPayload(
        [['procedure_id' => $this->procedure->id, 'quantity' => 1, 'unit_price' => $this->standard + 5000]],
        ['complaint' => 'Uji', 'allergy_confirmed' => true, 'diagnoses' => [['code' => 'K02.1', 'primary' => true]]],
    ));

    expect(AuditLog::where('action', 'tindakan.harga_dinaikkan')->exists())->toBeTrue();
});
