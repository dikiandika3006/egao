<?php

use App\Livewire\MedicalRecords\Exam;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Procedure;
use App\Models\ProcedurePrice;
use App\Models\User;
use App\Models\Visit;
use App\Support\Access;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
});

function periksaLogin(Visit $visit, string $role = 'dokter'): void
{
    $user = $role === 'dokter' ? $visit->doctor->user : User::where('username', 'admin')->firstOrFail();
    test()->actingAs($user)->withSession([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $visit->branch_id]);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $visit->branch_id]);
    Access::flush();
}

function kunjunganHariIni(string $patientName): Visit
{
    return Visit::whereDate('visit_date', today())
        ->whereHas('patient', fn ($q) => $q->where('name', $patientName))
        ->with('doctor.user')->firstOrFail();
}

it('obat dari resep tidak terbaca sebagai tindakan saat kunjungan dibuka lagi', function () {
    $visit = kunjunganHariIni('Ahmad Fauzi');
    periksaLogin($visit);
    $amx = Item::where('code', 'AMX500')->firstOrFail();

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('addPrescription')
        ->set('prescriptions.0.item_id', $amx->id)
        ->set('prescriptions.0.item_quantity', 10)
        ->set('prescriptions.0.dose', '3×1 sesudah makan')
        ->call('confirmSend')
        ->call('confirmChecksAndSend')
        ->assertHasNoErrors();

    expect($visit->fresh()->invoice->items()->whereNull('procedure_id')->count())->toBe(1);

    $exam = Livewire::test(Exam::class, ['visit' => $visit->fresh()]);
    $procedures = collect($exam->get('procedures'));

    expect($procedures)->toHaveCount(2)
        ->and($procedures->pluck('procedure_id')->filter()->count())->toBe(2)
        ->and($exam->get('prescriptions.0.item_id'))->toBe($amx->id);

    $exam->assertDontSee('Tidak tersedia / tanpa tarif');
});

it('alergi & tindakan Rp0 dikonfirmasi dalam satu modal periksa sebelum kirim', function () {
    $visit = kunjunganHariIni('Ahmad Fauzi');
    periksaLogin($visit);
    $konsultasi = Procedure::where('code', 'KONS')->firstOrFail();
    ProcedurePrice::where('procedure_id', $konsultasi->id)->where('branch_id', $this->batu->id)->update(['price' => 0]);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->assertSee('Tarif Rp0')
        ->call('confirmSend')
        ->assertSet('showSendReview', true)
        ->assertSee('Alergi belum dikonfirmasi')
        ->assertSee('Ada tindakan bertarif Rp0')
        ->call('confirmChecksAndSend')
        ->assertHasNoErrors()
        ->assertRedirect(route('rekam-medis'));

    expect($visit->fresh()->medicalRecord->allergy_confirmed)->toBeTruthy();
});

it('tindakan Rp0 saja (alergi sudah dicek) tetap memunculkan modal', function () {
    $visit = kunjunganHariIni('Ahmad Fauzi');
    periksaLogin($visit);
    $konsultasi = Procedure::where('code', 'KONS')->firstOrFail();
    ProcedurePrice::where('procedure_id', $konsultasi->id)->where('branch_id', $this->batu->id)->update(['price' => 0]);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertSet('showSendReview', true)
        ->assertDontSee('Alergi belum dikonfirmasi');
});

it('isian wajib kosong langsung tampil sebagai error tanpa modal', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('complaint', '')
        ->call('confirmSend')
        ->assertSet('showSendReview', false)
        ->assertHasErrors(['complaint']);
});

it('form tambah tindakan mengisi gigi dari peta gigi', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('selectTooth', 36)
        ->set('addingProcedure', true)
        ->assertSet('newTooth', '36');
});

it('menandai isian belum disimpan dan bersih lagi setelah simpan draf', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->assertSet('unsaved', false)
        ->set('plan', 'Kontrol 1 minggu.')
        ->assertSet('unsaved', true)
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSet('unsaved', false);
});

it('menampilkan indikator kelengkapan di bar aksi', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->assertSee('Keluhan')
        ->assertSee('Alergi dicek')
        ->assertSee('Resep');
});

it('menolak format tekanan darah yang salah', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('bloodPressure', '12080')
        ->call('saveDraft')
        ->assertHasErrors('bloodPressure')
        ->set('bloodPressure', '120/80 mmHg')
        ->call('saveDraft')
        ->assertHasNoErrors();
});

