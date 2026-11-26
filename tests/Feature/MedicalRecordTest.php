<?php

use App\Enums\VisitStatus;
use App\Livewire\MedicalRecords\Exam;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ToothConditionType;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    // Biaya admin sekali per pasien diuji di AturanOwnerFeeTest; di sini total tagihan tanpa admin.
    \App\Support\Settings::set('admin_fee', '0');
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', 'MLG')->first();
});

function loginAs(string $username, string $role, string|int $branch): User
{
    $user = User::where('username', $username)->firstOrFail();
    test()->actingAs($user)->withSession([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);

    return $user;
}

function todayVisit(string $patientName): Visit
{
    return Visit::whereDate('visit_date', today())
        ->whereHas('patient', fn ($q) => $q->where('name', $patientName))
        ->with('doctor')
        ->firstOrFail();
}

it('menampilkan pemeriksaan sesuai contoh desain', function () {
    $visit = todayVisit('Ahmad Fauzi');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    $this->get(route('rekam-medis.periksa', $visit))
        ->assertOk()
        ->assertSee('Pemeriksaan Ahmad Fauzi')
        ->assertSee('RM-00087')
        ->assertSee('K03.6')
        ->assertSee('Scaling')
        ->assertSee('Rp300.000')
        ->assertSee('Cabang Malang'); // kunjungan sebelumnya lintas cabang (UAT-08)
});

it('membuka rekam medis mengubah status menjadi sedang diperiksa', function () {
    $visit = todayVisit('Siti Aminah');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit]);

    expect($visit->fresh()->status)->toBe(VisitStatus::Diperiksa);
});

it('meminta konfirmasi alergi sebelum mengirim ke kasir (UAT-11)', function () {
    $visit = todayVisit('Ahmad Fauzi');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('confirmSend')
        ->assertSet('showSendReview', true);

    expect($visit->fresh()->status)->toBe(VisitStatus::Diperiksa);
});

it('mengirim ke kasir dengan total yang sama di cabang yang sama (UAT-12)', function () {
    $visit = todayVisit('Ahmad Fauzi');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('confirmSend')
        ->call('confirmChecksAndSend')
        ->assertRedirect(route('rekam-medis'));

    $visit->refresh()->load('invoice.items', 'medicalRecord.versions');

    expect($visit->status)->toBe(VisitStatus::MenungguPembayaran)
        ->and($visit->invoice->status)->toBe(Invoice::STATUS_UNPAID)
        ->and($visit->invoice->branch_id)->toBe($this->batu->id)
        ->and($visit->invoice->total)->toBe(300000)
        ->and($visit->invoice->items->pluck('name')->sort()->values()->all())->toBe(['Konsultasi', 'Scaling'])
        ->and($visit->medicalRecord->isFinal())->toBeTrue()
        ->and($visit->medicalRecord->version)->toBe(1)
        ->and($visit->medicalRecord->versions)->toHaveCount(1);
});

it('mewajibkan keluhan, diagnosis, dan tindakan sebelum dikirim', function () {
    $visit = todayVisit('Budi Santoso');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('complaint', '')
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertHasErrors(['complaint', 'diagnoses', 'procedures']);

    expect($visit->fresh()->status)->toBe(VisitStatus::Diperiksa);
});

it('mencatat odontogram, diagnosis, tindakan dengan harga cabang, dan resep', function () {
    $visit = todayVisit('Budi Santoso');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);
    $cabut = Procedure::where('code', 'CBUT')->first();
    $karies = ToothConditionType::where('code', 'KAR')->first();

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('selectTooth', 36)
        ->call('toggleSurface', 'O')
        ->call('toggleSurface', 'M')
        ->set('selectedCondition', $karies->id)
        ->call('addFinding')
        ->set('icdSearch', 'karies')
        ->call('addDiagnosis', 'K02.1')
        ->call('addDiagnosis', 'K04.0')
        ->call('makePrimary', 1)
        ->set('newProcedureId', $cabut->id)
        ->set('newQuantity', 2)
        ->set('newTooth', '36')
        ->call('addProcedure')
        ->call('addPrescription')
        ->set('prescriptions.0.drug_name', 'Amoksisilin 500 mg')
        ->set('prescriptions.0.dose', '3×1')
        ->set('complaint', 'Sakit gigi geraham kiri bawah.')
        ->set('followUp', '1 week')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $visit->refresh()->load('findings', 'diagnoses', 'prescriptions', 'invoice.items', 'medicalRecord');

    expect($visit->findings)->toHaveCount(1)
        ->and($visit->findings[0]->tooth)->toBe(36)
        ->and($visit->findings[0]->surfaces)->toBe(['O', 'M'])
        ->and($visit->diagnoses->firstWhere('is_primary', true)->icd10_code)->toBe('K04.0')
        ->and($visit->invoice->status)->toBe(Invoice::STATUS_DRAFT)
        ->and($visit->invoice->total)->toBe(700000)
        ->and($visit->prescriptions[0]->drug_name)->toBe('Amoksisilin 500 mg')
        ->and($visit->medicalRecord->follow_up_label)->toBe('Evaluasi 1 minggu')
        ->and($visit->medicalRecord->isFinal())->toBeFalse()
        ->and($visit->status)->toBe(VisitStatus::Diperiksa);
});

