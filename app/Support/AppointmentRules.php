<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Doctor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Aturan bersama janji temu (dipakai menu Janji temu dan "Alihkan" di Antrean):
 * dokter bertugas, bentrok jadwal dokter, dan peringatan jam kerja cabang.
 */
class AppointmentRules
{
    /** Satu dokter tidak boleh punya dua janji berdekatan (< 30 menit) di tanggal yang sama. */
    public const SLOT_MINUTES = 30;

    /** Cabang aktif klinik yang boleh dipilih sebagai tujuan janji (semua cabang, bukan hanya akses pengguna). */
    public static function branches(int $clinicId): Collection
    {
        return Branch::where('clinic_id', $clinicId)->where('is_active', true)->orderBy('id')->get();
    }

    /** Dokter aktif yang terjadwal praktik di cabang & tanggal tersebut. */
    public static function doctorsOn(int $clinicId, int $branchId, Carbon $date): Collection
    {
        return Doctor::where('clinic_id', $clinicId)->where('is_active', true)
            ->with(['schedules', 'overrides' => fn ($q) => $q->whereDate('date', $date)])
            ->orderBy('name')->get()
            ->filter(fn (Doctor $d) => ($d->scheduleOn($date)['branch_id'] ?? null) === $branchId)
            ->values();
    }

    /** Tolak bila dokter sudah punya janji terjadwal < SLOT_MINUTES dari jam ini. */
    public static function rejectDoubleBooking(int $clinicId, ?int $doctorId, string $date, ?string $time, ?int $ignoreId, string $field = 'form.time'): void
    {
        if (blank($doctorId) || blank($time)) {
            return;
        }

        $at = Carbon::parse($date.' '.$time);

        $clash = Appointment::where('clinic_id', $clinicId)
            ->where('doctor_id', $doctorId)
            ->whereDate('date', $date)
            ->where('status', Appointment::SCHEDULED)
            ->whereNotNull('time')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with('patient')
            ->get()
            ->first(fn (Appointment $a) => abs(Carbon::parse($date.' '.$a->time)->diffInMinutes($at)) < self::SLOT_MINUTES);

        if ($clash) {
            throw ValidationException::withMessages([
                $field => sprintf('Dokter ini sudah ada janji jam %s (%s). Pilih jam lain, beri jarak minimal %d menit.',
                    substr($clash->time, 0, 5), $clash->patient->name, self::SLOT_MINUTES),
            ]);
        }
    }

    /** Peringatan jam kerja cabang (null = aman). Janji tetap boleh disimpan. */
    public static function hoursWarning(Branch $branch, Carbon $date, ?string $time): ?string
    {
        $day = $date->translatedFormat('l');

        if (! $branch->hoursOn($date)) {
            return "{$branch->shortName()} tutup di hari {$day}. Janji tetap bisa disimpan.";
        }

        if (filled($time) && preg_match('/^\d{2}:\d{2}/', $time) && ! $branch->isOpenAt($date, $time)) {
            return sprintf('Jam %s di luar jam kerja %s (%s: %s). Janji tetap bisa disimpan.',
                str_replace(':', '.', substr($time, 0, 5)), $branch->shortName(), $day, $branch->hoursLabel($date));
        }

        return null;
    }
}
