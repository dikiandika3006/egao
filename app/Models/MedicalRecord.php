<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicalRecord extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    protected $fillable = [
        'visit_id', 'patient_id', 'doctor_id', 'branch_id', 'complaint', 'anamnesis', 'blood_pressure',
        'current_medication', 'plan', 'follow_up_date', 'follow_up_label', 'allergy_confirmed',
        'status', 'version', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_date' => 'date',
            'allergy_confirmed' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
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

    public function versions(): HasMany
    {
        return $this->hasMany(MedicalRecordVersion::class)->orderByDesc('version');
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }
}
