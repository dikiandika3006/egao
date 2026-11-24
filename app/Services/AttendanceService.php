<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShiftAttendance;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\EmployeeShiftAttendance;
use App\Models\User;
use App\Support\Payroll;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Absensi berbasis lokasi per shift (pagi = 1, sore = 2) untuk dokter & asisten.
 *
 * - Satu sumber kehadiran: dokter → doctor_shift_attendances (dasar uang duduk), asisten → employee_shift_attendances
 *   (dasar upah shift & lembur). Centang manual Owner di Jadwal menulis ke tabel yang sama.
 * - Lokasi dari browser (Geolocation API) dicek di server: jarak ke koordinat cabang ≤ radius, akurasi ≤ MAX_ACCURACY_M.
 * - Jendela shift dari jam kerja cabang: sesi 1 = pagi, sesi 2 = sore; satu sesi panjang dibelah di batas shift bawaan.
 * - Lembur asisten = absen pulang melewati akhir jadwal asisten (atau akhir shift cabang), per jam penuh.
 */
class AttendanceService
{
    public const MAX_ACCURACY_M = 150;

    /** Absen masuk boleh mulai sekian menit sebelum shift dimulai. */
    public const EARLY_MINUTES = 60;

    /**
     * Dokter atau karyawan yang terhubung ke akun ini.
     *
     * @return array{type:string, model:Doctor|Employee}|null
     */
    public function personFor(User $user): ?array
    {
        $doctor = Doctor::where('clinic_id', $user->clinic_id)->where('user_id', $user->id)->where('is_active', true)->first();
        if ($doctor) {
            return ['type' => 'doctor', 'model' => $doctor];
        }
        $employee = Employee::where('clinic_id', $user->clinic_id)->where('user_id', $user->id)->where('is_active', true)->first();

        return $employee ? ['type' => 'employee', 'model' => $employee] : null;
    }

    /**
     * Jendela shift cabang pada tanggal itu: [shift => [mulai H:i, selesai H:i]].
     *
     * @return array<int, array{0:string, 1:string}>
     */
    public function windows(Branch $branch, CarbonInterface $date): array
    {
        $sessions = array_values($branch->hoursOn($date));
        if (count($sessions) >= 2) {
            return [1 => [$sessions[0][0], $sessions[0][1]], 2 => [$sessions[1][0], $sessions[count($sessions) - 1][1]]];
        }
        if (count($sessions) === 1) {
            [$start, $end] = $sessions[0];
            $cut = Payroll::DEFAULT_SHIFT_BOUNDARY;
            if ($start < $cut && $end > $cut) {
                return [1 => [$start, $cut], 2 => [$cut, $end]];
            }

            return [($start >= $cut ? 2 : 1) => [$start, $end]];
        }

        return [];
    }

    /** Jarak dua titik dalam meter (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** Catatan kehadiran orang itu pada tanggal & shift (null = belum ada). */
    public function record(array $person, CarbonInterface $date, int $shift): ?Model
    {
        return $this->query($person)->whereDate('date', $date)->where('shift', $shift)->first();
    }

    public function query(array $person)
    {
        return $person['type'] === 'doctor'
            ? DoctorShiftAttendance::where('doctor_id', $person['model']->id)
            : EmployeeShiftAttendance::where('employee_id', $person['model']->id);
    }

    public function checkIn(User $user, Branch $branch, int $shift, float $lat, float $lng, ?float $accuracy, ?CarbonInterface $now = null): Model
    {
        $now = Carbon::instance($now ?? now());
        $person = $this->requirePerson($user);
        $window = $this->requireWindow($branch, $now, $shift);
        $distance = $this->requireNearby($branch, $lat, $lng, $accuracy);

        $start = $now->copy()->setTimeFromTimeString($window[0]);
        $end = $now->copy()->setTimeFromTimeString($window[1]);
        if ($now->lt($start->copy()->subMinutes(self::EARLY_MINUTES)) || $now->gte($end)) {
            throw ValidationException::withMessages(['absen' => 'Absen masuk shift '.$this->label($shift)." hanya bisa sejak {$this->earliest($start)} sampai {$window[1]}."]);
        }

        return DB::transaction(function () use ($person, $branch, $shift, $now, $lat, $lng, $accuracy, $distance, $user) {
            $row = $this->query($person)->whereDate('date', $now)->where('shift', $shift)->lockForUpdate()->first();
            if ($row?->check_in_at) {
                throw ValidationException::withMessages(['absen' => 'Anda sudah absen masuk shift '.$this->label($shift).' hari ini.']);
            }

            $values = [
                'branch_id' => $branch->id, 'check_in_at' => $now, 'check_in_lat' => $lat, 'check_in_lng' => $lng,
                'check_in_accuracy' => $accuracy !== null ? (int) round($accuracy) : null, 'distance_m' => $distance,
                'source' => DoctorShiftAttendance::SOURCE_LOCATION, 'recorded_by' => $user->id,
            ];
            if ($row) {
                $row->fill($values)->save(); // tanda hadir manual Owner dilengkapi jam absen
            } else {
                $key = $person['type'] === 'doctor' ? 'doctor_id' : 'employee_id';
                $class = $person['type'] === 'doctor' ? DoctorShiftAttendance::class : EmployeeShiftAttendance::class;
                $row = $class::create($values + [$key => $person['model']->id, 'date' => $now->toDateString(), 'shift' => $shift]);
            }
            AuditLog::record('absensi.masuk', $row, ['shift' => $shift, 'jarak_m' => $distance, 'akurasi_m' => $accuracy]);

            return $row;
        });
    }

