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
use App\Support\BranchContext;
use App\Support\Payroll;
use App\Support\Settings;
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
 * - Satu tombol: check in (cabang terdekat dalam radius, shift dari jam) lalu clock out. Lokasi dari browser dicek di
 *   server; radius & batas akurasi GPS = setting global (Pengaturan › Absensi).
 * - Mode uji (setting): cek lokasi & jam dilewati, cabang = cabang di header, sumber = 'uji'.
 * - Jendela shift dari jam kerja cabang: sesi 1 = pagi, sesi 2 = sore; satu sesi panjang dibelah di batas shift bawaan.
 * - Lembur asisten = absen pulang melewati akhir jadwal asisten (atau akhir shift cabang), per jam penuh.
 */
class AttendanceService
{
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

    public function isTestMode(): bool
    {
        return Settings::bool('attendance_test_mode');
    }

    /** Cabang untuk Mode uji = cabang yang dipilih di header (null bila "Semua cabang" atau Mode uji mati). */
    public function testBranchFor(BranchContext $context): ?Branch
    {
        return $this->isTestMode() && $context->id() ? $context->options()->firstWhere('id', $context->id()) : null;
    }

    public function radius(): int
    {
        return max(10, Settings::int('attendance_radius_m'));
    }

    public function maxAccuracy(): int
    {
        return max(10, Settings::int('attendance_max_accuracy_m'));
    }

    /**
     * Cabang aktif terdekat (boleh diakses user & sudah berkoordinat) yang berada dalam radius.
     *
     * @return array{0:Branch, 1:int} [cabang, jarak meter]
     */
    public function detectBranch(User $user, ?float $lat, ?float $lng, ?float $accuracy): array
    {
        $this->requireAccurate($lat, $lng, $accuracy);
        $branches = $user->accessibleBranches()->filter(fn (Branch $b) => $b->hasLocation());
        if ($branches->isEmpty()) {
            throw ValidationException::withMessages(['absen' => 'Koordinat cabang belum diatur. Owner mengisinya di Master › Cabang.']);
        }

        [$branch, $distance] = $branches
            ->map(fn (Branch $b) => [$b, self::distance($lat, $lng, (float) $b->latitude, (float) $b->longitude)])
            ->sortBy(fn ($pair) => $pair[1])->first();
        if ($distance > $this->radius()) {
            throw ValidationException::withMessages(['absen' => "Anda berada {$distance} m dari {$branch->name} (batas {$this->radius()} m). Absen hanya bisa di klinik."]);
        }

        return [$branch, $distance];
    }

    /**
     * Shift dari jam sekarang: shift yang sedang berjalan, atau shift berikutnya yang mulai ≤ EARLY_MINUTES lagi.
     * $lenient (mode uji): di luar jam kerja tetap dapat shift — sebelum batas shift = pagi, sesudahnya = sore.
     */
    public function detectShift(Branch $branch, CarbonInterface $now, bool $lenient = false): int
    {
        $now = Carbon::instance($now);
        $windows = $this->windows($branch, $now);
        foreach ($windows as $shift => [$from, $to]) {
            if ($now->gte($now->copy()->setTimeFromTimeString($from)) && $now->lt($now->copy()->setTimeFromTimeString($to))) {
                return $shift;
            }
        }
        foreach ($windows as $shift => [$from]) {
            $start = $now->copy()->setTimeFromTimeString($from);
            if ($now->lt($start) && $now->gte($start->copy()->subMinutes(self::EARLY_MINUTES))) {
                return $shift;
            }
        }
        if ($lenient) {
            return $now->format('H:i') < ($windows[2][0] ?? Payroll::DEFAULT_SHIFT_BOUNDARY) ? 1 : 2;
        }

        $hours = $windows ? collect($windows)->map(fn ($w) => $w[0].'–'.$w[1])->implode(', ') : 'tutup';
        throw ValidationException::withMessages(['absen' => "Di luar jam kerja {$branch->name} hari ini ({$hours}). Check in bisa mulai ".self::EARLY_MINUTES.' menit sebelum shift.']);
    }

    /** Absen hari ini yang sudah check in tapi belum clock out. */
    public function openRecord(array $person, CarbonInterface $date): ?Model
    {
        return $this->query($person)->whereDate('date', $date)->whereNotNull('check_in_at')->whereNull('check_out_at')->first();
    }

    /** Semua absen orang itu hari ini, urut shift. */
    public function todayRecords(array $person, CarbonInterface $date)
    {
        return $this->query($person)->with('branch')->whereDate('date', $date)->orderBy('shift')->get();
    }

