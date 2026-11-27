<?php

namespace App\Livewire\MedicalRecords;

use App\Models\Icd10Code;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\Procedure;
use App\Models\ToothConditionType;
use App\Models\ToothFinding;
use App\Models\Visit;
use App\Services\ExamService;
use App\Support\BranchContext;
use App\Support\Format;
use App\Support\TreatmentSuggestions;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
        'drugOptions', 'drugTotal', 'goodsOptions', 'goodsTotal','standardPrice', 'maxPrice', 'defaultPrice', 'needsPrice', 'priceOf', 'canEditPrice', 'canEditClinical', 'canEditBilling', 'canEditSentBilling', 'billingEditable', 'isCorrection',
        'editable', 'assistantOptions', 'missingAssistant', 'selectTooth', 'suggestions', 'zeroPriceProcedures', 'checklist', 'diagnosisSuggestions',
    ];

    public const UPPER_ADULT = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];

    public const LOWER_ADULT = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

    public const UPPER_CHILD = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];

    public const LOWER_CHILD = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];

    /** Label singkat tombol evaluasi ulang. */
    public const FOLLOW_UP_CHIPS = [
        '' => 'Tidak', '1 week' => '1 mgg', '2 weeks' => '2 mgg', '1 month' => '1 bln', '3 months' => '3 bln', '6 months' => '6 bln',
    ];

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

    /** Diskon tagihan dari dokter (Rp) + alasan; hanya dokter penanggung jawab / Owner yang bisa mengubah. */
    public ?int $discount = 0;

    public string $discountReason = '';

    /** Asisten pendamping kunjungan (visits.assistant_id); dipilih saat tindakan, dasar fee asisten di Gaji. */
    public ?int $assistantId = null;

    /** Asisten pendamping kedua (opsional, visits.assistant2_id); fee asisten penuh juga. */
    public ?int $assistant2Id = null;

    /** @var list<array{tooth:int, condition_id:int, surfaces:list<string>, note:?string}> */
    public array $findings = [];

    /** @var list<array{code:string, primary:bool}> */
    public array $diagnoses = [];

    /** @var list<array{procedure_id:int, tooth:?string, quantity:int}> */
    public array $procedures = [];

    /** @var list<array{drug_name:string, dose:?string, quantity:?string}> */
    public array $prescriptions = [];

    /**
     * Barang jual selain obat (odol, sikat gigi, bracket, dll.): hanya ditagihkan, tidak masuk rekam medis.
     *
     * @var list<array{item_id:int|string, quantity:int|string}>
     */
    public array $goods = [];

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

    /** Satu modal "Periksa sebelum kirim": alergi belum dikonfirmasi dan/atau tindakan bertarif Rp0. */
    public bool $showSendReview = false;

    public bool $zeroPriceConfirmed = false;

    /** Dokter sudah menyetujui kirim tanpa asisten pendamping (konfirmasi saja, tidak wajib). */
    public bool $noAssistantConfirmed = false;

    /** Sidik isian terakhir yang tersimpan; dipakai untuk peringatan "belum disimpan". */
    #[Locked]
    public string $savedHash = '';

    /** Ada isian yang belum disimpan (dihitung ulang tiap respons; dibaca Alpine lewat $wire.unsaved). */
    public bool $unsaved = false;

    public bool $showCorrection = false;

    /** Mode edit tagihan setelah dikirim ke kasir (diverifikasi ulang di server lewat canEditSentBilling). */
    public bool $editingBilling = false;

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
        $this->discount = (int) $visit->invoice?->discount;
        $this->discountReason = (string) $visit->invoice?->discount_reason;
        $this->assistantId = $visit->assistant_id;
        $this->assistant2Id = $visit->assistant2_id;

        $this->findings = $visit->findings->map(fn (ToothFinding $f) => [
            'tooth' => $f->tooth, 'condition_id' => $f->tooth_condition_type_id,
            'surfaces' => $f->surfaces ?? [], 'note' => $f->note,
        ])->all();

        $this->diagnoses = $visit->diagnoses->map(fn ($d) => ['code' => $d->icd10_code, 'primary' => $d->is_primary])->all();

        // Hanya baris tindakan; item obat/barang (procedure_id null) dimuat lewat resep, bukan sebagai tindakan.
        $this->procedures = $visit->invoice?->items->whereNotNull('procedure_id')->values()->map(fn ($i) => [
            'procedure_id' => $i->procedure_id, 'tooth' => $i->tooth, 'quantity' => $i->quantity, 'name' => $i->name,
            // Tarif tunggal: harga yang diubah dokter/Owner dipertahankan, selain itu null = ikut tarif terbaru.
            // Tarif range: harga yang diisi manual dimuat apa adanya; Rp0 tanpa "tidak selesai" = draf yang belum diisi harganya.
            'unit_price' => $this->loadedPrice($i),
            'price_reason' => $i->price_reason,
            'is_incomplete' => (bool) $i->is_incomplete,
        ])->all() ?? [];

        $this->prescriptions = $visit->prescriptions->map->only(['item_id', 'drug_name', 'dose', 'quantity', 'item_quantity'])->all();

        // Barang lain-lain = baris tagihan dari stok yang bukan obat (obat dimuat lewat resep).
        $this->goods = $visit->invoice?->items->whereNotNull('item_id')->whereNull('procedure_id')
            ->filter(fn ($i) => $this->goodsOptions->has($i->item_id))->values()
            ->map(fn ($i) => ['item_id' => $i->item_id, 'quantity' => $i->quantity])->all() ?? [];

        $this->selectedCondition = $this->conditions->first()?->id;

        if (collect($this->findings)->contains(fn ($f) => $f['tooth'] > 50)) {
            $this->dentition = 'child';
        }

        $this->savedHash = $this->formHash();
    }

    public function dehydrate(): void
    {
        $this->unsaved = $this->editable && $this->formHash() !== $this->savedHash;
    }

    /** Sidik isian yang disimpan ke rekam medis/tagihan (bukan state tampilan). */
    private function formHash(): string
    {
        return md5(json_encode([
            $this->complaint, $this->anamnesis, $this->bloodPressure, $this->currentMedication, $this->plan,
            $this->followUp, $this->allergyConfirmed, $this->findings, $this->diagnoses, $this->procedures, $this->prescriptions, $this->goods,
            $this->discount, $this->discountReason, $this->assistantId, $this->assistant2Id,
        ]));
    }

    /** Status kelengkapan untuk bar aksi: [label, terisi?, wajib?]. */
    #[Computed]
    public function checklist(): array
    {
        return [
            ['Keluhan', filled(trim($this->complaint)), true],
            ['Diagnosis', $this->diagnoses !== [], true],
            ['Tindakan', $this->procedures !== [], true],
            ['Alergi dicek', $this->allergyConfirmed, true],
            ['Resep', $this->prescriptions !== [], false],
        ];
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

        return $procedures + $this->drugTotal + $this->goodsTotal;
    }

    /** Dokter penanggung jawab / Owner boleh memberi diskon sebelum dikirim ke kasir. */
    #[Computed]
    public function canGiveDiscount(): bool
    {
        return app(ExamService::class)->canGiveDiscount($this->visit);
    }

    /** Batas diskon untuk subtotal di layar (tanpa biaya admin yang ditambahkan saat simpan). */
    #[Computed]
    public function maxDiscount(): int
    {
        return app(ExamService::class)->maxDiscount($this->total);
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

    /** Karyawan berjabatan "Asisten dokter" (aktif, atau asisten yang sudah tersimpan di kunjungan ini). */
    #[Computed]
    public function assistantOptions(): Collection
    {
        return app(ExamService::class)->assistantOptions($this->visit)->keyBy('id');
    }

    /** Barang jual bukan obat (kategori Barang jual, alat, dll.) yang punya harga jual, beserta stok cabang kunjungan. */
    #[Computed]
    public function goodsOptions(): Collection
    {
        return Item::where('clinic_id', auth()->user()->clinic_id)
            ->where('category', '!=', 'obat')->where('is_active', true)->whereNotNull('sell_price')
            ->with(['stocks' => fn ($q) => $q->where('branch_id', $this->visit->branch_id)])
            ->orderByRaw("category = 'barang' desc")->orderBy('name')->get()->keyBy('id');
    }

    #[Computed]
    public function goodsTotal(): int
    {
        return (int) collect($this->goods)->sum(function ($g) {
            $item = filled($g['item_id'] ?? null) ? $this->goodsOptions->get((int) $g['item_id']) : null;

            return $item ? $item->sell_price * max(1, (int) ($g['quantity'] ?? 1)) : 0;
        });
    }

    /** Harga standar tindakan di cabang kunjungan. */
    public function standardPrice(?int $procedureId): int
    {
        return (int) ($this->procedureOptions->get($procedureId)?->prices->first()?->price ?? 0);
    }

    /** Batas atas tarif range di cabang kunjungan (null = satu harga). */
    public function maxPrice(?int $procedureId): ?int
    {
        $max = $this->procedureOptions->get($procedureId)?->prices->first()?->max_price;

        return $max !== null && (int) $max > $this->standardPrice($procedureId) ? (int) $max : null;
    }

    /** Harga otomatis tindakan: tarif tunggal (atau minimal bila terkunci). Tarif range = null, wajib diisi manual. */
    public function defaultPrice(?int $procedureId): ?int
    {
        if ($this->procedureOptions->get($procedureId)?->price_locked || $this->maxPrice($procedureId) === null) {
            return $this->standardPrice($procedureId);
        }

        return null;
    }

    /** Tindakan bertarif range yang harganya belum diisi manual. */
    public function needsPrice(array $p): bool
    {
        return ! filled($p['unit_price'] ?? null) && $this->defaultPrice($p['procedure_id'] ?? null) === null;
    }

    /** Harga baris tagihan tersimpan yang dimuat ke kolom harga (null = tarif terkunci / tarif range belum diisi). */
    private function loadedPrice(InvoiceItem $i): ?int
    {
        $price = (int) $i->unit_price;
        $procedure = $this->procedureOptions->get($i->procedure_id);

        $default = $this->defaultPrice($i->procedure_id);

        if ($procedure?->price_locked || ($default === null && $price === 0 && ! $i->is_incomplete)) {
            return null;
        }

        // Tarif tunggal yang tidak diubah dokter ikut tarif terbaru; harga yang diubah dipertahankan.
        return $default !== null && ($i->standard_price === null || $price === (int) $i->standard_price) ? $default : $price;
    }

    /** Harga boleh di bawah tarif minimal: dicentang "tidak selesai" atau tindakan berizin. */
    private function mayLower(array $p): bool
    {
        return ! empty($p['is_incomplete']) || (bool) $this->procedureOptions->get($p['procedure_id'] ?? null)?->allow_lower_price;
    }

    /** Harga yang dipakai: harga yang diisi (bila ada) atau tarif tunggal. Tarif range belum diisi = 0 (tidak ikut total). */
    public function priceOf(array $p): int
    {
        $standard = $this->standardPrice($p['procedure_id'] ?? null);
        $procedure = $this->procedureOptions->get($p['procedure_id'] ?? null);

        if ($procedure?->price_locked) {
            return $standard;
        }
        if (! filled($p['unit_price'] ?? null)) {
            return $this->defaultPrice($p['procedure_id'] ?? null) ?? 0;
        }

        return $this->mayLower($p) ? max(0, (int) $p['unit_price']) : max($standard, (int) $p['unit_price']);
    }

    /** Hanya Dokter & Owner yang boleh mengubah harga tindakan (≥ tarif minimal, kecuali tidak selesai). */
    #[Computed]
    public function canEditPrice(): bool
    {
        return $this->billingEditable && app(ExamService::class)->canRaisePrice();
    }

    /** Ubah baris tindakan (gigi, jumlah, harga). Harga kosong = tarif tunggal terisi lagi / tarif range belum diisi; di bawah standar hanya untuk tindakan berizin (wajib alasan). */
    public function updatedProcedures($value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);
        if (! isset($this->procedures[$index]) || ! in_array($field, ['unit_price', 'price_reason', 'is_incomplete', 'quantity', 'tooth'], true)) {
            return;
        }

        // Gigi & jumlah diketik langsung di baris (seperti nota).
        if ($field === 'quantity' || $field === 'tooth') {
            $this->authorizeBilling();
            $this->procedures[$index][$field] = $field === 'quantity'
                ? max(1, min(99, (int) preg_replace('/\D/', '', (string) $value) ?: 1))
                : (mb_substr(trim((string) $value), 0, 10) ?: null);

            return;
        }

        if ($field === 'is_incomplete') {
            if (! $this->canEditPrice) {
                $this->procedures[$index]['is_incomplete'] = false;
                abort(403, 'Hanya Dokter dan Owner yang boleh menandai tindakan tidak selesai.');
            }
            $this->procedures[$index]['is_incomplete'] = (bool) $value;
            $standard = $this->standardPrice($this->procedures[$index]['procedure_id']);
            // Centang dilepas: harga di bawah minimal tidak berlaku lagi.
            if (! $this->mayLower($this->procedures[$index]) && filled($this->procedures[$index]['unit_price'] ?? null)
                && (int) $this->procedures[$index]['unit_price'] < $standard) {
                $this->procedures[$index]['unit_price'] = $this->defaultPrice($this->procedures[$index]['procedure_id']);
                $this->procedures[$index]['price_reason'] = null;
            }

            return;
        }

        if ($field === 'price_reason') {
            $this->procedures[$index]['price_reason'] = mb_substr(trim((string) $value), 0, 255) ?: null;
            $this->resetErrorBag("procedures.{$index}.price_reason");

            return;
        }

        if (! $this->canEditPrice) {
            $this->procedures[$index]['unit_price'] = $this->defaultPrice($this->procedures[$index]['procedure_id']);
            abort(403, 'Hanya Dokter dan Owner yang boleh mengubah harga tindakan.');
        }

        $price = (int) preg_replace('/\D/', '', (string) $value);
        $standard = $this->standardPrice($this->procedures[$index]['procedure_id']);
        $procedure = $this->procedureOptions->get($this->procedures[$index]['procedure_id']);
        $this->resetErrorBag("procedures.{$index}.unit_price");

        if ($procedure?->price_locked) {
            $this->procedures[$index]['unit_price'] = null;
            $this->addError("procedures.{$index}.unit_price", 'Tarif tindakan ini terkunci, tidak bisa diubah.');
        } elseif ($value === null || $value === '') {
            // Dikosongkan: tarif tunggal terisi lagi; tarif range kembali "belum diisi".
            $this->procedures[$index]['unit_price'] = $this->defaultPrice($this->procedures[$index]['procedure_id']);
        } elseif ($price < $standard && $this->mayLower($this->procedures[$index])) {
            $this->procedures[$index]['unit_price'] = $price;
        } elseif ($price < $standard) {
            $this->procedures[$index]['unit_price'] = $this->defaultPrice($this->procedures[$index]['procedure_id']);
            $this->addError("procedures.{$index}.unit_price", 'Di bawah tarif minimal '.Format::rupiah($standard).'. Centang "Tidak selesai" bila tindakannya tidak selesai.');
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

    /** Tagihan sudah dikirim ke kasir tetapi belum dibayar: Dokter pemeriksa/Owner boleh masuk Mode edit. */
    #[Computed]
    public function canEditSentBilling(): bool
    {
        return app(ExamService::class)->canEditSentBilling($this->visit);
    }

    /** Kartu tindakan & tagihan bisa diubah: pemeriksaan masih terbuka, atau Mode edit setelah kirim. */
    #[Computed]
    public function billingEditable(): bool
    {
        return $this->canEditBilling || ($this->editingBilling && $this->canEditSentBilling);
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

    /** Sekali klik chip kondisi = temuan tercatat (permukaan & catatan yang sudah diisi ikut). */
    public function recordFinding(int $conditionId): void
    {
        $this->selectedCondition = $conditionId;
        $this->addFinding();
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

    /** Saran diagnosis dari temuan peta gigi (mis. Karies → K02.9), yang belum dipilih. */
    #[Computed]
    public function diagnosisSuggestions(): Collection
    {
        $codes = TreatmentSuggestions::diagnosesFor(
            collect($this->findings)->map(fn ($f) => $this->conditions->get($f['condition_id'])?->code)->filter()->values()->all(),
            collect($this->diagnoses)->pluck('code')->all(),
        );

        return $codes ? Icd10Code::whereIn('code', $codes)->get()->sortBy(fn ($c) => array_search($c->code, $codes))->values() : collect();
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

        $this->procedures[] = $this->newRow($this->newProcedureId, trim($this->newTooth) ?: null, $this->newQuantity);
        $this->reset('newProcedureId', 'newQuantity', 'newTooth', 'addingProcedure', 'zeroPriceConfirmed');
        $this->resetErrorBag('procedures');
    }

    /** Baris tindakan baru seperti nota: tarif tunggal langsung terisi di kolom harga; tarif range kosong (wajib diisi); terkunci tidak diisi. */
    private function newRow(int $procedureId, ?string $tooth, int $quantity): array
    {
        $locked = (bool) $this->procedureOptions->get($procedureId)?->price_locked;

        return [
            'procedure_id' => $procedureId, 'tooth' => $tooth, 'quantity' => $quantity,
            'unit_price' => $locked ? null : $this->defaultPrice($procedureId),
        ];
    }

    /** Membuka form tambah tindakan: kolom gigi terisi gigi yang sedang dipilih di peta gigi. */
    public function updatedAddingProcedure(bool $value): void
    {
        if ($value && $this->selectedTooth && trim($this->newTooth) === '') {
            $this->newTooth = (string) $this->selectedTooth;
        }
    }

    /** Saran tindakan dari diagnosis (peta ICD + riwayat klinik), hanya yang bertarif di cabang & belum ada. */
    #[Computed]
    public function suggestions(): Collection
    {
        return TreatmentSuggestions::for(
            collect($this->diagnoses)->pluck('code')->filter()->values()->all(),
            $this->procedureOptions,
            collect($this->procedures)->pluck('procedure_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    /**
     * Tambah tindakan dari chip saran: jumlah 1, gigi = gigi yang sedang dipilih di peta gigi; bila tidak ada,
     * gigi temuan yang relevan (hanya bila tepat satu gigi).
     */
    public function addSuggestedProcedure(int $procedureId): void
    {
        $this->authorizeBilling();
        abort_unless($this->procedureOptions->has($procedureId), 422);

        $tooth = $this->selectedTooth ? (string) $this->selectedTooth : TreatmentSuggestions::toothFor(
            $procedureId,
            collect($this->findings)->map(fn ($f) => ['tooth' => $f['tooth'], 'code' => $this->conditions->get($f['condition_id'])?->code])->all(),
            collect($this->diagnoses)->pluck('code')->all(),
            $this->procedureOptions,
        );

        $this->procedures[] = $this->newRow($procedureId, $tooth, 1);
        $this->reset('zeroPriceConfirmed');
        $this->resetErrorBag('procedures');
        unset($this->suggestions);
    }

    /** Tindakan yang harganya Rp0 (tarif cabang belum diisi): perlu dikonfirmasi sebelum kirim ke kasir. */
    #[Computed]
    public function zeroPriceProcedures(): Collection
    {
        return collect($this->procedures)->filter(fn ($p) => ! $this->needsPrice($p) && $this->priceOf($p) <= 0)->values();
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

    public function addGoods(): void
    {
        $this->authorizeBilling();
        $this->goods[] = ['item_id' => '', 'quantity' => 1];
    }

    public function removeGoods(int $index): void
    {
        $this->authorizeBilling();
        unset($this->goods[$index]);
        $this->goods = array_values($this->goods);
    }

    public function saveDraft(ExamService $exam): void
    {
        $this->validateFields();
        $exam->saveDraft($this->visit, $this->payload());
        $this->savedHash = $this->formHash();
        unset($this->visit);

        $this->dispatch('toast', message: 'Draf tersimpan. Anda dapat melanjutkan nanti.');
    }

    /**
     * Langkah 1 kirim ke kasir. Isian wajib (keluhan, diagnosis, tindakan) yang kosong langsung tampil sebagai error.
     * Bila lengkap tapi alergi belum dikonfirmasi (FR-RM-21) atau ada tindakan Rp0 → satu modal "Periksa sebelum kirim".
     */
    public function confirmSend(): void
    {
        $this->authorizeBilling();
        $this->validateFields();

        if ($this->flagMissingPrices()) {
            return;
        }

        $incomplete = blank(trim($this->complaint)) || $this->diagnoses === [] || $this->procedures === [];
        $needsReview = ! $this->allergyConfirmed || ($this->zeroPriceProcedures->isNotEmpty() && ! $this->zeroPriceConfirmed)
            || ($this->missingAssistant() && ! $this->noAssistantConfirmed);

        if (! $incomplete && $needsReview) {
            $this->showSendReview = true;

            return;
        }

        $this->send(app(ExamService::class));
    }

    /** Langkah 2 dari modal: alergi dikonfirmasi & tindakan Rp0 disetujui, lalu kirim. */
    public function confirmChecksAndSend(ExamService $exam)
    {
        $this->authorizeBilling();
        $this->allergyConfirmed = true;
        $this->zeroPriceConfirmed = true;
        $this->noAssistantConfirmed = true;
        $this->showSendReview = false;

        return $this->send($exam);
    }

    public function send(ExamService $exam)
    {
        $this->authorizeBilling();

        try {
            $exam->sendToCashier($this->visit, $this->payload());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return null;
        }

        $this->savedHash = $this->formHash();

        session()->flash('status', 'Rekam medis tersimpan dan tagihan terkirim ke kasir '.$this->visit->branch->name.'.');

        return $this->redirectRoute('rekam-medis', navigate: true);
    }

    /** Asisten belum dipilih padahal ada asisten di cabang ini → minta konfirmasi (boleh tetap kirim). */
    public function missingAssistant(): bool
    {
        return $this->assistantId === null && $this->assistant2Id === null && $this->assistantOptions->isNotEmpty();
    }

    /** Combobox multi-pilih asisten: centang/lepas satu asisten, maksimal 2. Disimpan saat simpan draf/kirim. */
    public function toggleAssistant(int $employeeId): void
    {
        abort_unless($this->billingEditable, 403, 'Asisten tidak dapat diubah setelah tagihan dibayar.');
        abort_unless($this->assistantOptions->has($employeeId), 404);

        $chosen = collect([$this->assistantId, $this->assistant2Id])->filter()->values();
        if ($chosen->contains($employeeId)) {
            $chosen = $chosen->reject(fn ($id) => $id === $employeeId)->values();
        } elseif ($chosen->count() < 2) {
            $chosen->push($employeeId);
        } else {
            $this->addError('assistant2Id', 'Maksimal 2 asisten pendamping.');

            return;
        }

        [$this->assistantId, $this->assistant2Id] = [$chosen->get(0), $chosen->get(1)];
    }

    /** Asisten 1 dikosongkan → asisten 2 naik jadi asisten 1. */
    public function updatedAssistantId(): void
    {
        if ($this->assistantId === null && $this->assistant2Id !== null) {
            [$this->assistantId, $this->assistant2Id] = [$this->assistant2Id, null];
        } elseif ($this->assistantId !== null && $this->assistantId === $this->assistant2Id) {
            $this->assistant2Id = null;
        }
    }

    /** Tindakan bertarif range wajib diisi harganya manual; tandai baris yang kosong. true = ada yang kosong. */
    private function flagMissingPrices(): bool
    {
        $missing = false;
        foreach ($this->procedures as $i => $p) {
            if ($this->needsPrice($p)) {
                $missing = true;
                $this->addError("procedures.{$i}.unit_price", 'Isi harga '.Format::rupiah($this->standardPrice($p['procedure_id'])).' – '.Format::rupiah((int) $this->maxPrice($p['procedure_id'])).'.');
            }
        }

        return $missing;
    }

    // ── Edit tagihan setelah dikirim ke kasir (edit di tempat, belum dibayar) ──

    public function startBillingEdit(): void
    {
        abort_unless($this->canEditSentBilling, 403, 'Tagihan ini tidak dapat diubah.');
        $this->editingBilling = true;
        unset($this->billingEditable, $this->canEditPrice, $this->canGiveDiscount);
    }

    /** Batal: isian dimuat ulang dari tagihan tersimpan. */
    public function cancelBillingEdit()
    {
        return $this->redirectRoute('rekam-medis.periksa', ['visit' => $this->visitId], navigate: true);
    }

    public function saveBillingChanges(ExamService $exam): void
    {
        $this->authorizeBilling();
        $this->validateFields();
        if ($this->flagMissingPrices()) {
            return;
        }

        try {
            $exam->updateSentBilling($this->visit, $this->payload());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return;
        }

        $this->editingBilling = false;
        $this->savedHash = $this->formHash();
        unset($this->visit, $this->billingEditable, $this->canEditSentBilling, $this->canEditPrice, $this->canGiveDiscount);
        $this->dispatch('toast', message: 'Tagihan diperbarui. Kasir melihat total terbaru.');
    }

    public function saveCorrection(ExamService $exam): void
    {
        $this->validateFields();
        $exam->correct($this->visit, $this->payload(), $this->correctionReason);
        $this->savedHash = $this->formHash();

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
            'bloodPressure' => ['nullable', 'string', 'max:20', 'regex:/^\d{2,3}\s*\/\s*\d{2,3}(\s*mmhg)?$/i'],
            'currentMedication' => 'nullable|string|max:255',
            'followUp' => 'in:'.implode(',', array_keys(self::FOLLOW_UPS)),
            'prescriptions.*.drug_name' => 'nullable|string|max:255',
            'prescriptions.*.dose' => 'nullable|string|max:255',
            'prescriptions.*.quantity' => 'nullable|string|max:30',
            'prescriptions.*.item_quantity' => 'nullable|integer|min:1|max:999',
            'goods.*.item_id' => ['nullable', Rule::in($this->goodsOptions->keys()->map(fn ($id) => (string) $id)->all())],
            'goods.*.quantity' => 'required|integer|min:1|max:999',
            'discount' => 'nullable|integer|min:0',
            'discountReason' => 'nullable|string|max:255',
            'assistantId' => ['nullable', 'integer', Rule::in($this->assistantOptions->keys()->all())],
            'assistant2Id' => ['nullable', 'integer', 'different:assistantId', Rule::in($this->assistantOptions->keys()->all())],
        ], ['bloodPressure.regex' => 'Format tekanan darah: sistolik/diastolik, mis. 120/80.'],
            ['complaint' => 'keluhan utama', 'assistantId' => 'asisten pendamping', 'assistant2Id' => 'asisten 2', 'plan' => 'rencana perawatan', 'bloodPressure' => 'tekanan darah']);
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
            'goods' => $this->goods,
            'discount' => (int) $this->discount,
            'discount_reason' => $this->discountReason,
            'assistant_id' => $this->assistantId,
            'assistant2_id' => $this->assistant2Id,
        ];
    }

    private function authorizeEdit(): void
    {
        abort_unless($this->editable, 403, 'Rekam medis ini hanya dapat dibaca.');
    }

    private function authorizeBilling(): void
    {
        abort_unless($this->billingEditable, 403, 'Tindakan tidak dapat diubah setelah dikirim ke kasir.');
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
