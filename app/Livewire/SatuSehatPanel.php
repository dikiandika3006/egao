<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SatuSehatSubmission;
use App\Services\SatuSehat\SatuSehatException;
use App\Services\SatuSehat\SatuSehatService;
use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Panel Pengaturan → SATUSEHAT (khusus Owner): saklar aktif, Sandbox/Production, kredensial per cabang,
 * tes koneksi, buat Location, dan daftar pengiriman kunjungan (kirim ulang).
 */
class SatuSehatPanel extends Component
{
    use WithPagination;

    public bool $enabled = false;

    public string $env = 'sandbox';

    /** [branch_id => ['org' => , 'client' => , 'secret' => '']] — secret tidak pernah diisi dari DB. */
    public array $creds = [];

    /** Hasil tes koneksi terakhir per cabang: [branch_id => ['ok' => bool, 'message' => string]]. */
    public array $results = [];

    public string $statusFilter = '';

    public function mount(SatuSehatService $satusehat): void
    {
        $this->authorizeOwner();
        $this->enabled = $satusehat->enabled();
        $this->env = $satusehat->environment();
        $this->creds = $this->branches()->mapWithKeys(fn (Branch $b) => [$b->id => [
            'org' => (string) $b->satusehat_org_id,
            'client' => (string) $b->satusehat_client_id,
            'secret' => '',
        ]])->all();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage('kirim');
    }

    public function saveConfig(): void
    {
        $this->authorizeOwner();

        $this->validate([
            'env' => ['required', Rule::in(array_keys(config('satusehat.environments')))],
            'creds.*.org' => ['nullable', 'string', 'max:100'],
            'creds.*.client' => ['nullable', 'string', 'max:255'],
            'creds.*.secret' => ['nullable', 'string', 'max:255'],
        ], [], ['creds.*.org' => 'Organization ID', 'creds.*.client' => 'Client ID', 'creds.*.secret' => 'Client Secret']);

        $branches = $this->branches();
        $envChanged = $this->env !== (string) Settings::get('satusehat_env');

        DB::transaction(function () use ($branches, $envChanged) {
            Settings::set('satusehat_enabled', $this->enabled ? '1' : '0');
            Settings::set('satusehat_env', $this->env);

            // ID IHS & Location hanya berlaku di environment tempat ID itu dibuat.
            if ($envChanged) {
                Patient::where('clinic_id', $this->clinicId())->whereNotNull('ihs_patient_id')->update(['ihs_patient_id' => null]);
                Doctor::where('clinic_id', $this->clinicId())->whereNotNull('ihs_practitioner_id')->update(['ihs_practitioner_id' => null]);
            }

            foreach ($branches as $branch) {
                $input = $this->creds[$branch->id] ?? [];
                $org = trim((string) ($input['org'] ?? '')) ?: null;

                $branch->satusehat_org_id = $org;
                $branch->satusehat_client_id = trim((string) ($input['client'] ?? '')) ?: null;
                if (trim((string) ($input['secret'] ?? '')) !== '') {
                    $branch->satusehat_client_secret = trim($input['secret']);
                }
                if ($envChanged || $branch->isDirty('satusehat_org_id')) {
                    $branch->satusehat_location_id = null;
                }
                $branch->save();
            }
        });

        foreach ($this->creds as $id => $c) {
            $this->creds[$id]['secret'] = '';
        }
        $this->results = [];

        AuditLog::record('satusehat.pengaturan', null, [
            'aktif' => $this->enabled, 'environment' => $this->env, 'ganti_environment' => $envChanged,
        ]);
        $this->dispatch('toast', message: 'Pengaturan SATUSEHAT disimpan.');
    }

    public function testConnection(int $branchId, SatuSehatService $satusehat): void
    {
        $this->authorizeOwner();
        $branch = $this->findBranch($branchId);

        try {
            $name = $satusehat->testConnection($branch);
            $this->results[$branchId] = ['ok' => true, 'message' => "Terhubung ke {$name}."];
        } catch (SatuSehatException $e) {
            $this->results[$branchId] = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function createLocation(int $branchId, SatuSehatService $satusehat): void
    {
        $this->authorizeOwner();
        $branch = $this->findBranch($branchId);

        try {
            $id = $satusehat->ensureLocation($branch);
            AuditLog::record('satusehat.buat_location', $branch, ['location_id' => $id]);
            $this->results[$branchId] = ['ok' => true, 'message' => 'Location ruang praktik siap.'];
        } catch (SatuSehatException $e) {
            $this->results[$branchId] = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function resend(int $id, SatuSehatService $satusehat): void
    {
        $this->authorizeOwner();
        $submission = SatuSehatSubmission::whereIn('branch_id', $this->branches()->pluck('id'))->findOrFail($id);

        if (! $satusehat->enabled()) {
            $this->dispatch('toast', message: 'Aktifkan integrasi SATUSEHAT terlebih dulu.');

            return;
        }

        $satusehat->send($submission);
        AuditLog::record('satusehat.kirim_ulang', $submission->visit, ['status' => $submission->status]);
        $this->dispatch('toast', message: $submission->status === SatuSehatSubmission::SENT ? 'Kunjungan terkirim ke SATUSEHAT.' : 'Masih gagal: '.$submission->last_error);
    }

    public function sendAll(SatuSehatService $satusehat): void
    {
        $this->authorizeOwner();

        if (! $satusehat->enabled()) {
            $this->dispatch('toast', message: 'Aktifkan integrasi SATUSEHAT terlebih dulu.');

            return;
        }

        [$sent, $failed] = $satusehat->sendPending(null, true, $this->branches()->pluck('id')->all());
        AuditLog::record('satusehat.kirim_semua', null, ['terkirim' => $sent, 'gagal' => $failed]);
        $this->dispatch('toast', message: "{$sent} terkirim, {$failed} gagal.");
    }

    private function authorizeOwner(): void
    {
        abort_unless(app(BranchContext::class)->is(Role::Owner), 403);
    }

    private function clinicId(): int
    {
        return (int) auth()->user()->clinic_id;
    }

    private function branches()
    {
        return Branch::where('clinic_id', $this->clinicId())->orderBy('id')->get();
    }

    private function findBranch(int $id): Branch
    {
        return Branch::where('clinic_id', $this->clinicId())->findOrFail($id);
    }

    public function render()
    {
        $branches = $this->branches();
        $branchIds = $branches->pluck('id');

        $base = SatuSehatSubmission::whereIn('branch_id', $branchIds);

        return view('livewire.satu-sehat-panel', [
            'branches' => $branches,
            'environments' => config('satusehat.environments'),
            'statuses' => SatuSehatSubmission::STATUSES,
            'counts' => (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'submissions' => (clone $base)
                ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
                ->with(['visit.patient', 'visit.doctor', 'branch'])
                ->latest('id')
                ->paginate(15, pageName: 'kirim'),
        ]);
    }
}