it('draf tindakan tidak muncul sebagai tagihan kasir', function () {
    expect(Invoice::where('status', Invoice::STATUS_UNPAID)
        ->whereHas('visit.patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))->exists())->toBeFalse();
});

it('dokter lain hanya bisa membaca (FR-RM-23)', function () {
    $visit = todayVisit('Ahmad Fauzi');
    $other = User::whereIn('username', ['nadia', 'arya', 'sinta'])->where('id', '!=', $visit->doctor->user_id)->first();
    loginAs($other->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->assertSee('Mode baca saja')
        ->call('saveDraft')
        ->assertForbidden();
});

it('owner bisa mengisi pemeriksaan atas nama dokter kunjungan', function () {
    $visit = todayVisit('Ahmad Fauzi');
    loginAs('owner', 'owner', 'all');

    Livewire::test(Exam::class, ['visit' => $visit])
        ->assertDontSee('Mode baca saja')
        ->call('addDiagnosis', 'K05.1')
        ->assertHasNoErrors();
});

it('Admin/Asisten hanya membaca pemeriksaan; yang memeriksa dokter (Owner boleh mengisi)', function () {
    $visit = todayVisit('Ahmad Fauzi');
    $status = $visit->status;
    loginAs('admin', 'admin', $this->batu->id);

    $this->get(route('rekam-medis.periksa', $visit))->assertOk()->assertSee('Mode baca saja');
    expect($visit->fresh()->status)->toBe($status); // membuka tidak memulai periksa
    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('addDiagnosis', 'K05.1')
        ->assertForbidden();
    $this->get(route('antrean'))->assertOk()->assertDontSee(route('rekam-medis.periksa', $visit), false);

    loginAs('owner', 'owner', $this->batu->id);
    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('addDiagnosis', 'K05.1')
        ->assertHasNoErrors();
});

it('koreksi setelah final membuat versi baru dan tagihan tidak berubah (FR-RM-22)', function () {
    $visit = todayVisit('Ahmad Fauzi');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('allergyConfirmed', true)
        ->call('confirmSend');

    Livewire::test(Exam::class, ['visit' => $visit->fresh()])
        ->assertSet('showSendReview', false)
        ->set('plan', 'Scaling ulang 2 minggu lagi.')
        ->set('correctionReason', '')
        ->call('saveCorrection')
        ->assertHasErrors('correctionReason')
        ->set('correctionReason', 'Rencana diperbarui')
        ->call('saveCorrection')
        ->assertHasNoErrors()
        ->call('removeProcedure', 0)
        ->assertForbidden();

    $record = $visit->fresh()->medicalRecord;

    expect($record->version)->toBe(2)
        ->and($record->plan)->toBe('Scaling ulang 2 minggu lagi.')
        ->and($record->versions()->count())->toBe(2)
        ->and($record->versions()->where('version', 1)->first()->snapshot['record']['plan'])->toContain('Evaluasi 6 bulan')
        ->and($visit->fresh()->invoice->total)->toBe(300000);
});

it('dokter dari cabang lain tidak bisa mengisi kunjungan (FR-AUT-05)', function () {
    $visit = todayVisit('Siti Aminah');
    loginAs($visit->doctor->user->username, 'dokter', $this->malang->id);

    Livewire::test(Exam::class, ['visit' => $visit])->assertSee('Mode baca saja');

    expect($visit->fresh()->status)->toBe(VisitStatus::Menunggu);
});

it('mencetak ringkasan rekam medis A4 (FR-RM-24)', function () {
    $visit = Visit::whereHas('patient', fn ($q) => $q->where('name', 'Rina Kusuma'))
        ->whereHas('medicalRecord')->firstOrFail();
    loginAs('owner', 'owner', 'all');

    $this->get(route('rekam-medis.cetak', $visit))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('mencari diagnosis dari kata "karang"', function () {
    expect(\App\Models\Icd10Code::search('karang')->first()->code)->toBe('K03.6');
});

it('menampilkan daftar pasien hari ini untuk dokter', function () {
    $visit = todayVisit('Ahmad Fauzi');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    $this->get(route('rekam-medis'))->assertOk()->assertSee('Ahmad Fauzi')->assertSee('Lanjutkan');
});

it('tindakan dipilih lewat kolom cari yang bisa diketik (nama/kode)', function () {
    $visit = todayVisit('Budi Santoso');
    loginAs($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('addingProcedure', true)
        ->assertSee('Cari tindakan (nama/kode)')
        ->assertSee('CBUT')
        ->assertDontSeeHtml('<option value="">Pilih tindakan');
});
