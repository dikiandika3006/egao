<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Visit;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 03 Data pasien (FR-PAS-01 … 20): satu data pasien untuk semua cabang.
 */
class Pasien extends Component
{
    use ModulePage, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Pasien yang sedang dibuka di panel detail. */
    #[Url(as: 'id', except: null)]
    public ?int $selected = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $patient = [];

    /** Data sensitif yang sedang dibuka (FR-PAS-14/15), dicatat di jejak audit. */
    public bool $reveal = false;

    public function mount(): void
    {
        if (request()->boolean('baru')) {
            $this->create();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $this->selected = $id;
        $this->reveal = false;
    }

    public function close(): void
    {
        $this->selected = null;
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->patient = [
            'name' => '', 'gender' => 'P', 'birth_date' => '', 'phone' => '', 'nik' => '', 'address' => '',
            'occupation' => '', 'emergency_contact' => '', 'allergies' => 'Tidak ada',
        ];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = $this->query()->findOrFail($id);
        $this->resetValidation();
        $this->editingId = $p->id;
        $this->patient = [
            'name' => $p->name, 'gender' => $p->gender, 'birth_date' => $p->birth_date->toDateString(),
            'phone' => (string) $p->phone, 'nik' => (string) $p->nik, 'address' => (string) $p->address,
            'occupation' => (string) $p->occupation, 'emergency_contact' => (string) $p->emergency_contact,
            'allergies' => (string) $p->allergies,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'patient.name' => ['required', 'string', 'max:120'],
            'patient.gender' => ['required', Rule::in(['L', 'P'])],
            'patient.birth_date' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'patient.phone' => ['required', 'regex:/^[0-9+\-\s]{8,20}$/'],
            'patient.nik' => [
                'nullable', 'digits:16',
                Rule::unique('patients', 'nik')->where('clinic_id', $this->clinicId())->ignore($this->editingId),
            ],
            'patient.address' => ['nullable', 'string', 'max:500'],
            'patient.occupation' => ['nullable', 'string', 'max:100'],
            'patient.emergency_contact' => ['nullable', 'string', 'max:150'],
            'patient.allergies' => ['required', 'string', 'max:255'],
        ], [
            'patient.nik.unique' => 'NIK sudah terdaftar. Cari pasien tersebut, jangan mendaftar ulang.',
            'patient.phone.regex' => 'Nomor telepon tidak valid.',
        ], [
            'patient.name' => 'nama', 'patient.birth_date' => 'tanggal lahir', 'patient.phone' => 'nomor telepon',
            'patient.nik' => 'NIK', 'patient.allergies' => 'alergi',
        ])['patient'];

        $data['phone'] = preg_replace('/[^0-9+]/', '', $data['phone']);
        foreach (['nik', 'address', 'occupation', 'emergency_contact'] as $f) {
            $data[$f] = filled($data[$f]) ? trim($data[$f]) : null;
        }

        if ($this->editingId) {
            $p = $this->query()->findOrFail($this->editingId);
            $p->fill($data);
            $changes = array_keys($p->getDirty());
            $p->save();
            AuditLog::record('patient.updated', $p, ['fields' => $changes]);
        } else {
            $p = DB::transaction(function () use ($data) {
                // Kunci baris klinik agar nomor RM tidak dobel saat dua admin mendaftar bersamaan.
                DB::table('clinics')->where('id', $this->clinicId())->lockForUpdate()->first();

                return Patient::create($data + [
                    'clinic_id' => $this->clinicId(),
                    'medical_record_number' => Patient::nextMedicalRecordNumber($this->clinicId()),
                ]);
            });
            AuditLog::record('patient.created', $p);
        }

        $this->showForm = false;
        $this->selected = $p->id;
        $this->toast($this->editingId ? 'Data pasien diperbarui.' : "Pasien {$p->rmCode()} terdaftar.");
        $this->editingId = null;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function toggleReveal(BranchContext $context): void
    {
        abort_unless($this->selected && $context->is(Role::Admin, Role::Owner, Role::Dokter), 403);
        $this->reveal = ! $this->reveal;

        if ($this->reveal) {
            AuditLog::record('patient.revealed', $this->query()->findOrFail($this->selected));
        }
    }

    private function query()
    {
        return Patient::query()->where('clinic_id', $this->clinicId());
    }

    public function render(BranchContext $context)
    {
        $patients = $this->query()
            ->search($this->search)
            ->with('latestVisit.branch')
            ->orderByDesc('updated_at')
            ->paginate(15);

        $detail = $this->selected ? $this->query()->find($this->selected) : null;
        $history = $detail
            ? Visit::query()
                ->where('patient_id', $detail->id)
                ->with(['branch', 'doctor', 'invoice.items', 'diagnoses.icd10', 'medicalRecord'])
                ->orderByDesc('visit_date')->orderByDesc('id')
                ->limit(20)
                ->get()
            : collect();

        if ($detail) {
            $detail->makeVisible(['phone', 'nik']);
        }

        return $this->page('pasien', 'livewire.modules.pasien', [
            'patients' => $patients,
            'detail' => $detail,
            'history' => $history,
            'canRegister' => $context->is(Role::Admin, Role::Owner),
        ]);
    }
}
