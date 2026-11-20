<?php

namespace App\Services\SatuSehat;

use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SatuSehatSubmission;
use App\Models\Visit;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Integrasi SATUSEHAT tahap 1: kunjungan yang dikirim ke kasir → Encounter + Condition (ICD-10).
 * Pengiriman lewat tabel antrean `satusehat_submissions` (perintah egao:satusehat-kirim tiap 5 menit),
 * tidak pernah di dalam transaksi rekam medis/kasir.
 */
class SatuSehatService
{
    private const NIK_SYSTEM = 'https://fhir.kemkes.go.id/id/nik';

    private const IHS_SYSTEM = 'https://fhir.kemkes.go.id/id/ihs-number';

    public function enabled(): bool
    {
        return Settings::bool('satusehat_enabled');
    }

    public function environment(): string
    {
        $env = (string) Settings::get('satusehat_env');

        return array_key_exists($env, config('satusehat.environments')) ? $env : 'sandbox';
    }

    public function client(Branch $branch): SatuSehatClient
    {
        return new SatuSehatClient($branch, $this->environment());
    }

    /** Tes kredensial: login lalu baca data Organization. Mengembalikan nama organisasi. */
    public function testConnection(Branch $branch): string
    {
        $client = $this->client($branch);
        $client->forgetToken();
        $org = $client->get('Organization/'.$branch->satusehat_org_id);

        return (string) ($org['name'] ?? $branch->satusehat_org_id);
    }

    /** Ruang praktik cabang di SATUSEHAT; dibuat sekali lalu ID-nya disimpan. */
    public function ensureLocation(Branch $branch): string
    {
        if (filled($branch->satusehat_location_id)) {
            return $branch->satusehat_location_id;
        }

        $org = $branch->satusehat_org_id;
        $result = $this->client($branch)->post('Location', [
            'resourceType' => 'Location',
            'identifier' => [['system' => "http://sys-ids.kemkes.go.id/location/{$org}", 'value' => 'POLI-GIGI-'.$branch->code]],
            'status' => 'active',
            'name' => 'Poli Gigi '.$branch->name,
            'description' => 'Ruang praktik dokter gigi Egao Dental Care '.$branch->name,
            'mode' => 'instance',
            'physicalType' => ['coding' => [[
                'system' => 'http://terminology.hl7.org/CodeSystem/location-physical-type', 'code' => 'ro', 'display' => 'Room',
            ]]],
            'managingOrganization' => ['reference' => "Organization/{$org}"],
        ]);

        $id = $result['id'] ?? null;
        if (! $id) {
            throw new SatuSehatException('SATUSEHAT tidak mengembalikan ID Location.');
        }

        $branch->forceFill(['satusehat_location_id' => $id])->save();

        return $id;
    }

    public function resolvePatient(Patient $patient, Branch $branch): string
    {
        if (filled($patient->ihs_patient_id)) {
            return $patient->ihs_patient_id;
        }
        if (blank($patient->nik)) {
            throw new SatuSehatException("NIK pasien {$patient->name} belum diisi. Lengkapi di Data pasien lalu kirim ulang.");
        }

        $id = $this->findByNik($branch, 'Patient', $patient->nik);
        if (! $id) {
            throw new SatuSehatException("NIK pasien {$patient->name} tidak ditemukan di SATUSEHAT. Periksa kembali NIK-nya.");
        }

        $patient->forceFill(['ihs_patient_id' => $id])->saveQuietly();

        return $id;
    }

    public function resolvePractitioner(Doctor $doctor, Branch $branch): string
    {
        if (filled($doctor->ihs_practitioner_id)) {
            return $doctor->ihs_practitioner_id;
        }
        if (blank($doctor->nik)) {
            throw new SatuSehatException("NIK dokter {$doctor->name} belum diisi. Lengkapi di Data dokter lalu kirim ulang.");
        }

        $id = $this->findByNik($branch, 'Practitioner', $doctor->nik);
        if (! $id) {
            throw new SatuSehatException("NIK dokter {$doctor->name} tidak ditemukan di SATUSEHAT (data tenaga kesehatan). Periksa kembali NIK-nya.");
        }

        $doctor->forceFill(['ihs_practitioner_id' => $id])->saveQuietly();

        return $id;
    }

