<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Livewire\Concerns\ModulePage;
use App\Livewire\Concerns\PicksBranch;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * 02 Antrean (FR-ANT-01 … 11): daftar kunjungan, panggil, periksa, kirim ke kasir.
 */
class Antrean extends Component
{
    use ModulePage, PicksBranch;

    /** Tab antrean: menunggu | selesai (selesai tindakan) | batal (tidak hadir). */
    #[Url(as: 'status', except: 'menunggu')]
    public string $filter = 'menunggu';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Dokter: tampilkan pasien sendiri saja. */
    #[Url(as: 'saya', except: false)]
    public bool $mine = false;

    public bool $showForm = false;

    public string $patientSearch = '';

    public array $visit = [];

    public function mount(BranchContext $context): void
    {
        $this->mine = $this->mine || $context->is(Role::Dokter);

        if ($id = request()->integer('pasien')) {
            $this->register($id);
        }
    }

    // ---- Pendaftaran (FR-ANT-01/02/03) ------------------------------------------------

    public function register(?int $patientId = null): void
    {
        $context = app(BranchContext::class);
        abort_unless($context->is(Role::Admin, Role::Owner), 403);

        $this->resetValidation();
        $this->patientSearch = '';
        $this->visit = [
            'patient_id' => $patientId && Patient::where('clinic_id', $this->clinicId())->whereKey($patientId)->exists() ? $patientId : null,
            'doctor_id' => $this->doctorsOnDuty($context)->first()['doctor']->id ?? null,
            'scheduled_at' => now()->format('H:i'),
            'purpose' => '',
            'complaint' => '',
        ];
        $this->showForm = true;
    }

    public function pickPatient(int $id): void
    {
        $this->visit['patient_id'] = $id;
        $this->patientSearch = '';
    }

    public function save(BranchContext $context): void
    {
        abort_unless($context->is(Role::Admin, Role::Owner), 403);
        $branchId = $this->targetBranch($context);

        $data = $this->validate([
            'visit.patient_id' => ['required', Rule::exists('patients', 'id')->where('clinic_id', $this->clinicId())],
            'visit.doctor_id' => ['required', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId())->where('is_active', true)],
            'visit.scheduled_at' => ['nullable', 'date_format:H:i'],
            'visit.purpose' => ['required', 'string', 'max:100'],
            'visit.complaint' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'visit.patient_id' => 'pasien', 'visit.doctor_id' => 'dokter', 'visit.purpose' => 'keperluan',
        ])['visit'];

        $today = today()->toDateString();

