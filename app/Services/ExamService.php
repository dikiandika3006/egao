<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Models\AuditLog;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\MedicalRecord;
use App\Models\Procedure;
use App\Models\User;
use App\Models\Visit;
use App\Services\SatuSehat\SatuSehatService;
use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Settings;
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

    /**
     * Admin/Asisten/Owner boleh mengisi pemeriksaan atas nama dokter kunjungan (mis. dokter mendikte, admin mengetik),
     * hanya untuk cabang yang boleh diakses. Penginput tercatat di created_by / audit.
     */
    public function isStaffEditor(Visit $visit): bool
    {
        return $this->context->is(Role::Admin, Role::Asisten, Role::Owner)
            && $this->context->options()->contains('id', $visit->branch_id);
    }

    /** Catatan klinis boleh diubah: dokter pemilik atau Admin/Owner, kunjungan tidak batal. Setelah final, perubahan = koreksi berversi. */
    public function canEditClinical(Visit $visit): bool
    {
        return ($this->isOwningDoctor($visit) || $this->isStaffEditor($visit))
            && $visit->status !== VisitStatus::Batal
            && Access::can('rekam-medis', 'edit');
    }

    /**
     * Tindakan & tagihan boleh diubah selama belum dikirim ke kasir, dan hanya dari cabang kunjungan
     * (FR-AUT-05: dokter di cabang lain tetap bisa membaca, tidak bisa memulai/mengisi kunjungan).
     */
    public function canEditBilling(Visit $visit): bool
    {
        return $this->canEditClinical($visit)
            && $visit->isOpenForExam()
            && ($this->context->id() === $visit->branch_id || ($this->context->isAll() && $this->isStaffEditor($visit)));
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
            $this->persistBilling($visit, $data['procedures'] ?? [], Invoice::STATUS_DRAFT, $data['prescriptions'] ?? [], $this->discountFrom($visit, $data));

            return $record;
        });
    }

    /** FR-RM-19 / FR-ANT-07: final, buat tagihan untuk kasir cabang yang sama. */
    public function sendToCashier(Visit $visit, array $data): MedicalRecord
    {
        abort_unless($this->canEditBilling($visit), 403, 'Rekam medis ini tidak dapat diubah.');

        $this->validateForSend($visit, $data);

        $record = DB::transaction(function () use ($visit, $data) {
            $record = $this->persistClinical($visit, $data);
            $invoice = $this->persistBilling($visit, $data['procedures'], Invoice::STATUS_UNPAID, $data['prescriptions'] ?? [], $this->discountFrom($visit, $data));

            $record->update(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()]);
            $this->snapshot($record->fresh(), 'Dikirim ke kasir');

            $visit->update(['status' => VisitStatus::MenungguPembayaran, 'sent_to_cashier_at' => now()]);
            $this->syncFollowUp($visit, $record->fresh());

            AuditLog::record('rekam_medis.kirim_kasir', $visit, ['invoice_id' => $invoice->id, 'total' => $invoice->total]);

            return $record;
        });

        // SATUSEHAT: masuk antrean kirim setelah transaksi berhasil (tanpa HTTP di sini).
        $this->queueSatuSehat($visit);

        return $record;
    }

    /** Gagal mengantrekan SATUSEHAT tidak boleh mengganggu alur kasir. */
    private function queueSatuSehat(Visit $visit): void
    {
        try {
            app(SatuSehatService::class)->queue($visit->fresh());
        } catch (\Throwable $e) {
            report($e);
        }
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

    /**
     * Diskon tagihan diberikan dokter penanggung jawab (atau Owner) di pemeriksaan, sebelum dikirim ke kasir.
     * Admin/Asisten yang mengisi pemeriksaan atas nama dokter tidak bisa mengubahnya.
     */
    public function canGiveDiscount(Visit $visit): bool
    {
        return ($this->isOwningDoctor($visit) || $this->context->is(Role::Owner)) && $this->canEditBilling($visit);
    }

    /** Batas diskon dokter (% dari subtotal) dari Pengaturan; Owner tidak dibatasi. */
    public function maxDiscount(int $subtotal): int
    {
        return $this->context->is(Role::Owner)
            ? $subtotal
            : (int) floor($subtotal * Settings::int('admin_max_discount_percent') / 100);
    }

    /** Diskon dari isian pemeriksaan; null = tidak berhak / tidak dikirim → diskon tersimpan dipertahankan. */
    private function discountFrom(Visit $visit, array $data): ?array
    {
        if (! array_key_exists('discount', $data) || ! $this->canGiveDiscount($visit)) {
            return null;
        }

        return ['amount' => max(0, (int) $data['discount']), 'reason' => $this->clean($data['discount_reason'] ?? null, 255)];
    }

    /** Dokter & Owner boleh mengubah harga tindakan (≥ tarif minimal; di bawahnya hanya bila tidak selesai). */
    public function canRaisePrice(): bool
    {
        return $this->context->is(Role::Dokter, Role::Owner);
    }

    /**
     * Harga standar = tarif minimal cabang kunjungan saat disimpan (FR-RM-15), lalu dibekukan di invoice_items.
     * Tarif bisa berupa range (min–maks). Dokter/Owner boleh mengisi unit_price ≥ minimal; di atas maks tetap boleh tetapi
     * dicatat di audit (bagi hasil ikut harga ini). Di bawah minimal hanya bila dicentang "tidak selesai" (atau tindakan
     * bertanda "harga boleh di bawah standar") dan beralasan. Tindakan bertarif terkunci selalu tarif minimal.
     * Peran lain tidak bisa mengubah harga, tetapi harga yang sudah tersimpan untuk tindakan yang sama dipertahankan.
     * Biaya bahan resep dibekukan per baris (material_cost); biaya admin ditagih sekali di tagihan pertama pasien.
     */
    private function persistBilling(Visit $visit, array $procedures, string $status, array $prescriptions = [], ?array $discount = null): Invoice
    {
        $ids = collect($procedures)->pluck('procedure_id')->unique();
        $master = Procedure::with(['prices', 'materials.item'])->whereIn('id', $ids)->get()->keyBy('id');
        $canRaise = $this->canRaisePrice();
        $stock = app(StockService::class);

        // Harga yang sudah tersimpan per tindakan (antrian sesuai urutan) untuk dipertahankan saat Admin menyimpan.
        $existing = InvoiceItem::whereHas('invoice', fn ($q) => $q->where('visit_id', $visit->id))->orderBy('id')
            ->get(['procedure_id', 'unit_price', 'price_reason', 'is_incomplete', 'is_admin_fee']);
        $saved = $existing->whereNotNull('procedure_id')->groupBy('procedure_id')
            ->map(fn ($g) => $g->map(fn ($i) => ['price' => (int) $i->unit_price, 'reason' => $i->price_reason, 'incomplete' => (bool) $i->is_incomplete])->values()->all())
            ->all();

        $raised = [];
        $lowered = [];

        $items = collect($procedures)->map(function (array $p) use ($master, $visit, $canRaise, $stock, &$saved, &$raised, &$lowered) {
            $procedure = $master->get((int) $p['procedure_id']);
            $range = $procedure?->rangeFor($visit->branch_id);

            if (! $procedure || $range === null) {
                throw ValidationException::withMessages(['procedures' => 'Tindakan tidak tersedia di cabang ini.']);
            }

            [$standard, $max] = $range;
            $requested = filled($p['unit_price'] ?? null) ? (int) $p['unit_price'] : null;
            $reason = $this->clean($p['price_reason'] ?? null, 255);
            $incomplete = $canRaise ? (bool) ($p['is_incomplete'] ?? false) : false;
            $previous = ! empty($saved[$procedure->id]) ? array_shift($saved[$procedure->id]) : null;

            if ($procedure->price_locked) {
                $price = $standard;
                $incomplete = false;
            } elseif ($canRaise && $requested !== null) {
                if ($requested < $standard && ! $incomplete && ! $procedure->allow_lower_price) {
                    throw ValidationException::withMessages([
                        'procedures' => "Harga {$procedure->name} di bawah tarif minimal Rp ".number_format($standard, 0, ',', '.')
                            .'. Centang "Tidak selesai" bila tindakannya tidak selesai.',
                    ]);
                }
                $price = max(0, $requested);
            } elseif ($previous !== null && ($previous['price'] >= $standard || $previous['incomplete'] || $procedure->allow_lower_price)) {
                $price = $previous['price'];
                $reason ??= $previous['reason'];
                $incomplete = $previous['incomplete'];
            } else {
                $price = $standard;
            }

            if ($price < $standard) {
                if (! $reason) {
                    throw ValidationException::withMessages([
                        'procedures' => "Tuliskan alasan harga {$procedure->name} di bawah tarif minimal (mis. cabut tidak selesai).",
                    ]);
                }
                $lowered[] = ['tindakan' => $procedure->name, 'standar' => $standard, 'harga' => $price, 'alasan' => $reason, 'tidak_selesai' => $incomplete];
            } else {
                $reason = null;
                $incomplete = false;
            }

            // Di dalam range = wajar; di atas maks (atau di atas tarif tunggal) dicatat di audit.
            if ($price > ($max ?? $standard)) {
                $raised[] = ['tindakan' => $procedure->name, 'standar' => $standard, 'maks' => $max, 'harga' => $price];
            }

            $qty = max(1, min(99, (int) ($p['quantity'] ?? 1)));
            $material = (int) round($procedure->materials->sum(
                fn ($m) => $m->item ? $m->quantity * $stock->unitCost($m->item, $visit->branch_id) : 0
            ) * $qty);

            return [
                'procedure_id' => $procedure->id,
                'doctor_id' => $visit->doctor_id,
                'name' => $procedure->name,
                'tooth' => $this->clean($p['tooth'] ?? null, 10),
                'quantity' => $qty,
                'unit_price' => $price,
                'standard_price' => $standard,
                'price_reason' => $reason,
                'is_incomplete' => $incomplete,
                'material_cost' => $material,
                'amount' => $price * $qty,
            ];
        });

        // Obat resep dari stok klinik ikut ditagihkan (tanpa doctor_id → tidak masuk bagi hasil).
        $goods = Item::whereIn('id', collect($prescriptions)->pluck('item_id')->filter())->whereNotNull('sell_price')->get()->keyBy('id');
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

        $invoice = Invoice::firstOrNew(['visit_id' => $visit->id]);

        // Biaya admin & kartu: sekali per pasien. Ditambahkan selama tagihan masih baru/draf; setelah dikirim ke kasir
        // hanya dipertahankan (bila Kasir menghapusnya, tidak muncul lagi).
        $adminFee = Settings::int('admin_fee');
        $hadAdmin = $existing->contains('is_admin_fee', true);
        $fresh = ! $invoice->exists || $invoice->status === Invoice::STATUS_DRAFT;
        if ($adminFee > 0 && ($hadAdmin || ($fresh && ! $this->patientHasAdminFee($visit->patient_id, $invoice->id)))) {
            $items->push([
                'doctor_id' => null, 'name' => self::ADMIN_FEE_NAME, 'tooth' => null, 'is_admin_fee' => true,
                'quantity' => 1, 'unit_price' => $adminFee, 'amount' => $adminFee,
            ]);
        }

        $total = (int) $items->sum('amount');

        // Diskon dokter (per tagihan): wajib beralasan, dibatasi % di Pengaturan (Owner bebas).
        if ($discount !== null) {
            $max = $this->maxDiscount($total);
            if ($discount['amount'] > $max) {
                throw ValidationException::withMessages(['discount' => 'Diskon maksimal Rp '.number_format($max, 0, ',', '.').'.']);
            }
            if ($discount['amount'] > 0 && ! $discount['reason']) {
                throw ValidationException::withMessages(['discountReason' => 'Tuliskan alasan diskon.']);
            }
            $changed = (int) $invoice->discount !== $discount['amount'];
            $invoice->discount = $discount['amount'];
            $invoice->discount_reason = $discount['amount'] > 0 ? $discount['reason'] : null;
            if ($changed && $status !== Invoice::STATUS_DRAFT) {
                AuditLog::record('invoice.discount', $invoice, ['discount' => $invoice->discount, 'reason' => $invoice->discount_reason, 'oleh' => 'pemeriksaan']);
            }
        }

        $invoice->fill([
            'branch_id' => $visit->branch_id,
            'subtotal' => $total,
            'total' => max(0, $total - (int) $invoice->discount),
            'status' => $status,
        ])->save();

        $invoice->items()->delete();
        $invoice->items()->createMany($items->all());

        // Bahan resep tindakan: stok dipotong (pecahan dikumpulkan) sejak tagihan dikirim ke kasir.
        $stock->syncMaterials($invoice);

        if ($status !== Invoice::STATUS_DRAFT) {
            if ($raised) {
                AuditLog::record('tindakan.harga_dinaikkan', $invoice, ['tindakan' => $raised]);
            }
            if ($lowered) {
                AuditLog::record('tindakan.harga_diturunkan', $invoice, ['tindakan' => $lowered]);
            }
        }

        return $invoice;
    }

    public const ADMIN_FEE_NAME = 'Biaya admin & kartu';

    /** Pasien sudah pernah ditagih biaya admin (tagihan lain yang tidak batal). */
    private function patientHasAdminFee(int $patientId, ?int $exceptInvoiceId): bool
    {
        return InvoiceItem::where('is_admin_fee', true)
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', Invoice::STATUS_VOID)
                ->when($exceptInvoiceId, fn ($q) => $q->whereKeyNot($exceptInvoiceId))
                ->where(fn ($q) => $q->where('patient_id', $patientId)->orWhereHas('visit', fn ($v) => $v->where('patient_id', $patientId))))
            ->exists();
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
                'discount' => ['amount' => (int) $visit->invoice?->discount, 'reason' => $visit->invoice?->discount_reason],
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
