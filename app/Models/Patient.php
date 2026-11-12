<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pasien bersifat lintas cabang: sengaja tidak memiliki branch_id.
 */
class Patient extends Model
{
    protected $fillable = [
        'clinic_id', 'medical_record_number', 'name', 'gender', 'birth_date', 'phone', 'nik',
        'address', 'occupation', 'emergency_contact', 'allergies',
    ];

    protected $hidden = ['nik', 'phone'];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function latestVisit(): HasOne
    {
        return $this->hasOne(Visit::class)->latestOfMany('visit_date');
    }

    /** "RM-00128" */
    public function rmCode(): string
    {
        return 'RM-'.str_pad((string) $this->medical_record_number, 5, '0', STR_PAD_LEFT);
    }

    public function age(): int
    {
        return $this->birth_date->age;
    }

    public function genderLabel(): string
    {
        return $this->gender === 'P' ? 'Perempuan' : 'Laki-laki';
    }

    public function hasAllergy(): bool
    {
        return filled($this->allergies) && strcasecmp(trim($this->allergies), 'Tidak ada') !== 0;
    }

    /** Nomor telepon disamarkan: "0812 •••• 2741" (FR-PAS-14). */
    public function maskedPhone(): string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);

        return strlen($digits) < 8 ? '••••' : substr($digits, 0, 4).' •••• '.substr($digits, -4);
    }

    /** NIK disamarkan (FR-PAS-15). */
    public function maskedNik(): ?string
    {
        return $this->nik ? substr($this->nik, 0, 4).str_repeat('•', 8).substr($this->nik, -4) : null;
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))->filter()->take(2)
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).'.')->implode(' ');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', "%{$term}%");

            if ($digits !== '') {
                $q->orWhere('phone', 'like', "%{$digits}%")
                    ->orWhere('medical_record_number', (int) $digits);
            }
        });
    }

    public static function nextMedicalRecordNumber(int $clinicId): int
    {
        return (int) static::where('clinic_id', $clinicId)->max('medical_record_number') + 1;
    }
}