    public function checkOut(User $user, Branch $branch, int $shift, float $lat, float $lng, ?float $accuracy, ?CarbonInterface $now = null): Model
    {
        $now = Carbon::instance($now ?? now());
        $person = $this->requirePerson($user);
        $window = $this->requireWindow($branch, $now, $shift);
        $this->requireNearby($branch, $lat, $lng, $accuracy);

        return DB::transaction(function () use ($person, $branch, $shift, $now, $lat, $lng, $window) {
            $row = $this->query($person)->whereDate('date', $now)->where('shift', $shift)->lockForUpdate()->first();
            if (! $row?->check_in_at) {
                throw ValidationException::withMessages(['absen' => 'Belum absen masuk shift '.$this->label($shift).' hari ini.']);
            }
            if ($row->check_out_at) {
                throw ValidationException::withMessages(['absen' => 'Anda sudah absen pulang shift '.$this->label($shift).' hari ini.']);
            }
            if ((int) $row->branch_id !== $branch->id) {
                throw ValidationException::withMessages(['absen' => 'Absen pulang harus di cabang yang sama dengan absen masuk.']);
            }

            $row->fill(['check_out_at' => $now, 'check_out_lat' => $lat, 'check_out_lng' => $lng]);
            if ($row instanceof EmployeeShiftAttendance) {
                $row->overtime_minutes = $this->overtimeMinutes($row->employee, $branch, $now, $shift, $window[1]);
            }
            $row->save();
            AuditLog::record('absensi.pulang', $row, ['shift' => $shift, 'lembur_menit' => $row->overtime_minutes ?? 0]);

            return $row;
        });
    }

    /** Menit lembur (kelipatan 60) setelah akhir jadwal asisten pada shift itu, atau akhir shift cabang. */
    public function overtimeMinutes(Employee $employee, Branch $branch, CarbonInterface $checkOut, int $shift, string $windowEnd): int
    {
        $end = $this->scheduledEnd($employee, $branch, $checkOut, $shift) ?? $windowEnd;
        $over = Carbon::instance($checkOut)->copy()->setTimeFromTimeString($end)->diffInMinutes($checkOut, false);

        return $over >= 60 ? (int) (floor($over / 60) * 60) : 0;
    }

    /** Akhir jadwal rutin asisten yang jatuh di shift itu (null = tidak ada jadwal). */
    private function scheduledEnd(Employee $employee, Branch $branch, CarbonInterface $date, int $shift): ?string
    {
        $windows = $this->windows($branch, $date);
        $sessions = EmployeeSchedule::where('employee_id', $employee->id)->where('branch_id', $branch->id)->get()
            ->filter(fn (EmployeeSchedule $s) => $s->appliesOn($date))->sortBy('starts_at')->values();
        $boundary = $windows[2][0] ?? Payroll::DEFAULT_SHIFT_BOUNDARY;

        $match = $sessions->filter(fn ($s) => $shift === 1 ? substr($s->starts_at, 0, 5) < $boundary : substr($s->starts_at, 0, 5) >= $boundary)->last();

        return $match ? substr((string) $match->ends_at, 0, 5) : null;
    }

    public function label(int $shift): string
    {
        return DoctorShiftAttendance::SHIFT_LABELS[$shift] ?? (string) $shift;
    }

    private function earliest(CarbonInterface $start): string
    {
        return $start->copy()->subMinutes(self::EARLY_MINUTES)->format('H:i');
    }

    private function requirePerson(User $user): array
    {
        return $this->personFor($user)
            ?? throw ValidationException::withMessages(['absen' => 'Akun Anda belum terhubung ke data dokter atau karyawan. Hubungi Owner.']);
    }

    private function requireWindow(Branch $branch, CarbonInterface $date, int $shift): array
    {
        if (! in_array($shift, [1, 2], true)) {
            throw ValidationException::withMessages(['absen' => 'Shift tidak dikenal.']);
        }

        return $this->windows($branch, $date)[$shift]
            ?? throw ValidationException::withMessages(['absen' => "{$branch->name} tidak buka untuk shift ".$this->label($shift).' hari ini.']);
    }

    private function requireNearby(Branch $branch, float $lat, float $lng, ?float $accuracy): int
    {
        if (! $branch->hasLocation()) {
            throw ValidationException::withMessages(['absen' => "Lokasi {$branch->name} belum diatur. Owner mengisinya di Pengaturan › Cabang."]);
        }
        if (abs($lat) > 90 || abs($lng) > 180) {
            throw ValidationException::withMessages(['absen' => 'Lokasi tidak valid.']);
        }
        if ($accuracy === null || $accuracy > self::MAX_ACCURACY_M) {
            throw ValidationException::withMessages(['absen' => 'Sinyal lokasi kurang akurat ('.($accuracy !== null ? (int) $accuracy.' m' : 'tidak diketahui').'). Nyalakan GPS lalu coba lagi.']);
        }

        $distance = self::distance($lat, $lng, (float) $branch->latitude, (float) $branch->longitude);
        if ($distance > $branch->attendance_radius_m) {
            throw ValidationException::withMessages(['absen' => "Anda berada {$distance} m dari {$branch->name} (batas {$branch->attendance_radius_m} m). Absen hanya bisa di klinik."]);
        }

        return $distance;
    }
}
