<?php

use App\Livewire\MedicalRecords\Exam;
use App\Livewire\SatuSehatPanel;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Icd10Code;
use App\Models\Patient;
use App\Models\SatuSehatSubmission;
use App\Models\User;
use App\Models\Visit;
use App\Services\SatuSehat\SatuSehatService;
use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Cache::flush();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->batu->update([
        'satusehat_org_id' => 'org-123',
        'satusehat_client_id' => 'client-abc',
        'satusehat_client_secret' => 'rahasia-xyz',
    ]);
    Settings::set('satusehat_enabled', '1');
    Settings::set('satusehat_env', 'sandbox');
});

function ssLogin(string $username, string $role, string|int $branch): User
{
    $user = User::where('username', $username)->firstOrFail();
    test()->actingAs($user)->withSession([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);

    return $user;
}

/** Kunjungan Batu siap kirim: pasien & dokter ber-NIK, punya diagnosis ICD-10. */
function ssVisit(Branch $branch): Visit
{
    $visit = Visit::where('branch_id', $branch->id)->with(['patient', 'doctor'])->firstOrFail();
    $visit->patient->update(['nik' => '9271060312000001']);
    $visit->doctor->update(['nik' => '3507010101800001']);
    $visit->diagnoses()->delete();
    Diagnosis::create(['visit_id' => $visit->id, 'icd10_code' => Icd10Code::value('code'), 'is_primary' => true]);
    $visit->update(['sent_to_cashier_at' => now()]);

    return $visit->fresh();
}

function fakeSatuSehat(): void
{
    Http::fake([
        '*/oauth2/v1/accesstoken*' => Http::response(['access_token' => 'token-1', 'expires_in' => '3599']),
        '*/fhir-r4/v1/Patient*' => Http::response(['resourceType' => 'Bundle', 'total' => 2, 'entry' => [
            ['resource' => ['resourceType' => 'Patient', 'id' => 'uuid-uji-lain', 'identifier' => [['system' => 'https://fhir.kemkes.go.id/id/nik']]]],
            ['resource' => ['resourceType' => 'Patient', 'id' => 'P02478375538', 'identifier' => [['system' => 'https://fhir.kemkes.go.id/id/ihs-number', 'value' => 'P02478375538']]]],
        ]]),
        '*/fhir-r4/v1/Practitioner*' => Http::response(['resourceType' => 'Bundle', 'total' => 1, 'entry' => [
            ['resource' => ['resourceType' => 'Practitioner', 'id' => 'N10000001', 'identifier' => [['system' => 'https://fhir.kemkes.go.id/id/ihs-number', 'value' => 'N10000001']]]],
        ]]),
        '*/fhir-r4/v1/Location' => Http::response(['resourceType' => 'Location', 'id' => 'loc-1'], 201),
        '*/fhir-r4/v1/Organization/*' => Http::response(['resourceType' => 'Organization', 'id' => 'org-123', 'name' => 'Klinik Uji']),
        '*/fhir-r4/v1' => Http::response(['resourceType' => 'Bundle', 'type' => 'transaction-response', 'entry' => [
            ['response' => ['status' => '201 Created', 'resourceType' => 'Encounter', 'resourceID' => 'enc-1', 'location' => 'Encounter/enc-1/_history/1']],
            ['response' => ['status' => '201 Created', 'resourceType' => 'Condition', 'resourceID' => 'cond-1', 'location' => 'Condition/cond-1/_history/1']],
        ]]),
    ]);
}

it('kunjungan yang dikirim ke kasir masuk antrean satusehat', function () {
    $visit = Visit::whereDate('visit_date', today())->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))->with('doctor')->firstOrFail();
    ssLogin($visit->doctor->user->username, 'dokter', $this->batu->id);

    Livewire::test(Exam::class, ['visit' => $visit])
        ->call('confirmSend')
        ->call('confirmAllergyAndSend');

    $submission = SatuSehatSubmission::where('visit_id', $visit->id)->first();
    expect($submission)->not->toBeNull()
        ->and($submission->status)->toBe(SatuSehatSubmission::QUEUED);
});

