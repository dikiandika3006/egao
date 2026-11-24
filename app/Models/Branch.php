<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'clinic_id', 'code', 'name', 'city', 'address', 'latitude', 'longitude', 'attendance_radius_m', 'phone', 'color',
        'opens_at', 'closes_at', 'operating_hours', 'receipt_start_number', 'receipt_footer', 'is_active',
        'satusehat_org_id', 'satusehat_client_id', 'satusehat_client_secret', 'satusehat_location_id',
    ];

    protected $hidden = ['satusehat_client_secret'];

    /** Jam kerja bawaan: Sen–Jum 10–13 & 15–20, Sab–Min 10–17 (hari ISO 1=Senin … 7=Minggu). */
    public const DEFAULT_HOURS = [
        1 => [['10:00', '13:00'], ['15:00', '20:00']],
        2 => [['10:00', '13:00'], ['15:00', '20:00']],
        3 => [['10:00', '13:00'], ['15:00', '20:00']],
        4 => [['10:00', '13:00'], ['15:00', '20:00']],
        5 => [['10:00', '13:00'], ['15:00', '20:00']],
        6 => [['10:00', '17:00']],
        7 => [['10:00', '17:00']],
    ];

    public const DAY_NAMES = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float', 'attendance_radius_m' => 'integer', 'satusehat_client_secret' => 'encrypted', 'operating_hours' => 'array'];
    }

    /** Jam kerja seminggu (hari 1–7 → daftar sesi [mulai, selesai]); kosong = tutup. */
    public function weeklyHours(): array
    {
        $hours = $this->operating_hours ?: self::DEFAULT_HOURS;

        return collect(range(1, 7))->mapWithKeys(fn ($d) => [$d => array_values($hours[$d] ?? $hours[(string) $d] ?? [])])->all();
    }

    /** Sesi jam kerja pada tanggal itu, mis. [["10:00","13:00"],["15:00","20:00"]]; [] = tutup. */
    public function hoursOn(CarbonInterface $date): array
    {
        return $this->weeklyHours()[$date->dayOfWeekIso] ?? [];
    }

    /** Apakah jam (H:i) berada di dalam salah satu sesi kerja tanggal itu. */
    public function isOpenAt(CarbonInterface $date, string $time): bool
    {
        $time = substr($time, 0, 5);

        return collect($this->hoursOn($date))->contains(fn ($s) => $time >= $s[0] && $time < $s[1]);
    }

    /** Jam buka pertama pada tanggal itu (null = tutup). */
    public function openingTime(CarbonInterface $date): ?string
    {
        return $this->hoursOn($date)[0][0] ?? null;
    }

    /** "10.00–13.00, 15.00–20.00" atau "Tutup". */
    public function hoursLabel(CarbonInterface $date): string
    {
        $sessions = $this->hoursOn($date);

        return $sessions
            ? collect($sessions)->map(fn ($s) => str_replace(':', '.', $s[0]).'–'.str_replace(':', '.', $s[1]))->implode(', ')
            : 'Tutup';
    }

    /** Koordinat cabang sudah diisi (syarat absensi berbasis lokasi). */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Kredensial SATUSEHAT lengkap untuk cabang ini. */
    public function hasSatuSehatCredentials(): bool
    {
        return filled($this->satusehat_org_id) && filled($this->satusehat_client_id) && filled($this->satusehat_client_secret);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** "Batu" dari "Cabang Batu". */
    public function shortName(): string
    {
        return str($this->name)->replace('Cabang ', '')->toString();
    }

    /** Warna penanda cabang di kisi jadwal & tabel. */
    public function badgeClass(): string
    {
        return match ($this->color) {
            'malang' => 'bg-sky-soft text-sky-ink',
            default => 'bg-leaf-100 text-brand-600',
        };
    }
}