    /**
     * Masukkan kunjungan ke antrean kirim (dipanggil setelah rekam medis dikirim ke kasir).
     * Tidak melakukan apa pun bila integrasi nonaktif, cabang belum punya kredensial, atau sudah terkirim.
     */
    public function queue(Visit $visit): ?SatuSehatSubmission
    {
        $branch = $visit->branch;
        if (! $this->enabled() || ! $branch?->hasSatuSehatCredentials()) {
            return null;
        }

        $submission = SatuSehatSubmission::firstOrNew(['visit_id' => $visit->id]);
        if ($submission->status === SatuSehatSubmission::SENT) {
            return $submission;
        }

        $submission->fill([
            'branch_id' => $visit->branch_id,
            'status' => SatuSehatSubmission::QUEUED,
            'attempts' => 0,
            'last_error' => null,
        ])->save();

        return $submission;
    }

    /** Kirim satu kunjungan. Hasil (terkirim / gagal + alasan) disimpan di baris submission. */
    public function send(SatuSehatSubmission $submission): SatuSehatSubmission
    {
        if ($submission->status === SatuSehatSubmission::SENT) {
            return $submission;
        }

        $visit = $submission->visit()->with(['patient', 'doctor', 'branch', 'diagnoses.icd10'])->first();
        $env = $this->environment();

        try {
            if (! $visit) {
                throw new SatuSehatException('Kunjungan tidak ditemukan.');
            }

            $result = $this->client($visit->branch)->post('', $this->bundle($visit));
            [$encounterId, $conditionIds] = $this->parseBundleResponse($result);

            if (! $encounterId) {
                throw new SatuSehatException('SATUSEHAT tidak mengembalikan ID Encounter.');
            }

            $submission->fill([
                'status' => SatuSehatSubmission::SENT,
                'encounter_id' => $encounterId,
                'condition_ids' => $conditionIds,
                'environment' => $env,
                'last_error' => null,
                'sent_at' => now(),
            ]);
        } catch (SatuSehatException $e) {
            $submission->fill(['status' => SatuSehatSubmission::FAILED, 'last_error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('SATUSEHAT: gagal mengirim kunjungan', ['visit_id' => $submission->visit_id, 'error' => $e->getMessage()]);
            $submission->fill(['status' => SatuSehatSubmission::FAILED, 'last_error' => 'Kesalahan sistem saat mengirim. Detail tercatat di log server.']);
        }

        $submission->attempts++;
        $submission->environment ??= $env;
        $submission->save();

        return $submission;
    }

    /**
     * Kirim antrean tertunda. Otomatis (jadwal): antre + gagal yang belum melewati batas percobaan.
     * Manual (tombol Owner): semua yang belum terkirim, tanpa batas percobaan. Mengembalikan [terkirim, gagal].
     *
     * @param  list<int>|null  $branchIds  batasi ke cabang tertentu (null = semua)
     */
    public function sendPending(?int $limit = null, bool $manual = false, ?array $branchIds = null): array
    {
        if (! $this->enabled()) {
            return [0, 0];
        }

        $sent = $failed = 0;
        SatuSehatSubmission::query()
            ->whereIn('status', [SatuSehatSubmission::QUEUED, SatuSehatSubmission::FAILED])
            ->unless($manual, fn ($q) => $q->where('attempts', '<', config('satusehat.max_attempts')))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('attempts')->orderBy('id')
            ->limit($limit ?? config('satusehat.batch_size'))
            ->get()
            ->each(function (SatuSehatSubmission $s) use (&$sent, &$failed) {
                $this->send($s)->status === SatuSehatSubmission::SENT ? $sent++ : $failed++;
            });

        return [$sent, $failed];
    }

    /** Bundle transaction: 1 Encounter + 1 Condition per diagnosis ICD-10. */
    public function bundle(Visit $visit): array
    {
        $branch = $visit->branch;
        $diagnoses = $visit->diagnoses;

        if ($diagnoses->isEmpty()) {
            throw new SatuSehatException('Kunjungan belum punya diagnosis ICD-10.');
        }

        $org = $branch->satusehat_org_id;
        $patientId = $this->resolvePatient($visit->patient, $branch);
        $practitionerId = $this->resolvePractitioner($visit->doctor, $branch);
        $locationId = $this->ensureLocation($branch);

        [$arrived, $inProgress, $finished] = $this->timeline($visit);
        $subject = ['reference' => "Patient/{$patientId}", 'display' => $visit->patient->name];
        $encounterUuid = (string) Str::uuid();

        $conditions = $diagnoses->values()->map(fn (Diagnosis $d) => [
            'uuid' => (string) Str::uuid(),
            'code' => $d->icd10_code,
            'display' => $d->icd10?->name_en ?? $d->icd10_code,
        ]);

        $encounter = [
            'resourceType' => 'Encounter',
            'identifier' => [['system' => "http://sys-ids.kemkes.go.id/encounter/{$org}", 'value' => (string) $visit->id]],
            'status' => 'finished',
            'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB', 'display' => 'ambulatory'],
            'subject' => $subject,
            'participant' => [[
                'type' => [['coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType', 'code' => 'ATND', 'display' => 'attender',
                ]]]],
                'individual' => ['reference' => "Practitioner/{$practitionerId}", 'display' => $visit->doctor->name],
            ]],
            'period' => ['start' => $arrived, 'end' => $finished],
            'location' => [['location' => ['reference' => "Location/{$locationId}", 'display' => 'Poli Gigi '.$branch->name]]],
            'diagnosis' => $conditions->map(fn ($c, $i) => [
                'condition' => ['reference' => "urn:uuid:{$c['uuid']}", 'display' => $c['display']],
                'use' => ['coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/diagnosis-role', 'code' => 'DD', 'display' => 'Discharge diagnosis',
                ]]],
                'rank' => $i + 1,
            ])->all(),
            'statusHistory' => [
                ['status' => 'arrived', 'period' => ['start' => $arrived, 'end' => $inProgress]],
                ['status' => 'in-progress', 'period' => ['start' => $inProgress, 'end' => $finished]],
                ['status' => 'finished', 'period' => ['start' => $finished, 'end' => $finished]],
            ],
            'serviceProvider' => ['reference' => "Organization/{$org}"],
        ];

        $entries = [[
            'fullUrl' => "urn:uuid:{$encounterUuid}",
            'resource' => $encounter,
            'request' => ['method' => 'POST', 'url' => 'Encounter'],
        ]];

        foreach ($conditions as $c) {
            $entries[] = [
                'fullUrl' => "urn:uuid:{$c['uuid']}",
                'resource' => [
                    'resourceType' => 'Condition',
                    'clinicalStatus' => ['coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical', 'code' => 'active', 'display' => 'Active',
                    ]]],
                    'category' => [['coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'encounter-diagnosis', 'display' => 'Encounter Diagnosis',
                    ]]]],
                    'code' => ['coding' => [['system' => 'http://hl7.org/fhir/sid/icd-10', 'code' => $c['code'], 'display' => $c['display']]]],
                    'subject' => $subject,
                    'encounter' => ['reference' => "urn:uuid:{$encounterUuid}", 'display' => 'Kunjungan '.$visit->patient->name],
                ],
                'request' => ['method' => 'POST', 'url' => 'Condition'],
            ];
        }

        return ['resourceType' => 'Bundle', 'type' => 'transaction', 'entry' => $entries];
    }

    /** Waktu datang → mulai diperiksa → selesai, dijamin berurutan. Format ISO 8601 dengan zona waktu. */
    private function timeline(Visit $visit): array
    {
        $arrived = $visit->created_at ?? now();
        $inProgress = $this->notBefore($visit->examined_at ?? $visit->called_at ?? $arrived, $arrived);
        $finished = $this->notBefore($visit->sent_to_cashier_at ?? $visit->completed_at ?? now(), $inProgress);

        return array_map(fn (CarbonInterface $t) => $t->toIso8601String(), [$arrived, $inProgress, $finished]);
    }

    private function notBefore(CarbonInterface $time, CarbonInterface $min): CarbonInterface
    {
        return $time->lt($min) ? $min : $time;
    }

    private function findByNik(Branch $branch, string $resource, string $nik): ?string
    {
        $bundle = $this->client($branch)->get($resource, ['identifier' => self::NIK_SYSTEM.'|'.$nik]);
        $resources = collect($bundle['entry'] ?? [])->pluck('resource')->filter();

        // Utamakan entri resmi yang memiliki nomor IHS; data uji lain di sandbox bisa ikut muncul.
        $official = $resources->first(fn ($r) => collect($r['identifier'] ?? [])->contains('system', self::IHS_SYSTEM));

        return ($official ?? $resources->first())['id'] ?? null;
    }

    /** @return array{0: ?string, 1: list<string>} */
    private function parseBundleResponse(array $result): array
    {
        $encounterId = null;
        $conditionIds = [];

        foreach ($result['entry'] ?? [] as $entry) {
            $response = $entry['response'] ?? [];
            $location = (string) ($response['location'] ?? '');
            $type = $response['resourceType'] ?? Str::before($location, '/');
            $id = $response['resourceID'] ?? ($entry['resource']['id'] ?? (Str::of($location)->explode('/')->get(1)));

            if ($type === 'Encounter') {
                $encounterId = $id;
            } elseif ($type === 'Condition' && $id) {
                $conditionIds[] = $id;
            }
        }

        return [$encounterId, $conditionIds];
    }
}
