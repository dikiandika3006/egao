<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    protected $fillable = [
        'clinic_id', 'user_id', 'primary_branch_id', 'name', 'str_number', 'sip_number', 'joined_at',
        'base_salary', 'share_percent', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'date',
            'share_percent' => 'decimal:2',
        ];
    }

    /** Lama bergabung, mis. "3 tahun 2 bulan". */
    public function tenureLabel(): ?string
    {
        if (! $this->joined_at) {
            return null;
        }

        $diff = $this->joined_at->diff(today());
        $parts = array_filter([
            $diff->y ? "{$diff->y} tahun" : null,
            $diff->m ? "{$diff->m} bulan" : null,
        ]);

        return $parts ? implode(' ', $parts) : 'Kurang dari 1 bulan';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ScheduleOverride::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** Diagnosis favorit dokter (FR-RM-10). */
    public function favoriteDiagnoses(): BelongsToMany
    {
        return $this->belongsToMany(Icd10Code::class, 'doctor_favorite_diagnoses', 'doctor_id', 'icd10_code');
    }

    /**
     * Jadwal efektif pada tanggal tertentu: override (pindah/libur) lebih dulu, lalu jadwal rutin.
     *
     * @return array{branch_id:int, starts_at:string, ends_at:string, moved:bool}|null  null = libur
     */
    public function scheduleOn(CarbonInterface $date): ?array
    {
        $override = $this->overrides->first(fn (ScheduleOverride $o) => $o->date->isSameDay($date));

        if ($override) {
            if ($override->type === ScheduleOverride::TYPE_OFF) {
                return null;
            }

            $routine = $this->routineOn($date);

            return [
                'branch_id' => $override->branch_id,
                'starts_at' => $override->starts_at ?? $routine?->starts_at ?? '10:00:00',
                'ends_at' => $override->ends_at ?? $routine?->ends_at ?? '17:00:00',
                'moved' => true,
            ];
        }

        $routine = $this->routineOn($date);

        return $routine ? [
            'branch_id' => $routine->branch_id,
            'starts_at' => $routine->starts_at,
            'ends_at' => $routine->ends_at,
            'moved' => false,
        ] : null;
    }

    public function routineOn(CarbonInterface $date): ?DoctorSchedule
    {
        return $this->schedules->first(fn (DoctorSchedule $s) => $s->appliesOn($date));
    }
}
