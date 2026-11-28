<?php

use App\Enums\VisitStatus;
use App\Livewire\MedicalRecords\Exam;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Procedure;
use App\Models\User;
use App\Models\Visit;
use App\Services\ExamService;
use App\Support\BranchContext;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Edit di tempat (26 Nov 2026): setelah dikirim ke kasir, Dokter pemeriksa/Owner bisa masuk Mode edit untuk
 * mengubah tindakan & harga selama tagihan belum dibayar. Tagihan tetap di Kasir, perubahan tercatat di audit.
 */

beforeEach(function () {
    $this->seed();
    $this->visit = Visit::whereDate('visit_date', today())
        ->whereHas('patient', fn ($q) => $q->where('name', 'Siti Aminah'))->with('doctor.user')->firstOrFail();
    $this->proc = Procedure::with('prices')->get()
        ->first(fn (Procedure $p) => $p->priceFor($this->visit->branch_id) > 0 && $p->rangeFor($this->visit->branch_id)[1] === null && ! $p->price_locked);
    $this->tarif = $this->proc->priceFor($this->visit->branch_id);

    editLogin($this->visit->doctor->user->username, 'dokter');
    app(ExamService::class)->sendToCashier($this->visit, [
        'complaint' => 'Uji', 'anamnesis' => null, 'blood_pressure' => null, 'current_medication' => null, 'plan' => null,
        'follow_up_label' => null, 'follow_up_date' => null, 'allergy_confirmed' => true,
        'findings' => [], 'diagnoses' => [['code' => 'K02.1', 'primary' => true]], 'prescriptions' => [],
        'procedures' => [['procedure_id' => $this->proc->id, 'quantity' => 1]],
    ]);
    $this->visit->refresh();
});

function editLogin(string $username, string $role): void
{
    $ctx = [BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => test()->visit->branch_id];
    test()->actingAs(User::where('username', $username)->firstOrFail())->withSession($ctx);
    session($ctx);
}

function barisEdit()
{
    return test()->visit->fresh()->invoice->items()->where('procedure_id', test()->proc->id)->first();
}

it('tanpa menekan Edit kartu tagihan hanya lihat; tombol Edit tersedia untuk dokter pemeriksa', function () {
    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->assertSee('Mode lihat')
        ->assertSee('✎ Edit')
        ->assertDontSeeHtml('data-price-input')
        ->call('addProcedure')
        ->assertForbidden();
});

it('dokter pemeriksa mengubah qty & harga setelah dikirim: tagihan tetap di kasir dan tercatat di audit', function () {
    $c = Livewire::test(Exam::class, ['visit' => $this->visit])
        ->call('startBillingEdit')
        ->assertSee('Mode edit')
        ->assertSeeHtml('data-price-input');
    $i = collect($c->get('procedures'))->search(fn ($p) => $p['procedure_id'] === $this->proc->id);

    $c->set("procedures.{$i}.quantity", '2')
        ->set("procedures.{$i}.unit_price", $this->tarif + 20_000)
        ->call('saveBillingChanges')
        ->assertHasNoErrors()
        ->assertSet('editingBilling', false);

    $line = barisEdit();
    $visit = $this->visit->fresh();
    expect($line->quantity)->toBe(2)
        ->and($line->unit_price)->toBe($this->tarif + 20_000)
        ->and($visit->invoice->status)->toBe(Invoice::STATUS_UNPAID)
        ->and($visit->status)->toBe(VisitStatus::MenungguPembayaran)
        ->and(AuditLog::where('action', 'tagihan.diubah_setelah_kirim')->exists())->toBeTrue();
});

it('owner boleh mengedit; admin dan dokter lain tidak', function () {
    editLogin('owner', 'owner');
    expect(app(ExamService::class)->canEditSentBilling($this->visit->fresh()))->toBeTrue();

    editLogin('admin', 'admin');
    expect(app(ExamService::class)->canEditSentBilling($this->visit->fresh()))->toBeFalse();
    Livewire::test(Exam::class, ['visit' => $this->visit])->call('startBillingEdit')->assertForbidden();

    $other = User::whereHas('doctor', fn ($q) => $q->where('id', '!=', $this->visit->doctor_id))->firstOrFail();
    editLogin($other->username, 'dokter');
    expect(app(ExamService::class)->canEditSentBilling($this->visit->fresh()))->toBeFalse();
});

