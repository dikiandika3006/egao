<?php

namespace App\Livewire\MedicalRecords;

use App\Models\Icd10Code;
use App\Models\Item;
use App\Models\Procedure;
use App\Models\ToothConditionType;
use App\Models\ToothFinding;
use App\Models\Visit;
use App\Services\ExamService;
use App\Support\BranchContext;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 04 Rekam medis › Kunjungan: layar "Pemeriksaan [nama pasien]" (FR-RM-01 … 24).
 */
class Exam extends Component
{
    /** Hak akses: menu Rekam medis. Method di luar READ_ACTIONS butuh izin Edit (hook di AppServiceProvider). */
    public const MENU = 'rekam-medis';

    public const READ_ACTIONS = [
        'visit', 'conditions', 'procedureOptions', 'icdResults', 'icdLabels', 'favorites', 'history', 'previousTeeth', 'total',
        'drugOptions', 'drugTotal', 'standardPrice', 'priceOf', 'canEditPrice', 'canEditClinical', 'canEditBilling', 'isCorrection',
        'editable', 'selectTooth',
    ];

    public const UPPER_ADULT = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];

    public const LOWER_ADULT = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

    public const UPPER_CHILD = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];

    public const LOWER_CHILD = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];

    public const FOLLOW_UPS = [
        '' => 'Tanpa evaluasi',
        '1 week' => 'Evaluasi 1 minggu',
        '2 weeks' => 'Evaluasi 2 minggu',
        '1 month' => 'Evaluasi 1 bulan',
        '3 months' => 'Evaluasi 3 bulan',
        '6 months' => 'Evaluasi 6 bulan',
    ];

    #[Locked]
    public int $visitId;

    // Catatan kunjungan
    public string $complaint = '';

    public string $anamnesis = '';

    public string $bloodPressure = '';

    public string $currentMedication = '';

    public string $plan = '';

    public string $followUp = '';

    public bool $allergyConfirmed = false;

    /** @var list<array{tooth:int, condition_id:int, surfaces:list<string>, note:?string}> */
    public array $findings = [];

    /** @var list<array{code:string, primary:bool}> */
    public array $diagnoses = [];

    /** @var list<array{procedure_id:int, tooth:?string, quantity:int}> */
    public array $procedures = [];

    /** @var list<array{drug_name:string, dose:?string, quantity:?string}> */
    public array $prescriptions = [];

    // Odontogram
    public string $dentition = 'adult';

    public ?int $selectedTooth = null;

    /** @var list<string> */
    public array $selectedSurfaces = [];

    public ?int $selectedCondition = null;

    public string $findingNote = '';

    // Diagnosis
    public string $icdSearch = '';

    // Tambah tindakan
    public bool $addingProcedure = false;

    public ?int $newProcedureId = null;

    public int $newQuantity = 1;

    public string $newTooth = '';

    // Modal
    public bool $showHistory = false;

    public bool $showAllergyPrompt = false;

    public bool $showCorrection = false;

    public string $correctionReason = '';

    public function mount(Visit $visit, ExamService $exam): void
    {
        $this->visitId = $visit->id;
        $exam->start($visit);

        $visit->load(['medicalRecord', 'findings', 'diagnoses', 'prescriptions', 'invoice.items', 'patient']);
        $record = $visit->medicalRecord;

        $this->complaint = $record?->complaint ?? (string) $visit->complaint;
        $this->anamnesis = (string) $record?->anamnesis;
        $this->bloodPressure = (string) $record?->blood_pressure;
        $this->currentMedication = (string) $record?->current_medication;
        $this->plan = (string) $record?->plan;
        $this->followUp = (string) array_search($record?->follow_up_label, self::FOLLOW_UPS, true) ?: '';
        $this->allergyConfirmed = (bool) $record?->allergy_confirmed;

        $this->findings = $visit->findings->map(fn (ToothFinding $f) => [
            'tooth' => $f->tooth, 'condition_id' => $f->tooth_condition_type_id,
            'surfaces' => $f->surfaces ?? [], 'note' => $f->note,
        ])->all();

        $this->diagnoses = $visit->diagnoses->map(fn ($d) => ['code' => $d->icd10_code, 'primary' => $d->is_primary])->all();

        $this->procedures = $visit->invoice?->items->map(fn ($i) => [
            'procedure_id' => $i->procedure_id, 'tooth' => $i->tooth, 'quantity' => $i->quantity,
            // Harga dinaikkan dokter/Owner dipertahankan; selain itu null = ikut harga standar terbaru.
            'unit_price' => $i->isRaised() ? (int) $i->unit_price : null,
        ])->all() ?? [];

        $this->prescriptions = $visit->prescriptions->map->only(['item_id', 'drug_name', 'dose', 'quantity', 'item_quantity'])->all();

        $this->selectedCondition = $this->conditions->first()?->id;

        if (collect($this->findings)->contains(fn ($f) => $f['tooth'] > 50)) {
            $this->dentition = 'child';
        }
    }

    // ── Data turunan ────────────────────────────────────────────────

    #[Computed]
    public function visit(): Visit
    {
        return Visit::with(['patient', 'doctor', 'branch', 'medicalRecord.versions.user', 'invoice'])->findOrFail($this->visitId);
    }

    #[Computed]
    public function conditions(): Collection
    {
        return ToothConditionType::where('clinic_id', auth()->user()->clinic_id)->where('is_active', true)->orderBy('sort')->get()->keyBy('id');
    }

    #[Computed]
    public function procedureOptions(): Collection
    {
        $branchId = $this->visit->branch_id;

        return Procedure::where('clinic_id', auth()->user()->clinic_id)
            ->where('is_active', true)
            ->with(['prices' => fn ($q) => $q->where('branch_id', $branchId)])
            ->orderBy('name')
            ->get()
            ->filter(fn (Procedure $p) => $p->prices->isNotEmpty())
            ->keyBy('id');
    }

    #[Computed]
    public function icdResults(): Collection
    {
        if (mb_strlen(trim($this->icdSearch)) < 2) {
            return collect();
        }

        return Icd10Code::search($this->icdSearch)->limit(8)->get();
    }

    #[Computed]
    public function icdLabels(): Collection
    {
        $codes = collect($this->diagnoses)->pluck('code')->merge($this->favorites->pluck('code'));

        return Icd10Code::whereIn('code', $codes)->get()->keyBy('code');
    }

    #[Computed]
    public function favorites(): Collection
    {
        return $this->visit->doctor->favoriteDiagnoses()->orderBy('icd10_codes.code')->get();
    }

    /** Kunjungan lain pasien, lintas cabang (FR-RM-02, FR-RM-14, FR-PAS-17). */
    #[Computed]
    public function history(): Collection
    {
        return Visit::where('patient_id', $this->visit->patient_id)
            ->whereKeyNot($this->visitId)
            ->whereHas('medicalRecord')
            ->with(['branch', 'doctor', 'medicalRecord', 'diagnoses.icd10', 'findings.condition'])
            ->orderByDesc('visit_date')
            ->orderByDesc('id')
            ->get();
    }

    /** Kondisi terakhir tiap gigi dari kunjungan sebelumnya (FR-RM-08). */
    #[Computed]
    public function previousTeeth(): Collection
    {
        return ToothFinding::where('patient_id', $this->visit->patient_id)
            ->where('visit_id', '!=', $this->visitId)
            ->with(['condition', 'visit.branch'])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('tooth');
    }

    #[Computed]
    public function total(): int
    {
        $procedures = (int) collect($this->procedures)->sum(fn ($p) => $this->priceOf($p) * max(1, (int) $p['quantity']));

        return $procedures + $this->drugTotal;
    }

    /** Obat dari stok cabang kunjungan yang bisa diresepkan & ditagihkan. */
    #[Computed]
    public function drugOptions(): Collection
    {
        return Item::where('clinic_id', auth()->user()->clinic_id)
            ->where('category', 'obat')->where('is_active', true)->whereNotNull('sell_price')
            ->with(['stocks' => fn ($q) => $q->where('branch_id', $this->visit->branch_id)])
            ->orderBy('name')->get()->keyBy('id');
    }

    #[Computed]
    public function drugTotal(): int
    {
        return (int) collect($this->prescriptions)->sum(function ($p) {
            $item = filled($p['item_id'] ?? null) ? $this->drugOptions->get((int) $p['item_id']) : null;

            return $item ? $item->sell_price * max(1, (int) ($p['item_quantity'] ?? 1)) : 0;
        });
    }

    /** Harga standar tindakan di cabang kunjungan. */
    public function standardPrice(?int $procedureId): int
    {
        return (int) ($this->procedureOptions->get($procedureId)?->prices->first()?->price ?? 0);
    }

    /** Harga yang dipakai: harga dinaikkan (bila ada) atau harga standar. */
    public function priceOf(array $p): int
    {
        $standard = $this->standardPrice($p['procedure_id'] ?? null);

        return filled($p['unit_price'] ?? null) ? max($standard, (int) $p['unit_price']) : $standard;
    }

    /** Hanya Dokter & Owner yang boleh menaikkan harga tindakan (≥ harga standar). */
    #[Computed]
    public function canEditPrice(): bool
    {
        return $this->canEditBilling && app(ExamService::class)->canRaisePrice();
    }

    /** Ubah harga tindakan: kosong/sama dengan standar = harga standar; di bawah standar ditolak. */
    public function updatedProcedures($value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);
        if ($field !== 'unit_price' || ! isset($this->procedures[$index])) {
            return;
        }

        if (! $this->canEditPrice) {
            $this->procedures[$index]['unit_price'] = null;
            abort(403, 'Hanya Dokter dan Owner yang boleh mengubah harga tindakan.');
        }

        $price = (int) preg_replace('/\D/', '', (string) $value);
        $standard = $this->standardPrice($this->procedures[$index]['procedure_id']);
        $this->resetErrorBag("procedures.{$index}.unit_price");

        if ($value === null || $value === '' || $price === $standard) {
            $this->procedures[$index]['unit_price'] = null;
        } elseif ($price < $standard) {
            $this->procedures[$index]['unit_price'] = null;
            $this->addError("procedures.{$index}.unit_price", 'Tidak boleh di bawah harga standar '.Format::rupiah($standard).'.');
        } else {
            $this->procedures[$index]['unit_price'] = $price;
        }
    }

    /** Memilih obat dari stok mengisi nama obat otomatis. */
    public function updatedPrescriptions($value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);

        if ($field === 'item_id' && isset($this->prescriptions[$index])) {
            $item = filled($value) ? $this->drugOptions->get((int) $value) : null;
            if ($item) {
                $this->prescriptions[$index]['drug_name'] = $item->name;
                $this->prescriptions[$index]['item_quantity'] = $this->prescriptions[$index]['item_quantity'] ?: 1;
            }
        }
    }

    #[Computed]
    public function canEditClinical(): bool
    {
        return app(ExamService::class)->canEditClinical($this->visit);
    }

    #[Computed]
    public function canEditBilling(): bool
    {
        return app(ExamService::class)->canEditBilling($this->visit);
    }

    /** Mode koreksi: rekam medis final dan dokter pemilik → form klinis terbuka, tagihan terkunci. */
    #[Computed]
    public function isCorrection(): bool
    {
        return ! $this->canEditBilling && $this->canEditClinical && (bool) $this->visit->medicalRecord?->isFinal();
    }

    #[Computed]
    public function editable(): bool
    {
        return $this->canEditBilling || $this->isCorrection;
    }

    // ── Odontogram ──────────────────────────────────────────────────

    public function selectTooth(int $tooth): void
    {
        $this->selectedTooth = $this->selectedTooth === $tooth ? null : $tooth;
        $this->selectedSurfaces = [];
        $this->findingNote = '';
    }

    public function toggleSurface(string $surface): void
    {
        abort_unless(array_key_exists($surface, ToothFinding::SURFACES), 422);

        $this->selectedSurfaces = in_array($surface, $this->selectedSurfaces, true)
            ? array_values(array_diff($this->selectedSurfaces, [$surface]))
            : [...$this->selectedSurfaces, $surface];
    }

    public function addFinding(): void
    {
        $this->authorizeEdit();

        if (! $this->selectedTooth || ! $this->conditions->has($this->selectedCondition)) {
            return;
        }

        $this->findings[] = [
            'tooth' => $this->selectedTooth,
            'condition_id' => $this->selectedCondition,
            'surfaces' => array_values(array_intersect(array_keys(ToothFinding::SURFACES), $this->selectedSurfaces)),
            'note' => trim($this->findingNote) ?: null,
        ];

        $this->selectedSurfaces = [];
        $this->findingNote = '';
    }

    public function removeFinding(int $index): void
    {
        $this->authorizeEdit();
        unset($this->findings[$index]);
        $this->findings = array_values($this->findings);
    }

    // ── Diagnosis ───────────────────────────────────────────────────

    public function addDiagnosis(string $code): void
    {
        $this->authorizeEdit();

        if (! Icd10Code::whereKey($code)->exists() || collect($this->diagnoses)->contains('code', $code)) {
            return;
        }

        $this->diagnoses[] = ['code' => $code, 'primary' => $this->diagnoses === []];
        $this->icdSearch = '';
        unset($this->icdLabels);
    }

    public function removeDiagnosis(int $index): void
    {
        $this->authorizeEdit();
        $wasPrimary = $this->diagnoses[$index]['primary'] ?? false;
        unset($this->diagnoses[$index]);
        $this->diagnoses = array_values($this->diagnoses);

        if ($wasPrimary && $this->diagnoses) {
            $this->diagnoses[0]['primary'] = true;
        }
    }

    public function makePrimary(int $index): void
    {
        $this->authorizeEdit();

        foreach ($this->diagnoses as $i => $d) {
            $this->diagnoses[$i]['primary'] = $i === $index;
        }
    }

    public function toggleFavorite(string $code): void
    {
        abort_unless($this->canEditClinical, 403);
        $this->visit->doctor->favoriteDiagnoses()->toggle([$code]);
        unset($this->favorites, $this->icdLabels);
    }

    // ── Tindakan & resep ────────────────────────────────────────────

    public function addProcedure(): void
    {
        $this->authorizeBilling();

        $this->validate([
            'newProcedureId' => 'required|integer',
            'newQuantity' => 'required|integer|min:1|max:99',
            'newTooth' => 'nullable|string|max:10',
        ], attributes: ['newProcedureId' => 'tindakan', 'newQuantity' => 'jumlah', 'newTooth' => 'gigi']);

        if (! $this->procedureOptions->has($this->newProcedureId)) {
            $this->addError('newProcedureId', 'Tindakan tidak tersedia di cabang ini.');

            return;
        }

        $this->procedures[] = ['procedure_id' => $this->newProcedureId, 'tooth' => trim($this->newTooth) ?: null, 'quantity' => $this->newQuantity, 'unit_price' => null];
        $this->reset('newProcedureId', 'newQuantity', 'newTooth', 'addingProcedure');
        $this->resetErrorBag('procedures');
    }

    public function removeProcedure(int $index): void
    {
        $this->authorizeBilling();
        unset($this->procedures[$index]);
        $this->procedures = array_values($this->procedures);
    }

    public function addPrescription(): void
    {
        $this->authorizeEdit();
        $this->prescriptions[] = ['item_id' => '', 'drug_name' => '', 'dose' => '', 'quantity' => '', 'item_quantity' => 1];
    }

    public function removePrescription(int $index): void
    {
        $this->authorizeEdit();
        unset($this->prescriptions[$index]);
        $this->prescriptions = array_values($this->prescriptions);
    }

    // ── Simpan ──────────────────────────────────────────────────────

    public function saveDraft(ExamService $exam): void
    {
        $this->validateFields();
        $exam->saveDraft($this->visit, $this->payload());
        unset($this->visit);

        $this->dispatch('toast', message: 'Draf tersimpan. Anda dapat melanjutkan nanti.');
    }

    /** Langkah 1: pengingat periksa catatan & konfirmasi alergi (FR-RM-21). */
    public function confirmSend(): void
    {
        $this->authorizeBilling();
        $this->validateFields();

        if (! $this->allergyConfirmed) {
            $this->showAllergyPrompt = true;

            return;
        }

        $this->send(app(ExamService::class));
    }

    public function confirmAllergyAndSend(ExamService $exam)
    {
        $this->allergyConfirmed = true;
        $this->showAllergyPrompt = false;

        return $this->send($exam);
    }

    public function send(ExamService $exam)
    {
        try {
            $exam->sendToCashier($this->visit, $this->payload());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return null;
        }

        session()->flash('status', 'Rekam medis tersimpan dan tagihan terkirim ke kasir '.$this->visit->branch->name.'.');

        return $this->redirectRoute('rekam-medis', navigate: true);
    }

    public function saveCorrection(ExamService $exam): void
    {
        $this->validateFields();
        $exam->correct($this->visit, $this->payload(), $this->correctionReason);

        $this->reset('showCorrection', 'correctionReason');
        unset($this->visit);
        $this->dispatch('toast', message: 'Koreksi tersimpan sebagai versi baru.');
    }

    private function validateFields(): void
    {
        $this->validate([
            'complaint' => 'nullable|string|max:500',
            'plan' => 'nullable|string|max:2000',
            'anamnesis' => 'nullable|string|max:2000',
            'bloodPressure' => 'nullable|string|max:20',
            'currentMedication' => 'nullable|string|max:255',
            'followUp' => 'in:'.implode(',', array_keys(self::FOLLOW_UPS)),
            'prescriptions.*.drug_name' => 'nullable|string|max:255',
            'prescriptions.*.dose' => 'nullable|string|max:255',
            'prescriptions.*.quantity' => 'nullable|string|max:30',
            'prescriptions.*.item_quantity' => 'nullable|integer|min:1|max:999',
        ], attributes: ['complaint' => 'keluhan utama', 'plan' => 'rencana perawatan', 'bloodPressure' => 'tekanan darah']);
    }

    private function payload(): array
    {
        return [
            'complaint' => $this->complaint,
            'anamnesis' => $this->anamnesis,
            'blood_pressure' => $this->bloodPressure,
            'current_medication' => $this->currentMedication,
            'plan' => $this->plan,
            'follow_up_label' => self::FOLLOW_UPS[$this->followUp] ?? null,
            'follow_up_date' => $this->followUp ? $this->visit->visit_date->copy()->add($this->followUp)->toDateString() : null,
            'allergy_confirmed' => $this->allergyConfirmed,
            'findings' => $this->findings,
            'diagnoses' => $this->diagnoses,
            'procedures' => $this->procedures,
            'prescriptions' => $this->prescriptions,
        ];
    }

    private function authorizeEdit(): void
    {
        abort_unless($this->editable, 403, 'Rekam medis ini hanya dapat dibaca.');
    }

    private function authorizeBilling(): void
    {
        abort_unless($this->canEditBilling, 403, 'Tindakan tidak dapat diubah setelah dikirim ke kasir.');
    }

    public function render(BranchContext $context)
    {
        Format::mask(! \App\Support\Access::can('rekam-medis', 'keuangan'));
        $visit = $this->visit;

        return view('livewire.medical-records.exam', [
            'visit' => $visit,
            'patient' => $visit->patient,
            'record' => $visit->medicalRecord,
            'upper' => $this->dentition === 'adult' ? self::UPPER_ADULT : self::UPPER_CHILD,
            'lower' => $this->dentition === 'adult' ? self::LOWER_ADULT : self::LOWER_CHILD,
            'findingsByTooth' => collect($this->findings)->groupBy('tooth'),
            'surfaces' => ToothFinding::SURFACES,
            'lastVisit' => $this->history->first(),
        ])->title('Pemeriksaan '.$visit->patient->name)
            ->layoutData(['breadcrumb' => ['Operasional', 'Rekam medis', 'Kunjungan'], 'active' => 'rekam-medis']);
    }
}
