<?php

namespace App\Models;

use App\Enums\VisitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Visit extends Model
{
    protected $fillable = [
        'patient_id', 'doctor_id', 'branch_id', 'registered_by', 'visit_date', 'queue_number',
        'scheduled_at', 'complaint', 'purpose', 'status',
        'called_at', 'examined_at', 'sent_to_cashier_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'status' => VisitStatus::class,
            'called_at' => 'datetime',
            'examined_at' => 'datetime',
            'sent_to_cashier_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ToothFinding::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /** Masih dalam pemeriksaan: belum dikirim ke kasir. */
    public function isOpenForExam(): bool
    {
        return in_array($this->status, [VisitStatus::Menunggu, VisitStatus::Dipanggil, VisitStatus::Diperiksa], true);
    }

    public function queueLabel(): string
    {
        return str_pad((string) $this->queue_number, 2, '0', STR_PAD_LEFT);
    }

    /** Filter cabang; null = semua cabang (khusus Owner). */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $branchId ? $query->where('branch_id', $branchId) : $query;
    }

    /** Nomor antrean berikutnya per dokter per cabang per hari (SRS §1.3). */
    public static function nextQueueNumber(int $branchId, int $doctorId, string $date): int
    {
        return (int) static::where([
            'branch_id' => $branchId,
            'doctor_id' => $doctorId,
            'visit_date' => $date,
        ])->max('queue_number') + 1;
    }
}