it('sudah ada pembayaran: terkunci, server menolak', function () {
    $invoice = $this->visit->invoice;
    $invoice->payments()->create(['received_by' => auth()->id(), 'method' => 'tunai', 'amount' => 1000, 'paid_at' => now()]);

    expect(app(ExamService::class)->canEditSentBilling($this->visit->fresh()))->toBeFalse();
    expect(fn () => app(ExamService::class)->updateSentBilling($this->visit->fresh(), [
        'procedures' => [['procedure_id' => $this->proc->id, 'quantity' => 5]],
    ]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(barisEdit()->quantity)->toBe(1);
});

it('tarif range yang dikosongkan saat edit ditolak di form', function () {
    $this->proc->prices()->where('branch_id', $this->visit->branch_id)->update(['max_price' => $this->tarif + 100_000]);

    $c = Livewire::test(Exam::class, ['visit' => $this->visit])->call('startBillingEdit');
    $i = collect($c->get('procedures'))->search(fn ($p) => $p['procedure_id'] === $this->proc->id);
    expect($c->get('procedures')[$i]['unit_price'])->toBe($this->tarif);

    $c->set("procedures.{$i}.unit_price", null)
        ->call('saveBillingChanges')
        ->assertHasErrors("procedures.{$i}.unit_price")
        ->assertSet('editingBilling', true);
});

it('tambah baris saat Mode edit ikut tersimpan di tagihan', function () {
    $other = Procedure::with('prices')->get()->first(fn (Procedure $p) => $p->id !== $this->proc->id
        && $p->priceFor($this->visit->branch_id) > 0 && $p->rangeFor($this->visit->branch_id)[1] === null && ! $p->price_locked);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->call('startBillingEdit')
        ->set('newProcedureId', $other->id)
        ->call('addProcedure')
        ->call('saveBillingChanges')
        ->assertHasNoErrors();

    expect($this->visit->fresh()->invoice->items()->where('procedure_id', $other->id)->exists())->toBeTrue();
});

it('tombol Edit di Kasir muncul untuk tagihan belum dibayar dan membuka Mode edit langsung', function () {
    editLogin('owner', 'owner');

    Livewire::test(\App\Livewire\Modules\Kasir::class)
        ->assertSeeHtml('href="'.route('rekam-medis.periksa', ['visit' => $this->visit->id, 'edit' => 1]).'"');

    Livewire::withQueryParams(['edit' => 1])->test(Exam::class, ['visit' => $this->visit])
        ->assertSet('editingBilling', true)
        ->assertSeeHtml('data-price-input');
});

it('tombol Edit di Kasir hilang setelah ada pembayaran, dan ?edit=1 tanpa hak diabaikan', function () {
    editLogin('admin', 'admin');
    $link = route('rekam-medis.periksa', ['visit' => $this->visit->id, 'edit' => 1]);

    // Admin tanpa izin Rekam medis › Edit: tidak ada tombol, parameter diabaikan.
    Livewire::test(\App\Livewire\Modules\Kasir::class)->assertDontSeeHtml('href="'.$link.'"');
    Livewire::withQueryParams(['edit' => 1])->test(Exam::class, ['visit' => $this->visit])->assertSet('editingBilling', false);

    // Sudah ada pembayaran sebagian: Owner pun tidak melihat tombol.
    editLogin('owner', 'owner');
    $this->visit->invoice->payments()->create(['received_by' => auth()->id(), 'method' => 'tunai', 'amount' => 1_000, 'paid_at' => now()]);
    Livewire::test(\App\Livewire\Modules\Kasir::class)->assertDontSeeHtml('href="'.$link.'"');
});
