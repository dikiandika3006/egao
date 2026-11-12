<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Models\AuditLog;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\MedicalRecord;
use App\Models\Procedure;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logika modul Rekam medis (FR-RM). Komponen Livewire hanya mengirim data form ke sini.
 *
 * Bentuk $data:
 *  complaint, anamnesis, blood_pressure, current_medication, plan, follow_up_label, follow_up_date, allergy_confirmed,
 *  findings[]      = {tooth, condition_id, surfaces[], note}
 *  diagnoses[]     = {code, primary}
 *  procedures[]    = {procedure_id, tooth, quantity}
 *  prescriptions[] = {drug_name, dose, quantity}
 */
class ExamService
{
    public function __construct(private BranchContext $context) {}

    /** Dokter penanggung jawab kunjungan, dengan peran aktif Dokter (FR-RM: hanya dokter bertugas yang mengubah). */
    public function isOwningDoctor(Visit $visit, ?User $user = null): bool
    {
        $user ??= auth()->user();
        $visit->loadMissing('doctor');

        return $user !== null
            && $this->context->is(Role::Dokter)
            && $visit->doctor->user_id === $user->id;
    }

    /** Catatan klinis boleh diubah: dokter pemilik, kunjungan tidak batal. Setelah final, perubahan = koreksi berversi. */
    public function canEditClinical(Visit $visit): bool
    {
        return $this->isOwningDoctor($visit) && $visit->status !== VisitStatus::Batal;
    }

    /**
     * Tindakan & tagihan boleh diubah selama belum dikirim ke kasir, dan hanya dari cabang kunjungan
     * (FR-AUT-05: dokter di cabang lain tetap bisa membaca, tidak bisa memulai/mengisi kunjungan).
     */
    public function canEditBilling(Visit $visit): bool
    {
        return $this->canEditClinical($visit)
            && $visit->isOpenForExam()
            && $this->context->id() === $visit->branch_id;
    }

    /** FR-ANT-06: dokter membuka rekam medis → status Sedang diperiksa. */
    public function start(Visit $visit): void
    {
        if ($this->canEditBilling($visit) && in_array($visit->status, [VisitStatus::Menunggu, VisitStatus::Dipanggil], true)) {
            $visit->update([
                'status' => VisitStatus::Diperiksa,
                'called_at' => $visit->called_at ?? now(),
                'examined_at' => now(),
            ]);
        }
    }

    /** FR-RM-20: simpan semua isian tanpa mengirim ke kasir. */
    public function saveDraft(Visit $visit, array $data): MedicalRecord
    {
        abort_unless($this->canEditBilling($visit), 403, 'Rekam medis ini tidak dapat diubah.');

        return DB::transaction(function () use ($visit, $data) {
            $record = $this->persistClinical($visit, $data);
            $this->persistBilling($visit, $data['procedures'] ?? [], Invoice::STATUS_DRAFT, $data['prescriptions'] ?? []);

            return $record;
        });
    }