it('tidak mengantrekan bila integrasi nonaktif atau kredensial cabang kosong', function () {
    $visit = ssVisit($this->batu);
    $service = app(SatuSehatService::class);

    Settings::set('satusehat_enabled', '0');
    expect($service->queue($visit))->toBeNull();

    Settings::set('satusehat_enabled', '1');
    $this->batu->update(['satusehat_client_id' => null]);
    expect($service->queue($visit->fresh()))->toBeNull();
    expect(SatuSehatSubmission::count())->toBe(0);
});

it('mengirim encounter dan diagnosis icd-10 dalam satu bundle', function () {
    fakeSatuSehat();
    $visit = ssVisit($this->batu);
    $service = app(SatuSehatService::class);

    $submission = $service->send($service->queue($visit));

    expect($submission->status)->toBe(SatuSehatSubmission::SENT)
        ->and($submission->encounter_id)->toBe('enc-1')
        ->and($submission->condition_ids)->toBe(['cond-1'])
        ->and($submission->environment)->toBe('sandbox')
        ->and($visit->patient->fresh()->ihs_patient_id)->toBe('P02478375538')
        ->and($visit->doctor->fresh()->ihs_practitioner_id)->toBe('N10000001')
        ->and($this->batu->fresh()->satusehat_location_id)->toBe('loc-1');

    $code = $visit->diagnoses->first()->icd10_code;
    Http::assertSent(function (Request $request) use ($code, $visit) {
        if (! str_ends_with($request->url(), '/fhir-r4/v1') || $request['resourceType'] !== 'Bundle') {
            return false;
        }
        [$enc, $cond] = $request['entry'];

        return $request['type'] === 'transaction'
            && $enc['resource']['subject']['reference'] === 'Patient/P02478375538'
            && $enc['resource']['participant'][0]['individual']['reference'] === 'Practitioner/N10000001'
            && $enc['resource']['location'][0]['location']['reference'] === 'Location/loc-1'
            && $enc['resource']['serviceProvider']['reference'] === 'Organization/org-123'
            && $enc['resource']['identifier'][0]['value'] === (string) $visit->id
            && $cond['resource']['code']['coding'][0]['code'] === $code
            && $cond['resource']['encounter']['reference'] === $enc['fullUrl'];
    });
});

it('gagal dengan alasan jelas bila nik pasien kosong lalu berhasil setelah dikirim ulang', function () {
    fakeSatuSehat();
    $visit = ssVisit($this->batu);
    $visit->patient->update(['nik' => null]);
    $service = app(SatuSehatService::class);

    $submission = $service->send($service->queue($visit));
    expect($submission->status)->toBe(SatuSehatSubmission::FAILED)
        ->and($submission->last_error)->toContain('NIK pasien')
        ->and($submission->attempts)->toBe(1);
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/fhir-r4/v1'));

    $visit->patient->update(['nik' => '9271060312000001']);
    expect($service->send($submission->fresh())->status)->toBe(SatuSehatSubmission::SENT);
});

it('gagal bila kunjungan belum punya diagnosis', function () {
    fakeSatuSehat();
    $visit = ssVisit($this->batu);
    $visit->diagnoses()->delete();
    $service = app(SatuSehatService::class);

    $submission = $service->send($service->queue($visit));
    expect($submission->status)->toBe(SatuSehatSubmission::FAILED)
        ->and($submission->last_error)->toContain('diagnosis');
});

it('menyimpan token di cache dan tidak mengirim ulang kunjungan yang sudah terkirim', function () {
    fakeSatuSehat();
    $service = app(SatuSehatService::class);
    $visit = ssVisit($this->batu);
    $submission = $service->send($service->queue($visit));

    $service->send($submission);
    $service->queue($visit);

    expect($submission->fresh()->status)->toBe(SatuSehatSubmission::SENT);
    Http::assertSentCount(5); // token + Patient + Practitioner + Location + Bundle, masing-masing sekali
});

it('pesan error satusehat diterjemahkan dari operation outcome', function () {
    Http::fake([
        '*/oauth2/v1/accesstoken*' => Http::response(['access_token' => 'token-1']),
        '*/fhir-r4/v1/Organization/*' => Http::response(['resourceType' => 'OperationOutcome', 'issue' => [
            ['severity' => 'error', 'code' => 'not-found', 'details' => ['text' => 'Organization tidak ditemukan']],
        ]], 404),
    ]);

    expect(fn () => app(SatuSehatService::class)->testConnection($this->batu))
        ->toThrow(\App\Services\SatuSehat\SatuSehatException::class, 'Organization tidak ditemukan');
});

