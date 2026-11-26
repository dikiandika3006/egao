<?php

namespace App\Models\Concerns;

/** Data absen masuk/pulang yang sama untuk kehadiran dokter & asisten per shift. */
trait HasCheckInOut
{
    public const SHIFT_LABELS = [1 => 'Pagi', 2 => 'Sore'];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LOCATION = 'lokasi';

    /** Absen saat Mode uji aktif (lokasi & jam tidak dicek). */
    public const SOURCE_TEST = 'uji';

    public function viaTest(): bool
    {
        return $this->source === self::SOURCE_TEST;
    }

    public function shiftLabel(): string
    {
        return self::SHIFT_LABELS[$this->shift] ?? 'Shift '.$this->shift;
    }

    /** Menit kerja dari absen masuk sampai pulang (0 bila belum lengkap). */
    public function workedMinutes(): int
    {
        if (! $this->check_in_at || ! $this->check_out_at) {
            return 0;
        }

        return max(0, (int) $this->check_in_at->diffInMinutes($this->check_out_at));
    }

    public function viaLocation(): bool
    {
        return $this->source === self::SOURCE_LOCATION;
    }
}