it('menyarankan tindakan dari diagnosis dan menambahkannya sekali klik', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);
    $scaling = Procedure::where('code', 'SCAL')->firstOrFail();

    $exam = Livewire::test(Exam::class, ['visit' => $visit])
        ->call('addDiagnosis', 'K03.6');

    expect(collect($exam->get('procedures'))->pluck('procedure_id'))->not->toContain($scaling->id);

    expect($exam->instance()->suggestions->pluck('id'))->toContain($scaling->id);

    $exam->call('selectTooth', 16)
        ->call('addSuggestedProcedure', $scaling->id)
        ->assertSee('Saran dari diagnosis', false);

    expect(collect($exam->get('procedures'))->firstWhere('procedure_id', $scaling->id))
        ->toMatchArray(['quantity' => 1, 'tooth' => '16'])
        ->and($exam->instance()->suggestions->pluck('id'))->not->toContain($scaling->id);
});

it('peran tanpa izin Edit rekam medis tidak bisa menambah tindakan saran', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    $matrix = Access::matrix();
    $matrix['dokter']['rekam-medis']['edit'] = false;
    Access::save($matrix);
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('addSuggestedProcedure', Procedure::where('code', 'SCAL')->value('id'))
        ->assertForbidden();
});

it('kartu resep tampil terpisah dengan pencarian obat dan pilihan aturan pakai', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('addPrescription')
        ->assertSee('Ketik nama obat…')
        ->assertSee('sesudah makan')
        ->assertSee('Obat luar / tidak dari stok klinik');
});

it('klik kondisi di panel gigi langsung mencatat temuan', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);
    $karies = \App\Models\ToothConditionType::where('code', 'KAR')->firstOrFail();

    $exam = Livewire::test(Exam::class, ['visit' => $visit])
        ->call('selectTooth', 36)
        ->call('toggleSurface', 'O')
        ->set('findingNote', 'Dalam')
        ->call('recordFinding', $karies->id);

    expect(collect($exam->get('findings'))->last())
        ->toMatchArray(['tooth' => 36, 'condition_id' => $karies->id, 'surfaces' => ['O'], 'note' => 'Dalam'])
        ->and($exam->get('selectedSurfaces'))->toBe([]);
});

it('temuan karies menyarankan diagnosis K02.9 lalu tindakan dengan gigi terisi', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);
    $karies = \App\Models\ToothConditionType::where('code', 'KAR')->firstOrFail();

    $exam = Livewire::test(Exam::class, ['visit' => $visit])
        ->set('diagnoses', [])
        ->set('findings', [])
        ->call('selectTooth', 46)
        ->call('recordFinding', $karies->id)
        ->call('selectTooth', 46) // tutup panel: gigi tidak lagi dipilih
        ->assertSee('Saran dari peta gigi');

    expect($exam->instance()->diagnosisSuggestions->pluck('code')->all())->toBe(['K02.9']);

    $exam->call('addDiagnosis', 'K02.9');
    expect($exam->instance()->diagnosisSuggestions)->toBeEmpty();

    $saran = $exam->instance()->suggestions->first();
    expect($saran)->not->toBeNull();

    $exam->call('addSuggestedProcedure', $saran->id);
    expect(collect($exam->get('procedures'))->last())->toMatchArray(['procedure_id' => $saran->id, 'tooth' => '46', 'quantity' => 1]);
});

it('gigi tidak diisi otomatis bila temuan relevan lebih dari satu gigi', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);
    $karies = \App\Models\ToothConditionType::where('code', 'KAR')->firstOrFail();

    $exam = Livewire::test(Exam::class, ['visit' => $visit])
        ->set('diagnoses', [])
        ->set('findings', [])
        ->call('selectTooth', 46)->call('recordFinding', $karies->id)
        ->call('selectTooth', 36)->call('recordFinding', $karies->id)
        ->call('selectTooth', 36)
        ->call('addDiagnosis', 'K02.9');

    $saran = $exam->instance()->suggestions->first();
    $exam->call('addSuggestedProcedure', $saran->id);

    expect(collect($exam->get('procedures'))->last()['tooth'])->toBeNull();
});

it('evaluasi ulang dipilih lewat tombol', function () {
    $visit = kunjunganHariIni('Budi Santoso');
    periksaLogin($visit);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->assertSee('Evaluasi ulang')
        ->assertSee('2 mgg')
        ->set('followUp', '2 weeks')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($visit->fresh()->medicalRecord->follow_up_label)->toBe('Evaluasi 2 minggu');
});