it('perintah terjadwal mematuhi batas percobaan', function () {
    fakeSatuSehat();
    $visit = ssVisit($this->batu);
    SatuSehatSubmission::create([
        'visit_id' => $visit->id, 'branch_id' => $this->batu->id, 'status' => SatuSehatSubmission::FAILED,
        'attempts' => config('satusehat.max_attempts'),
    ]);

    $this->artisan('egao:satusehat-kirim')->assertSuccessful();
    expect(SatuSehatSubmission::first()->status)->toBe(SatuSehatSubmission::FAILED);

    expect(app(SatuSehatService::class)->sendPending(null, true))->toBe([1, 0]);
});

it('client secret tersimpan terenkripsi', function () {
    $raw = DB::table('branches')->where('id', $this->batu->id)->value('satusehat_client_secret');

    expect($raw)->not->toBe('rahasia-xyz')
        ->and($this->batu->fresh()->satusehat_client_secret)->toBe('rahasia-xyz');
});

it('panel satusehat hanya untuk owner', function () {
    ssLogin('admin', 'admin', $this->batu->id);

    Livewire::test(SatuSehatPanel::class)->assertForbidden();
});

it('owner menyimpan kredensial; secret kosong tidak menimpa dan ganti environment mereset id ihs', function () {
    ssLogin('owner', 'owner', 'all');
    $patient = Patient::first();
    $patient->forceFill(['ihs_patient_id' => 'P-LAMA'])->saveQuietly();
    $this->batu->update(['satusehat_location_id' => 'loc-lama']);

    Livewire::test(SatuSehatPanel::class)
        ->set("creds.{$this->batu->id}.org", 'org-baru')
        ->set("creds.{$this->batu->id}.secret", '')
        ->set('env', 'production')
        ->call('saveConfig')
        ->assertHasNoErrors();

    $batu = $this->batu->fresh();
    expect($batu->satusehat_org_id)->toBe('org-baru')
        ->and($batu->satusehat_client_secret)->toBe('rahasia-xyz')
        ->and($batu->satusehat_location_id)->toBeNull()
        ->and($patient->fresh()->ihs_patient_id)->toBeNull()
        ->and(Settings::get('satusehat_env'))->toBe('production');
});

it('owner dapat tes koneksi dari panel', function () {
    fakeSatuSehat();
    ssLogin('owner', 'owner', 'all');

    Livewire::test(SatuSehatPanel::class)
        ->call('testConnection', $this->batu->id)
        ->assertSet("results.{$this->batu->id}.ok", true)
        ->assertSee('Terhubung ke Klinik Uji');
});

it('mengubah nik pasien mereset id ihs', function () {
    $patient = Patient::first();
    $patient->forceFill(['ihs_patient_id' => 'P-LAMA'])->saveQuietly();

    $patient->update(['nik' => '3507019999990001']);

    expect($patient->fresh()->ihs_patient_id)->toBeNull();
});

it('tab satusehat tampil di pengaturan untuk owner', function () {
    ssLogin('owner', 'owner', 'all');

    $this->get(route('pengaturan', ['tab' => 'satusehat']))
        ->assertOk()
        ->assertSee('Koneksi SATUSEHAT')
        ->assertSee('Pengiriman kunjungan')
        ->assertDontSee('rahasia-xyz');
});

it('nik dokter divalidasi 16 digit dan disimpan', function () {
    ssLogin('owner', 'owner', 'all');
    $doctor = \App\Models\Doctor::first();

    Livewire::test(\App\Livewire\Modules\Dokter::class)
        ->call('edit', $doctor->id)
        ->set('joined_at', '2024-01-01')
        ->set('nik', '12345')
        ->call('save')
        ->assertHasErrors(['nik'])
        ->set('nik', '3507010101800001')
        ->call('save')
        ->assertHasNoErrors();

    expect($doctor->fresh()->nik)->toBe('3507010101800001');
});
