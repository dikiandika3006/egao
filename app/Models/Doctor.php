<?php

namespace App\Models;

use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    protected $fillable = [
        'clinic_id', 'user_id', 'primary_branch_id', 'name', 'calendar_email', 'str_number', 'sip_number', 'nik', 'ihs_practitioner_id', 'joined_at',
        'base_salary', 'share_percent', 'is_active',
    ];

    protected $hidden = ['nik'];

    protected static function booted(): void
    {
        // NIK berubah → ID Practitioner SATUSEHAT lama tidak berlaku, dicari ulang saat kirim.
        static::saving(function (self $doctor) {
            if ($doctor->isDirty('nik') && ! $doctor->isDirty('ihs_practitioner_id')) {
                $doctor->ihs_practitioner_id = null;
            }
        });
    }

    /** NIK disamarkan, sama seperti pasien. */
    public function maskedNik(): ?string
    {
        return $this->nik ? substr($this->nik, 0, 4).str_repeat('•', 8).substr($this->nik, -4) : null;
    }

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
            $starts = $override->starts_at ?? $routine?->starts_at ?? '10:00:00';
            $ends = $override->ends_at ?? $routine?->ends_at ?? '17:00:00';

            return static::withSessions([
                'branch_id' => $override->branch_id,
                'moved' => true,
            ], [['starts_at' => $starts, 'ends_at' => $ends]]);
        }

        $routine = $this->routineOn($date);
        if (! $routine) {
            return null;
        }

        // Satu hari bisa lebih dari satu sesi di cabang yang sama (mis. 10.00–13.00 dan 17.00–20.00).
        $sessions = $this->schedules
            ->filter(fn (DoctorSchedule $s) => $s->appliesOn($date) && $s->branch_id === $routine->branch_id)
            ->sortBy('starts_at')
            ->map(fn (DoctorSchedule $s) => ['starts_at' => $s->starts_at, 'ends_at' => $s->ends_at])
            ->values()->all();

        return static::withSessions(['branch_id' => $routine->branch_id, 'moved' => false], $sessions);
    }

    /**
     * Lengkapi slot dengan daftar sesi, teks jam siap tampil ("10.00–13.00, 17.00–20.00"),
     * serta starts_at = sesi pertama dan ends_at = sesi terakhir.
     */
    private static function withSessions(array $slot, array $sessions): array
    {
        return $slot + [
            'starts_at' => $sessions[0]['starts_at'],
            'ends_at' => end($sessions)['ends_at'],
            'sessions' => $sessions,
            'hours' => collect($sessions)
                ->map(fn ($s) => Format::jam($s['starts_at']).'–'.Format::jam($s['ends_at']))
                ->implode(', '),
        ];
    }

    public function routineOn(CarbonInterface $date): ?DoctorSchedule
    {
        return $this->schedules->first(fn (DoctorSchedule $s) => $s->appliesOn($date));
    }
}