        // FR-ANT-04: pasien yang masih aktif di antrean hari ini tidak didaftarkan dua kali.
        $duplicate = Visit::where('patient_id', $data['patient_id'])
            ->whereDate('visit_date', $today)
            ->whereIn('status', VisitStatus::active())
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['visit.patient_id' => 'Pasien ini masih ada di antrean hari ini.']);
        }

        $visit = DB::transaction(function () use ($data, $branchId, $today) {
            DB::table('doctors')->where('id', $data['doctor_id'])->lockForUpdate()->first();

            return Visit::create([
                'patient_id' => $data['patient_id'],
                'doctor_id' => $data['doctor_id'],
                'branch_id' => $branchId,
                'registered_by' => auth()->id(),
                'visit_date' => $today,
                'queue_number' => Visit::nextQueueNumber($branchId, (int) $data['doctor_id'], $today),
                'scheduled_at' => $data['scheduled_at'] ?: null,
                'purpose' => $data['purpose'],
                'complaint' => $data['complaint'] ?: null,
                'status' => VisitStatus::Menunggu,
            ]);
        });

        AuditLog::record('visit.registered', $visit);
        $this->showForm = false;
        $this->toast("Nomor antrean {$visit->queueLabel()} dibuat.");
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    // ---- Alur status (SRS §3.4.1) -----------------------------------------------------

    public function examine(int $id, BranchContext $context)
    {
        abort_unless($context->is(Role::Dokter, Role::Admin, Role::Owner), 403);
        $visit = $this->find($id, $context);

        return $this->redirectRoute('rekam-medis.periksa', ['visit' => $visit->id], navigate: true);
    }

    public function backToWaiting(int $id, BranchContext $context): void
    {
        $visit = $this->find($id, $context);
        if (in_array($visit->status, [VisitStatus::Dipanggil, VisitStatus::Batal], true)) {
            $visit->update(['status' => VisitStatus::Menunggu, 'called_at' => null]);
            AuditLog::record('visit.requeued', $visit);
        }
    }

    /** FR-ANT-08: tidak hadir / dibatalkan. Kunjungan yang sudah diperiksa tidak bisa dibatalkan di sini. */
    public function cancel(int $id, BranchContext $context): void
    {
        $visit = $this->find($id, $context);
        abort_unless(in_array($visit->status, [VisitStatus::Menunggu, VisitStatus::Dipanggil], true), 422);
        $visit->update(['status' => VisitStatus::Batal]);
        AuditLog::record('visit.cancelled', $visit);
        $this->toast('Ditandai tidak hadir.');
    }

    private function find(int $id, BranchContext $context): Visit
    {
        return Visit::forBranch($context->id())
            ->whereHas('branch', fn ($q) => $q->whereIn('id', $context->options()->pluck('id')))
            ->with('patient')
            ->findOrFail($id);
    }

    /** Dokter yang praktik hari ini di cabang aktif; bila kosong, semua dokter aktif. */
    private function doctorsOnDuty(BranchContext $context)
    {
        $today = today();

        $doctors = Doctor::query()
            ->where('clinic_id', $this->clinicId())
            ->where('is_active', true)
            ->with(['schedules', 'overrides' => fn ($q) => $q->whereDate('date', $today)])
            ->orderBy('name')
            ->get()
            ->map(fn (Doctor $d) => ['doctor' => $d, 'schedule' => $d->scheduleOn($today)]);

        $onDuty = $doctors->filter(fn ($r) => $r['schedule'] && $r['schedule']['branch_id'] === $this->formBranchId($context))->values();

        return $onDuty->isNotEmpty() ? $onDuty : $doctors->map(fn ($r) => ['doctor' => $r['doctor'], 'schedule' => null]);
    }

    public function render(BranchContext $context)
    {
        $myDoctorId = $context->is(Role::Dokter) ? auth()->user()->doctor?->id : null;

        $visits = Visit::query()
            ->forBranch($context->id())
            ->whereIn('branch_id', $context->options()->pluck('id'))
            ->whereDate('visit_date', today())
            ->when($this->mine && $myDoctorId, fn ($q) => $q->where('doctor_id', $myDoctorId))
            ->with(['patient', 'doctor', 'branch'])
            ->orderBy('branch_id')->orderBy('doctor_id')->orderBy('queue_number')
            ->get();

        $counts = ['menunggu' => 0, 'selesai' => 0, 'batal' => 0];
        foreach ($visits as $v) {
            $counts[$v->status->queueGroup()]++;
        }
        $tab = array_key_exists($this->filter, $counts) ? $this->filter : 'menunggu';

        $list = $visits
            ->filter(fn (Visit $v) => $v->status->queueGroup() === $tab)
            ->when($this->search !== '', function ($c) {
                $term = mb_strtolower($this->search);

                return $c->filter(fn (Visit $v) => str_contains(mb_strtolower($v->patient->name), $term)
                    || str_contains(mb_strtolower($v->patient->rmCode()), $term));
            });

        $candidates = $this->showForm && mb_strlen(trim($this->patientSearch)) >= 2
            ? Patient::where('clinic_id', $this->clinicId())->search($this->patientSearch)->orderBy('name')->limit(8)->get()
            : collect();

        $chosen = $this->showForm && ($this->visit['patient_id'] ?? null)
            ? Patient::where('clinic_id', $this->clinicId())->find($this->visit['patient_id'])
            : null;

        return $this->page('antrean', 'livewire.modules.antrean', [
            'context' => $context,
            'visits' => $list,
            'counts' => $counts,
            'tab' => $tab,
            'canRegister' => $context->is(Role::Admin, Role::Owner),
            'canExamine' => $context->is(Role::Dokter, Role::Admin, Role::Owner),
            'isDoctor' => (bool) $myDoctorId,
            'candidates' => $candidates,
            'chosen' => $chosen,
            'doctorOptions' => $this->showForm ? $this->doctorsOnDuty($context) : collect(),
        ]);
    }
}
