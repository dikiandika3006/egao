<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\PatientImportService;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * 03 Data pasien (FR-PAS-01 … 20): satu data pasien untuk semua cabang.
 */
class Pasien extends Component
{
    use ModulePage, WithFileUploads, WithPagination;

    public const MENU = 'pasien';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['open', 'close', 'closeForm', 'closeImport', 'toggleReveal', 'downloadImportReport'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Pasien yang sedang dibuka di panel detail. */
    #[Url(as: 'id', except: null)]
    public ?int $selected = null;

    /** Filter daftar: '' semua, 'rujukan' hanya pasien rujukan drg luar. */
    #[Url(as: 'filter', except: '')]
    public string $filter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $patient = [];

    /** Data sensitif yang sedang dibuka (FR-PAS-14/15), dicatat di jejak audit. */
    public bool $reveal = false;

    /** Impor pasien dari Excel Assist.id (khusus Owner). */
    public bool $showImport = false;

    public $importFile = null;

    /** Hasil impor terakhir: ['stats' => [...], 'reportPath' => '...']. */
    public array $importResult = [];

    public function mount(): void
    {
        if (request()->boolean('baru') && app(BranchContext::class)->is(Role::Admin, Role::Asisten, Role::Owner) && $this->canEditMenu()) {
            $this->create();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->filter = $this->filter === 'rujukan' ? 'rujukan' : '';
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

    /** Mendaftarkan & mengubah data pasien: Admin, Asisten, Owner yang punya izin Edit Data pasien. */
    private function authorizeWrite(): void
    {
        abort_unless(app(BranchContext::class)->is(Role::Admin, Role::Asisten, Role::Owner) && $this->canEditMenu(), 403);
    }

    /** Impor massal pasien hanya Owner. */
    private function authorizeImport(): void
    {
        abort_unless(app(BranchContext::class)->is(Role::Owner), 403);
    }

    public function openImport(): void
    {
        $this->authorizeImport();
        $this->resetValidation();
        $this->reset('importFile', 'importResult');
        $this->showImport = true;
    }

    public function closeImport(): void
    {
        $this->reset('importFile', 'importResult');
        $this->showImport = false;
    }

    public function importPatients(PatientImportService $service): void
    {
        $this->authorizeImport();
        $this->validate(
            ['importFile' => ['required', 'file', 'max:10240']],
            attributes: ['importFile' => 'file Excel']
        );

        // Ekstensi dicek manual: xlsx sering terbaca sebagai application/zip oleh aturan mimes.
        $ext = strtolower($this->importFile->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls'], true)) {
            $this->addError('importFile', 'File harus Excel (.xlsx atau .xls).');

            return;
        }

        // File unggahan bernama acak tanpa ekstensi; salin ke path berekstensi agar pembaca Excel mengenalinya.
        $local = tempnam(sys_get_temp_dir(), 'import-pasien').'.'.$ext;
        copy($this->importFile->getRealPath(), $local);

        try {
            $result = $service->import($local, $this->clinicId());
        } finally {
            @unlink($local);
        }

        if ($result['error']) {
            $this->addError('importFile', $result['error']);

            return;
        }

        $this->importResult = ['stats' => $result['stats'], 'reportPath' => $result['reportPath']];
        $this->importFile = null;
        $this->resetPage();
        $this->toast($result['stats']['baru'].' pasien diimpor.');
    }

    public function downloadImportReport()
    {
        $this->authorizeImport();
        $path = $this->importResult['reportPath'] ?? '';
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'laporan-impor-pasien.csv');
    }

    public function create(): void
    {
        $this->authorizeWrite();
        $this->resetValidation();
        $this->editingId = null;
        $this->patient = [
            'medical_record_number' => '', 'name' => '', 'gender' => 'P', 'birth_place' => '', 'birth_date' => '',
            'phone' => '', 'nik' => '', 'address' => '',
            'occupation' => '', 'emergency_contact' => '', 'allergies' => 'Tidak ada', 'referred_by' => '',
        ];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeWrite();
        $p = $this->query()->findOrFail($id);
        $this->resetValidation();
        $this->editingId = $p->id;
        $this->patient = [
            'medical_record_number' => (string) $p->medical_record_number, 'name' => $p->name, 'gender' => $p->gender,
            'birth_place' => (string) $p->birth_place, 'birth_date' => (string) $p->birth_date?->toDateString(),
            'phone' => (string) $p->phone, 'nik' => (string) $p->nik, 'address' => (string) $p->address,
            'occupation' => (string) $p->occupation, 'emergency_contact' => (string) $p->emergency_contact,
            'allergies' => (string) $p->allergies, 'referred_by' => (string) $p->referred_by,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizeWrite();
        $this->patient['medical_record_number'] = Patient::normalizeRm((string) ($this->patient['medical_record_number'] ?? ''));

        $data = $this->validate([
            'patient.medical_record_number' => [
                'nullable', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9\-\/.]*$/',
                Rule::unique('patients', 'medical_record_number')->where('clinic_id', $this->clinicId())->ignore($this->editingId),
            ],
            'patient.name' => ['required', 'string', 'max:120'],
            'patient.gender' => ['required', Rule::in(['L', 'P'])],
            'patient.birth_place' => ['nullable', 'string', 'max:100'],
            'patient.birth_date' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'patient.phone' => ['nullable', 'regex:/^[0-9+\-\s]{8,20}$/'],
            'patient.nik' => [
                'nullable', 'digits:16',
                Rule::unique('patients', 'nik')->where('clinic_id', $this->clinicId())->ignore($this->editingId),
            ],
            'patient.address' => ['nullable', 'string', 'max:500'],
            'patient.occupation' => ['nullable', 'string', 'max:100'],
            'patient.emergency_contact' => ['nullable', 'string', 'max:150'],
            'patient.allergies' => ['required', 'string', 'max:255'],
            'patient.referred_by' => ['nullable', 'string', 'max:255'],
        ], [
            'patient.nik.unique' => 'NIK sudah terdaftar. Cari pasien tersebut, jangan mendaftar ulang.',
            'patient.phone.regex' => 'Nomor telepon tidak valid.',
            'patient.medical_record_number.unique' => 'No. rekam medis sudah dipakai pasien lain.',
            'patient.medical_record_number.regex' => 'No. rekam medis hanya boleh huruf, angka, dan tanda "-".',
        ], [
            'patient.medical_record_number' => 'no. rekam medis', 'patient.birth_place' => 'tempat lahir', 'patient.name' => 'nama', 'patient.birth_date' => 'tanggal lahir', 'patient.phone' => 'nomor telepon',
            'patient.nik' => 'NIK', 'patient.allergies' => 'alergi', 'patient.referred_by' => 'perujuk',
        ])['patient'];

        $data['phone'] = filled($data['phone']) ? preg_replace('/[^0-9+]/', '', $data['phone']) : null;
        foreach (['nik', 'address', 'occupation', 'emergency_contact', 'referred_by', 'birth_place', 'birth_date'] as $f) {
            $data[$f] = filled($data[$f]) ? trim($data[$f]) : null;
        }
        $rm = $data['medical_record_number'] ?: null;
        unset($data['medical_record_number']);

        if ($this->editingId) {
            $p = $this->query()->findOrFail($this->editingId);
            $p->fill($data);
            if ($rm) {
                $p->medical_record_number = $rm; // kosong saat ubah data = nomor lama dipertahankan
            }
            $changes = array_keys($p->getDirty());
            $p->save();
            AuditLog::record('patient.updated', $p, ['fields' => $changes]);
        } else {
            $p = DB::transaction(function () use ($data, $rm) {
                // Kunci baris klinik agar nomor RM tidak dobel saat dua admin mendaftar bersamaan.
                DB::table('clinics')->where('id', $this->clinicId())->lockForUpdate()->first();

                return Patient::create($data + [
                    'clinic_id' => $this->clinicId(),
                    'medical_record_number' => $rm ?? Patient::nextMedicalRecordNumber($this->clinicId(), $data['name']),
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
            ->when($this->filter === 'rujukan', fn ($q) => $q->whereNotNull('referred_by'))
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
            'canRegister' => $context->is(Role::Admin, Role::Asisten, Role::Owner) && $this->canEditMenu(),
            'canImport' => $context->is(Role::Owner),
        ]);
    }
}