    /**
     * Check in: orang dari akun, tanggal = hari ini, cabang dari lokasi, shift dari jam.
     * Mode uji: lokasi & jam tidak dicek, cabang = $testBranch (cabang di header).
     */
    public function checkIn(User $user, ?float $lat, ?float $lng, ?float $accuracy, ?Branch $testBranch = null, ?CarbonInterface $now = null): Model
    {
        $now = Carbon::instance($now ?? now());
        $person = $this->requirePerson($user);
        $test = $this->isTestMode();
        if ($test) {
            $branch = $testBranch ?? throw ValidationException::withMessages(['absen' => 'Mode uji: pilih cabang di header (bukan "Semua cabang").']);
            $distance = ($lat !== null && $lng !== null && $branch->hasLocation())
                ? self::distance($lat, $lng, (float) $branch->latitude, (float) $branch->longitude) : null;
        } else {
            [$branch, $distance] = $this->detectBranch($user, $lat, $lng, $accuracy);
        }
        $shift = $this->detectShift($branch, $now, $test);

        return DB::transaction(function () use ($person, $branch, $shift, $now, $lat, $lng, $accuracy, $distance, $user, $test) {
            $open = $this->query($person)->whereDate('date', $now)->whereNotNull('check_in_at')->whereNull('check_out_at')->lockForUpdate()->first();
            if ($open) {
                throw ValidationException::withMessages(['absen' => 'Anda masih check in di shift '.$this->label($open->shift).'. Clock out dulu.']);
            }
            $row = $this->query($person)->whereDate('date', $now)->where('shift', $shift)->lockForUpdate()->first();
            if ($row?->check_in_at) {
                throw ValidationException::withMessages(['absen' => 'Anda sudah absen shift '.$this->label($shift).' hari ini.']);
            }

            $values = [
                'branch_id' => $branch->id, 'check_in_at' => $now, 'check_in_lat' => $lat, 'check_in_lng' => $lng,
                'check_in_accuracy' => $accuracy !== null ? (int) round($accuracy) : null, 'distance_m' => $distance,
                'source' => $test ? DoctorShiftAttendance::SOURCE_TEST : DoctorShiftAttendance::SOURCE_LOCATION, 'recorded_by' => $user->id,
            ];
            if ($row) {
                $row->fill($values)->save(); // tanda hadir manual Owner dilengkapi jam absen
            } else {
                $key = $person['type'] === 'doctor' ? 'doctor_id' : 'employee_id';
                $class = $person['type'] === 'doctor' ? DoctorShiftAttendance::class : EmployeeShiftAttendance::class;
                $row = $class::create($values + [$key => $person['model']->id, 'date' => $now->toDateString(), 'shift' => $shift]);
            }
            AuditLog::record('absensi.masuk', $row, ['shift' => $shift, 'cabang' => $branch->name, 'jarak_m' => $distance, 'akurasi_m' => $accuracy, 'mode_uji' => $test]);

            return $row;
        });
    }

    /** Clock out: menutup check in yang masih terbuka hari ini; lokasi harus dalam radius cabang tempat check in. */
    public function clockOut(User $user, ?float $lat, ?float $lng, ?float $accuracy, ?CarbonInterface $now = null): Model
    {
        $now = Carbon::instance($now ?? now());
        $person = $this->requirePerson($user);
        $test = $this->isTestMode();

        return DB::transaction(function () use ($person, $now, $lat, $lng, $accuracy, $test) {
            $row = $this->query($person)->whereDate('date', $now)->whereNotNull('check_in_at')->whereNull('check_out_at')->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['absen' => 'Belum check in hari ini.']);
            $branch = $row->branch;
            if (! $test) {
                $this->requireNearby($branch, $lat, $lng, $accuracy);
            }

            $row->fill(['check_out_at' => $now, 'check_out_lat' => $lat, 'check_out_lng' => $lng]);
            if ($row instanceof EmployeeShiftAttendance) {
                $windowEnd = $this->windows($branch, $now)[$row->shift][1] ?? $now->format('H:i');
                $row->overtime_minutes = $this->overtimeMinutes($row->employee, $branch, $now, $row->shift, $windowEnd);
            }
            $row->save();
            AuditLog::record('absensi.pulang', $row, ['shift' => $row->shift, 'lembur_menit' => $row->overtime_minutes ?? 0, 'mode_uji' => $test]);

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

    private function requirePerson(User $user): array
    {
        return $this->personFor($user)
            ?? throw ValidationException::withMessages(['absen' => 'Akun Anda belum terhubung ke data dokter atau karyawan. Hubungi Owner.']);
    }

    private function requireAccurate(?float $lat, ?float $lng, ?float $accuracy): void
    {
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            throw ValidationException::withMessages(['absen' => 'Lokasi tidak valid. Izinkan akses lokasi lalu coba lagi.']);
        }
        if ($accuracy === null || $accuracy > $this->maxAccuracy()) {
            throw ValidationException::withMessages(['absen' => 'Sinyal lokasi kurang akurat ('.($accuracy !== null ? (int) $accuracy.' m' : 'tidak diketahui').', batas '.$this->maxAccuracy().' m). Nyalakan GPS lalu coba lagi.']);
        }
    }

    private function requireNearby(Branch $branch, ?float $lat, ?float $lng, ?float $accuracy): int
    {
        if (! $branch->hasLocation()) {
            throw ValidationException::withMessages(['absen' => "Lokasi {$branch->name} belum diatur. Owner mengisinya di Master › Cabang."]);
        }
        $this->requireAccurate($lat, $lng, $accuracy);

        $distance = self::distance($lat, $lng, (float) $branch->latitude, (float) $branch->longitude);
        if ($distance > $this->radius()) {
            throw ValidationException::withMessages(['absen' => "Anda berada {$distance} m dari {$branch->name} (batas {$this->radius()} m). Clock out harus di cabang tempat check in."]);
        }

        return $distance;
    }
}
