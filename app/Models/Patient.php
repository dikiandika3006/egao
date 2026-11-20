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
        'clinic_id', 'medical_record_number', 'name', 'gender', 'birth_date', 'birth_place', 'phone', 'nik',
        'address', 'occupation', 'emergency_contact', 'allergies', 'referred_by', 'ihs_patient_id',
    ];

    protected $hidden = ['nik', 'phone'];

    protected static function booted(): void
    {
        // NIK berubah → ID IHS SATUSEHAT lama tidak berlaku, dicari ulang saat kirim.
        static::saving(function (self $patient) {
            if ($patient->isDirty('nik') && ! $patient->isDirty('ihs_patient_id')) {
                $patient->ihs_patient_id = null;
            }
        });
    }

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

    /** "A-021" (format klinik); nomor angka lama tampil "RM-00128". */
    public function rmCode(): string
    {
        $rm = (string) $this->medical_record_number;

        return ctype_digit($rm) ? 'RM-'.str_pad($rm, 5, '0', STR_PAD_LEFT) : $rm;
    }

    /** Umur dalam tahun, null bila tanggal lahir belum diketahui. */
    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    /** "34 tahun" / "34 th"; "umur –" bila tanggal lahir kosong. */
    public function ageLabel(bool $short = false): string
    {
        $age = $this->age();

        return $age === null ? 'umur –' : $age.($short ? ' th' : ' tahun');
    }

    /** "Malang, 18 Sep 2003" / "—". */
    public function birthLabel(string $format = 'j M Y'): string
    {
        $parts = array_filter([$this->birth_place, $this->birth_date?->translatedFormat($format)]);

        return $parts ? implode(', ', $parts) : '—';
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

        if ($digits === '') {
            return '—';
        }

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
        $rm = static::normalizeRm($term);

        return $query->where(function (Builder $q) use ($term, $digits, $rm) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('medical_record_number', $rm);

            if ($digits !== '') {
                $q->orWhere('phone', 'like', "%{$digits}%");

                // Nomor angka lama: "128" / "RM-00128".
                if (preg_match('/^(RM)?[\s\-]*\d+$/i', $term)) {
                    $q->orWhere('medical_record_number', ltrim($digits, '0') ?: '0');
                }
            }
        });
    }

    /**
     * Rapikan tulisan No. RM: "a-5", "A- 021", "Q_003", "E -64" → "A-005", "A-021", "Q-003", "E-064".
     * Format lain (mis. "MR000003") cukup di-trim dan dijadikan huruf besar.
     */
    public static function normalizeRm(string $value): string
    {
        $value = strtoupper(trim($value));

        if (preg_match('/^([A-Z])\s*[-_.\s]?\s*(\d+)$/', $value, $m)) {
            return $m[1].'-'.str_pad(ltrim($m[2], '0') ?: '0', 3, '0', STR_PAD_LEFT);
        }

        return $value;
    }

    /** Huruf RM dari nama: huruf awal setelah gelar dr./drg. diabaikan; bukan huruf → "X". */
    public static function rmLetter(string $name): string
    {
        // "dr.Atika", "(dr) Limanto", "drg. Budi" — tapi "Drajat" tetap D.
        $name = preg_replace('/^[\s(`\'".]*(drg?(\.|\))\)?\s*|drg?\s+)?/i', '', trim($name));
        $first = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 1));

        return $first !== '' ? $first : 'X';
    }

    /** No. RM berikutnya untuk huruf awal nama, mis. "Budi" → "B-043". */
    public static function nextMedicalRecordNumber(int $clinicId, string $name): string
    {
        $letter = static::rmLetter($name);
        $max = static::where('clinic_id', $clinicId)
            ->where('medical_record_number', 'like', $letter.'-%')
            ->pluck('medical_record_number')
            ->map(fn ($rm) => preg_match('/^[A-Z]-(\d+)$/', $rm, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $letter.'-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