    /** FR-RM-19 / FR-ANT-07: final, buat tagihan untuk kasir cabang yang sama. */
    public function sendToCashier(Visit $visit, array $data): MedicalRecord
    {
        abort_unless($this->canEditBilling($visit), 403, 'Rekam medis ini tidak dapat diubah.');

        $this->validateForSend($visit, $data);

        return DB::transaction(function () use ($visit, $data) {
            $record = $this->persistClinical($visit, $data);
            $invoice = $this->persistBilling($visit, $data['procedures'], Invoice::STATUS_UNPAID, $data['prescriptions'] ?? []);

            $record->update(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()]);
            $this->snapshot($record->fresh(), 'Dikirim ke kasir');

            $visit->update(['status' => VisitStatus::MenungguPembayaran, 'sent_to_cashier_at' => now()]);
            $this->syncFollowUp($visit, $record->fresh());

            AuditLog::record('rekam_medis.kirim_kasir', $visit, ['invoice_id' => $invoice->id, 'total' => $invoice->total]);

            return $record;
        });
    }

    /** FR-RM-22: koreksi setelah final = versi baru; tindakan/tagihan tidak berubah. */
    public function correct(Visit $visit, array $data, string $reason): MedicalRecord
    {
        $record = $visit->medicalRecord;

        abort_unless($record?->isFinal() && $this->canEditClinical($visit), 403, 'Koreksi tidak diizinkan.');

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['correctionReason' => 'Tuliskan alasan koreksi.']);
        }

        return DB::transaction(function () use ($visit, $data, $reason) {
            $record = $this->persistClinical($visit, $data);
            $this->snapshot($record->fresh(), $reason);
            $this->syncFollowUp($visit, $record->fresh());
            AuditLog::record('rekam_medis.koreksi', $record, ['reason' => $reason]);

            return $record;
        });
    }

    /**
     * Rencana evaluasi dokter → janji kontrol otomatis di cabang & dokter yang sama ("Janji temu").
     * Janji yang sudah dibuat dari kunjungan ini ikut diperbarui/dibatalkan bila rencana berubah.
     */
    private function syncFollowUp(Visit $visit, MedicalRecord $record): void
    {
        $existing = Appointment::where('source_visit_id', $visit->id)->where('status', Appointment::SCHEDULED)->first();

        if (! $record->follow_up_date) {
            $existing?->update(['status' => Appointment::CANCELLED, 'note' => 'Rencana evaluasi dihapus dokter']);

            return;
        }

        Appointment::updateOrCreate(
            ['source_visit_id' => $visit->id, 'status' => Appointment::SCHEDULED],
            [
                'clinic_id' => $visit->patient()->value('clinic_id'),
                'patient_id' => $visit->patient_id,
                'branch_id' => $visit->branch_id,
                'doctor_id' => $visit->doctor_id,
                'date' => $record->follow_up_date->toDateString(),
                'purpose' => $record->follow_up_label ?: 'Kontrol',
                'note' => str($record->plan)->limit(200)->toString() ?: null,
                'source' => 'kontrol',
                'created_by' => auth()->id(),
            ],
        );
    }

    public function validateForSend(Visit $visit, array $data): void
    {
        $errors = [];

        if (blank($data['complaint'] ?? null)) {
            $errors['complaint'] = 'Keluhan utama wajib diisi.';
        }
        if (empty($data['diagnoses'])) {
            $errors['diagnoses'] = 'Tambahkan minimal satu diagnosis ICD-10.';
        }
        if (empty($data['procedures'])) {
            $errors['procedures'] = 'Tambahkan minimal satu tindakan.';
        }
        // FR-RM-21 / UAT-11: alergi wajib dikonfirmasi sebelum disimpan final.
        if (! ($data['allergy_confirmed'] ?? false)) {
            $errors['allergy_confirmed'] = 'Konfirmasi alergi pasien terlebih dahulu.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function persistClinical(Visit $visit, array $data): MedicalRecord
    {
        $record = MedicalRecord::firstOrNew(['visit_id' => $visit->id]);
        $record->fill([
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'branch_id' => $visit->branch_id,
            'complaint' => $this->clean($data['complaint'] ?? null, 500),
            'anamnesis' => $this->clean($data['anamnesis'] ?? null),
            'blood_pressure' => $this->clean($data['blood_pressure'] ?? null, 20),
            'current_medication' => $this->clean($data['current_medication'] ?? null, 255),
            'plan' => $this->clean($data['plan'] ?? null),
            'follow_up_label' => $this->clean($data['follow_up_label'] ?? null, 255),
            'follow_up_date' => $data['follow_up_date'] ?: null,
            'allergy_confirmed' => (bool) ($data['allergy_confirmed'] ?? false),
        ])->save();

        // Temuan gigi, diagnosis, resep: disinkronkan. Riwayat lama tetap ada di snapshot versi.
        $visit->findings()->delete();
        foreach ($data['findings'] ?? [] as $f) {
            $visit->findings()->create([
                'patient_id' => $visit->patient_id,
                'tooth' => (int) $f['tooth'],
                'tooth_condition_type_id' => (int) $f['condition_id'],
                'surfaces' => array_values($f['surfaces'] ?? []),
                'note' => $this->clean($f['note'] ?? null, 255),
            ]);
        }

        $visit->diagnoses()->delete();
        $hasPrimary = collect($data['diagnoses'] ?? [])->contains(fn ($d) => $d['primary'] ?? false);
        foreach (array_values($data['diagnoses'] ?? []) as $i => $d) {
            $visit->diagnoses()->create([
                'icd10_code' => $d['code'],
                'is_primary' => $hasPrimary ? (bool) ($d['primary'] ?? false) : $i === 0,
            ]);
        }

        $visit->prescriptions()->delete();
        foreach ($data['prescriptions'] ?? [] as $p) {
            if (filled($p['drug_name'] ?? null)) {
                $itemQty = filled($p['item_id'] ?? null) ? max(1, min(999, (int) ($p['item_quantity'] ?? 1))) : null;
                $visit->prescriptions()->create([
                    'item_id' => filled($p['item_id'] ?? null) ? (int) $p['item_id'] : null,
                    'drug_name' => $this->clean($p['drug_name'], 255),
                    'dose' => $this->clean($p['dose'] ?? null, 255),
                    'quantity' => $this->clean($p['quantity'] ?? null, 30),
                    'item_quantity' => $itemQty,
                ]);
            }
        }

        return $record;
    }

    /** Harga diambil dari harga cabang kunjungan saat disimpan (FR-RM-15), lalu dibekukan di invoice_items. */
    private function persistBilling(Visit $visit, array $procedures, string $status, array $prescriptions = []): Invoice
    {
        $ids = collect($procedures)->pluck('procedure_id')->unique();
        $master = Procedure::with('prices')->whereIn('id', $ids)->get()->keyBy('id');

        $items = collect($procedures)->map(function (array $p) use ($master, $visit) {
            $procedure = $master->get((int) $p['procedure_id']);
            $price = $procedure?->priceFor($visit->branch_id);

            if (! $procedure || $price === null) {
                throw ValidationException::withMessages(['procedures' => 'Tindakan tidak tersedia di cabang ini.']);
            }

            $qty = max(1, min(99, (int) ($p['quantity'] ?? 1)));

            return [
                'procedure_id' => $procedure->id,
                'doctor_id' => $visit->doctor_id,
                'name' => $procedure->name,
                'tooth' => $this->clean($p['tooth'] ?? null, 10),
                'quantity' => $qty,
                'unit_price' => $price,
                'amount' => $price * $qty,
            ];
        });

        // Obat resep dari stok klinik ikut ditagihkan (tanpa doctor_id → tidak masuk bagi hasil).
        $goods = Item::whereIn('id', collect($prescriptions)->pluck('item_id')->filter())->whereNotNull('sell_price')->get()->keyBy('id');
        $stock = app(StockService::class);
        foreach ($prescriptions as $p) {
            $item = filled($p['item_id'] ?? null) ? $goods->get((int) $p['item_id']) : null;
            if (! $item) {
                continue;
            }
            $qty = max(1, min(999, (int) ($p['item_quantity'] ?? 1)));
            if ($status !== Invoice::STATUS_DRAFT && $stock->available($item->id, $visit->branch_id) < $qty) {
                throw ValidationException::withMessages(['prescriptions' => "Stok {$item->name} di cabang ini tidak cukup untuk {$qty} {$item->unit}."]);
            }
            $items->push([
                'item_id' => $item->id, 'doctor_id' => null, 'name' => $item->name, 'tooth' => null,
                'quantity' => $qty, 'unit_price' => (int) $item->sell_price, 'amount' => (int) $item->sell_price * $qty,
            ]);
        }

        $total = (int) $items->sum('amount');

        $invoice = Invoice::firstOrNew(['visit_id' => $visit->id]);
        $invoice->fill([
            'branch_id' => $visit->branch_id,
            'subtotal' => $total,
            'total' => max(0, $total - (int) $invoice->discount),
            'status' => $status,
        ])->save();

        $invoice->items()->delete();
        $invoice->items()->createMany($items->all());

        return $invoice;
    }

    private function snapshot(MedicalRecord $record, string $reason): void
    {
        $visit = $record->visit()->with(['findings.condition', 'diagnoses', 'prescriptions', 'invoice.items'])->first();
        $version = $record->version + 1;

        $record->versions()->create([
            'version' => $version,
            'user_id' => auth()->id(),
            'reason' => $reason,
            'snapshot' => [
                'record' => $record->only(['complaint', 'anamnesis', 'blood_pressure', 'current_medication', 'plan', 'follow_up_label', 'allergy_confirmed']),
                'follow_up_date' => $record->follow_up_date?->toDateString(),
                'findings' => $visit->findings->map(fn ($f) => ['tooth' => $f->tooth, 'condition' => $f->condition->code, 'surfaces' => $f->surfaces, 'note' => $f->note])->all(),
                'diagnoses' => $visit->diagnoses->map(fn ($d) => ['code' => $d->icd10_code, 'primary' => $d->is_primary])->all(),
                'prescriptions' => $visit->prescriptions->map->only(['drug_name', 'dose', 'quantity'])->all(),
                'procedures' => $visit->invoice?->items->map->only(['name', 'tooth', 'quantity', 'unit_price', 'amount'])->all() ?? [],
            ],
        ]);

        $record->update(['version' => $version]);
    }

    private function clean(?string $value, ?int $max = null): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $max ? mb_substr($value, 0, $max) : $value;
    }
}
