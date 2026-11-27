<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\DoctorProcedureShare;
use App\Models\DoctorSchedule;
use App\Models\DoctorShiftAttendance;
use App\Models\Employee;
use App\Models\EmployeeShiftAttendance;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrder;
use App\Models\PayrollAdjustment;
use App\Models\PayrollHour;
use App\Models\Procedure;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Perhitungan gaji & bagi hasil (FR-GJI-01 … 08), komponen per peran diatur di Pengaturan → Gaji & bagi hasil.
 *
 * Aturan Owner 24 Nov 2026: fee dihitung dari TINDAKAN (tagihan sudah dikirim ke kasir, tidak draf/batal) menurut tanggal
 * kunjungan — tidak menunggu pembayaran; piutang/cicilan ditanggung klinik.
 *
 * Dokter  = gaji pokok + UD per jam (lama) + uang duduk per shift + fee − potongan lab + bonus/THR − potongan.
 *           Dasar fee per baris = harga setelah diskon tagihan (dibagi proporsional) − biaya modal × qty − biaya bahan resep.
 *           Prioritas %fee: khusus dokter × tindakan → tindakan → dokter → global.
 *           Tindakan bertahap (gigi palsu, behel): bila tindakan punya "dasar ikut tindakan" (fee_from_procedure_id),
 *           tindakan tahap awal pasien yang sama sejak tahap akhir sebelumnya ikut dasar fee (DP cetak → insersi).
 *           Potongan lab = biaya lab × %fee. Bila ada tindakan bertanda "potong lab" (fee_minus_lab), lab pasien dipotong
 *           pada tindakan itu memakai %fee tindakan; bila tidak ada, cara lama: order lab dokter
 *           yang dikirim dalam periode (tidak batal) × %fee dokter.
 *           Uang duduk per shift: tiap shift yang ditandai hadir (Jadwal), bila kotor tindakan shift itu (sebelum diskon
 *           & potongan) < ambang → dokter dapat uang duduk dan fee shift itu 0; ≥ ambang → fee normal, tanpa uang duduk.
 * Asisten = gaji pokok + upah shift + fee asisten (nominal per tindakan bila diisi, selain itu %fee asisten × harga setelah
 *           diskon) pada kunjungan yang didampingi + lembur + bonus/THR − potongan.
 *           Upah shift (24 Nov 2026): tiap shift hadir (absen lokasi / centang Jadwal) × tarif pagi/sore asisten itu
 *           (kosong = tarif global). Lembur = menit lembur dari absen pulang. Bulan tanpa data kehadiran memakai cara
 *           lama: jam hadir × UD per jam dan jam lembur yang diisi manual di Gaji (agar tidak terhitung ganda).
 * Admin   = gaji pokok + lembur + bonus/THR − potongan (semua jabatan selain asisten).
 */
class Payroll
{
    public const ROLE_ASSISTANT = 'asisten';

    public const ROLE_ADMIN = 'admin';

    /** Status tagihan yang tindakannya sudah dihitung (sudah dikirim ke kasir). */
    public const COUNTED_STATUSES = [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL, Invoice::STATUS_PAID];

    /** Batas shift 1/2 bila jadwal dokter hari itu tidak punya dua sesi. */
    public const DEFAULT_SHIFT_BOUNDARY = '14:00';

    /** Peran gaji karyawan berdasarkan jabatannya. */
    public static function employeeRole(Employee $e): string
    {
        return $e->position === 'Asisten dokter' ? self::ROLE_ASSISTANT : self::ROLE_ADMIN;
    }

    /** Tagihan yang tindakannya dihitung dalam rentang tanggal kunjungan. */
    private static function countedInvoices(Builder $q, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $q->whereIn('status', self::COUNTED_STATUSES)
            ->whereHas('visit', fn ($v) => $v->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()]));
    }

    /** Nominal baris setelah diskon tagihan dibagi proporsional terhadap subtotal. */
    public static function netAmount(InvoiceItem $i): int
    {
        $amount = (int) $i->amount;
        $subtotal = (int) $i->invoice?->subtotal;
        $discount = (int) $i->invoice?->discount;

        if ($discount <= 0 || $subtotal <= 0) {
            return $amount;
        }

        return max(0, $amount - (int) round($discount * $amount / $subtotal));
    }

    /** Waktu tindakan: jam diperiksa (fallback dipanggil/daftar) pada tanggal kunjungan. */
    private static function performedAt(InvoiceItem $i): Carbon
    {
        $visit = $i->invoice->visit;
        $time = $visit->examined_at ?? $visit->called_at ?? $visit->created_at;

        return $visit->visit_date->copy()->setTimeFrom($time ?? $visit->visit_date);
    }

    /**
     * @return Collection<int, array{doctor:Doctor, gross:int, cost:int, lab:int, lab_deduction:int, share:int, base:int,
     *     hours:float, ud:int, hourly_ud:int, sitting:int, sitting_shifts:int, shifts:Collection, bonus:int, deduction:int,
     *     total:int, count:int, branches:array<int,array{gross:int,share:int}>, procedures:Collection, lines:Collection}>
     */
    public static function forMonth(int $clinicId, CarbonInterface $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $global = Settings::globalShare();
        $hourly = Settings::int('doctor_hourly_rate');
        $sittingFee = Settings::int('doctor_sitting_fee');
        $threshold = Settings::int('doctor_sitting_threshold');

        $items = InvoiceItem::query()
            ->whereNotNull('doctor_id')
            ->whereNotNull('procedure_id')
            ->whereHas('invoice', fn ($q) => static::countedInvoices($q, $start, $end))
            ->with([
                'invoice:id,visit_id,branch_id,patient_id,subtotal,discount',
                'invoice.visit:id,patient_id,visit_date,examined_at,called_at,created_at',
                'procedure:id,share_percent,cost_amount,fee_from_procedure_id,fee_minus_lab',
            ])
            ->get();

        $staged = static::stagedContext($clinicId, $items, $end);
        $items = $items->groupBy('doctor_id');

        $labs = $staged['lab_at_procedure'] ? collect() : LabOrder::query()
            ->where('clinic_id', $clinicId)
            ->whereNotNull('doctor_id')
            ->where('status', '!=', 'batal')
            ->whereBetween('sent_at', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'doctor_id', 'branch_id', 'cost'])
            ->groupBy('doctor_id');

        $adjustments = static::adjustments($clinicId, $start, PayrollAdjustment::PAYEE_DOCTOR);
        $hours = static::hours($clinicId, $start, PayrollAdjustment::PAYEE_DOCTOR);

        $attendances = DoctorShiftAttendance::query()
            ->whereHas('doctor', fn ($q) => $q->where('clinic_id', $clinicId))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')->orderBy('shift')
            ->get()
            ->groupBy('doctor_id');
        $schedules = DoctorSchedule::whereIn('doctor_id', $attendances->keys())->get()->groupBy('doctor_id');

        // Tarif khusus dokter × tindakan, kunci "doctorId:procedureId".
        $special = DoctorProcedureShare::query()
            ->whereHas('doctor', fn ($q) => $q->where('clinic_id', $clinicId))
            ->get()
            ->mapWithKeys(fn (DoctorProcedureShare $s) => [$s->doctor_id.':'.$s->procedure_id => (float) $s->share_percent]);

        return Doctor::query()
            ->where('clinic_id', $clinicId)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $items->keys())->orWhereIn('id', $attendances->keys()))
            ->orderBy('name')
            ->get()
            ->map(function (Doctor $doctor) use ($items, $labs, $global, $hourly, $adjustments, $hours, $special, $staged, $attendances, $schedules, $sittingFee, $threshold) {
                $rows = ($items->get($doctor->id) ?? collect())->map(function (InvoiceItem $i) use ($doctor, $global, $special, $staged) {
                    $percent = (float) ($special[$doctor->id.':'.$i->procedure_id] ?? $i->procedure?->share_percent ?? $doctor->share_percent ?? $global);
                    $net = static::netAmount($i);
                    $modal = (int) ($i->procedure?->cost_amount ?? 0) * (int) $i->quantity;
                    $cost = min($net, $modal + (int) $i->material_cost);
                    [$carry, $lab] = static::stageAmounts($i, $staged);
                    $share = max(0, (int) round(($net + $carry - $cost) * $percent / 100));
                    $at = static::performedAt($i);

                    return [
                        'name' => $i->name,
                        'branch_id' => $i->invoice->branch_id,
                        'date' => $at,
                        'quantity' => (int) $i->quantity,
                        'gross' => (int) $i->amount,
                        'discount' => (int) $i->amount - $net,
                        'cost' => $cost,
                        'material' => min($cost, (int) $i->material_cost),
                        'carry' => $carry,
                        'lab' => $lab,
                        'percent' => $percent,
                        'share' => $share,
                        'sitting' => false,
                        'shift' => null,
                        // Potongan lab kasus ini (tidak melebihi fee barisnya).
                        'lab_cut' => min($share, (int) round($lab * $percent / 100)),
                    ];
                });

                // Uang duduk per shift hadir: kotor shift < ambang → UD, fee baris shift itu dinolkan.
                [$rows, $shifts] = static::applySitting($rows, $attendances->get($doctor->id) ?? collect(), $schedules->get($doctor->id) ?? collect(), $sittingFee, $threshold);

                // Potongan lab: pada tindakan "potong lab" (cabang tindakan itu), atau cara lama
                // %fee dokter (atau global) × order lab yang dikirim, dibebankan ke cabang order lab.
                $doctorPercent = (float) ($doctor->share_percent ?? $global);
                $labRows = $labs->get($doctor->id) ?? collect();
                $labByBranch = $staged['lab_at_procedure']
                    ? $rows->where('lab_cut', '>', 0)->groupBy('branch_id')->map(fn ($g) => (int) $g->sum('lab_cut'))
                    : $labRows->groupBy('branch_id')->map(fn ($g) => (int) round($g->sum('cost') * $doctorPercent / 100));

                $branches = $rows->groupBy('branch_id')
                    ->map(fn ($g) => ['gross' => (int) $g->sum('gross'), 'share' => (int) $g->sum('share')])
                    ->all();
                foreach ($labByBranch as $branchId => $amount) {
                    $branches[$branchId] ??= ['gross' => 0, 'share' => 0];
                    $branches[$branchId]['share'] -= $amount;
                }

                $labDeduction = (int) $labByBranch->sum();
                $share = max(0, (int) $rows->sum('share') - $labDeduction);
                $base = (int) $doctor->base_salary;
                $workHours = (float) ($hours->get($doctor->id)?->work_hours ?? 0);
                $hourlyUd = (int) round($workHours * $hourly);
                $sitting = (int) $shifts->sum('sitting_fee');
                $ud = $hourlyUd + $sitting;
                $adj = $adjustments->get($doctor->id, collect());
                [$bonus, $deduction] = static::sumAdjustments($adj);

                return [
                    'doctor' => $doctor,
                    'gross' => (int) $rows->sum('gross'),
                    'discount' => (int) $rows->sum('discount'),
                    'cost' => (int) $rows->sum('cost'),
                    'material' => (int) $rows->sum('material'),
                    'lab' => $staged['lab_at_procedure'] ? (int) $rows->sum('lab') : (int) $labRows->sum('cost'),
                    'carry' => (int) $rows->sum('carry'),
                    'lab_deduction' => $labDeduction,
                    'share' => $share,
                    'base' => $base,
                    'hours' => $workHours,
                    'hourly_ud' => $hourlyUd,
                    'sitting' => $sitting,
                    'sitting_shifts' => $shifts->where('sitting_fee', '>', 0)->count(),
                    'shifts' => $shifts,
                    'ud' => $ud,
                    'bonus' => $bonus,
                    'deduction' => $deduction,
                    'adjustments' => $adj->values(),
                    'total' => max(0, $share + $base + $ud + $bonus - $deduction),
                    'count' => $rows->count(),
                    'branches' => $branches,
                    'procedures' => $rows->groupBy('name')->map(fn ($g, $name) => [
                        'name' => $name, 'count' => $g->count(), 'gross' => $g->sum('gross'), 'cost' => $g->sum('cost'), 'carry' => $g->sum('carry'),
                        'discount' => $g->sum('discount'), 'percent' => $g->first()['percent'], 'share' => $g->sum('share'),
                    ])->sortByDesc('gross')->values(),
                    // Rincian per tanggal & cabang untuk slip (FR-GJI-03).
                    'lines' => $rows->sortBy('date')->values(),
                ];
            });
    }

    /**
     * Tandai baris ke shift hadir dan hitung uang duduk per shift.
     *
     * @return array{0:Collection, 1:Collection<int, array{date:string, shift:int, branch_id:int, gross:int, sitting_fee:int}>}
     */
    private static function applySitting(Collection $rows, Collection $attendances, Collection $schedules, int $fee, int $threshold): array
    {
        if ($attendances->isEmpty()) {
            return [$rows, collect()];
        }

        $byDate = $attendances->groupBy(fn ($a) => $a->date->toDateString());

        // Tentukan shift tiap baris di tanggal yang punya tanda hadir.
        $rows = $rows->map(function (array $r) use ($byDate, $schedules) {
            $marks = $byDate->get($r['date']->toDateString());
            if (! $marks) {
                return $r;
            }
            $shiftNos = $marks->pluck('shift')->unique()->values();
            $r['shift'] = $shiftNos->count() === 1
                ? (int) $shiftNos->first()
                : ($r['date']->format('H:i') >= static::shiftBoundary($schedules, $r['date']) ? 2 : 1);

            return $r;
        });

        $shifts = collect();
        foreach ($attendances as $a) {
            $date = $a->date->toDateString();
            $inShift = fn (array $r) => $r['shift'] === (int) $a->shift && $r['date']->toDateString() === $date;
            $gross = (int) $rows->filter($inShift)->sum('gross');
            $sits = $fee > 0 && $gross < $threshold;

            if ($sits) {
                $rows = $rows->map(fn (array $r) => $inShift($r) ? ['sitting' => true, 'share' => 0, 'lab_cut' => 0] + $r : $r);
            }

            $shifts->push(['date' => $date, 'shift' => (int) $a->shift, 'branch_id' => (int) $a->branch_id, 'gross' => $gross, 'sitting_fee' => $sits ? $fee : 0]);
        }

        return [$rows, $shifts];
    }

    /** Jam mulai sesi ke-2 jadwal rutin dokter pada tanggal itu; bila hanya satu sesi, pakai batas bawaan. */
    private static function shiftBoundary(Collection $schedules, CarbonInterface $date): string
    {
        $sessions = $schedules->filter(fn (DoctorSchedule $s) => $s->appliesOn($date))->sortBy('starts_at')->values();

        if ($sessions->count() < 2) {
            return self::DEFAULT_SHIFT_BOUNDARY;
        }

        return substr((string) $sessions[1]->starts_at, 0, 5);
    }

    /**
     * Gaji karyawan non-dokter (FR-GJI-05) sesuai peran: asisten atau admin.
     *
     * @return Collection<int, array{employee:Employee, role:string, base:int, hours:float, ud:int, overtime_hours:float,
     *     overtime:int, assisted:int, assistant_percent:float, assistant_share:int, assistant_branches:array<int,int>,
     *     bonus:int, deduction:int, total:int, adjustments:Collection}>
     */
    public static function employeesForMonth(int $clinicId, CarbonInterface $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $adjustments = static::adjustments($clinicId, $start, PayrollAdjustment::PAYEE_EMPLOYEE);
        $hours = static::hours($clinicId, $start, PayrollAdjustment::PAYEE_EMPLOYEE);

        $rates = [
            self::ROLE_ASSISTANT => [
                'hourly' => Settings::int('assistant_hourly_rate'),
                'overtime' => Settings::int('assistant_overtime_rate'),
                'percent' => Settings::float('assistant_share_percent'),
                'morning' => Settings::int('assistant_morning_rate'),
                'evening' => Settings::int('assistant_evening_rate'),
            ],
            self::ROLE_ADMIN => ['hourly' => 0, 'overtime' => Settings::int('admin_overtime_rate'), 'percent' => 0.0],
        ];

        // Tindakan pada kunjungan yang didampingi, per asisten per cabang. Maks 2 asisten per kunjungan;
        // masing-masing mendapat fee penuh dari tindakan yang sama (keputusan Owner 27 Nov 2026).
        $assisted = InvoiceItem::query()
            ->whereNotNull('procedure_id')
            ->whereHas('invoice', fn ($q) => static::countedInvoices($q, $start, $end)
                ->whereHas('visit', fn ($v) => $v->where(fn ($v) => $v->whereNotNull('assistant_id')->orWhereNotNull('assistant2_id'))))
            ->with('invoice:id,visit_id,branch_id,subtotal,discount', 'invoice.visit:id,assistant_id,assistant2_id', 'procedure:id,assistant_fee_amount')
            ->get(['id', 'invoice_id', 'procedure_id', 'quantity', 'amount'])
            ->flatMap(fn (InvoiceItem $i) => collect([$i->invoice->visit->assistant_id, $i->invoice->visit->assistant2_id])
                ->filter()->unique()->map(fn ($employeeId) => ['employee_id' => $employeeId, 'item' => $i]))
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->pluck('item'));

        $attendances = EmployeeShiftAttendance::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('employee', fn ($q) => $q->where('clinic_id', $clinicId))
            ->get()
            ->groupBy('employee_id');

        return Employee::query()
            ->where('clinic_id', $clinicId)
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', $adjustments->keys())
                ->orWhereIn('id', $assisted->keys())
                ->orWhereIn('id', $attendances->keys()))
            ->with('primaryBranch')
            ->orderBy('name')
            ->get()
            ->map(function (Employee $e) use ($adjustments, $hours, $rates, $assisted, $attendances) {
                $role = static::employeeRole($e);
                $rate = $rates[$role];
                $h = $hours->get($e->id);
                $present = $role === self::ROLE_ASSISTANT ? ($attendances->get($e->id) ?? collect()) : collect();
                $fromAttendance = $present->isNotEmpty();

                $rateMorning = $e->morning_shift_rate ?? ($rate['morning'] ?? 0);
                $rateEvening = $e->evening_shift_rate ?? ($rate['evening'] ?? 0);
                $morning = $present->where('shift', 1)->count();
                $evening = $present->where('shift', 2)->count();

                if ($fromAttendance) {
                    $workHours = round($present->sum(fn (EmployeeShiftAttendance $a) => $a->workedMinutes()) / 60, 2);
                    $overtimeHours = round($present->sum('overtime_minutes') / 60, 2);
                } else {
                    $workHours = $role === self::ROLE_ASSISTANT ? (float) ($h?->work_hours ?? 0) : 0.0;
                    $overtimeHours = (float) ($h?->overtime_hours ?? 0);
                }

                $items = $role === self::ROLE_ASSISTANT ? ($assisted->get($e->id) ?? collect()) : collect();
                $feeOf = fn (InvoiceItem $i) => $i->procedure?->assistant_fee_amount !== null
                    ? (int) $i->procedure->assistant_fee_amount * (int) $i->quantity
                    : (int) round(static::netAmount($i) * $rate['percent'] / 100);
                $assistantBranches = $items->groupBy(fn ($i) => $i->invoice->branch_id)
                    ->map(fn ($g) => (int) $g->sum($feeOf))
                    ->all();

                $adj = $adjustments->get($e->id, collect());
                [$bonus, $deduction] = static::sumAdjustments($adj);
                $base = (int) $e->base_salary;
                $shiftPay = $morning * $rateMorning + $evening * $rateEvening;
                $ud = $fromAttendance ? $shiftPay : (int) round($workHours * $rate['hourly']);
                $overtime = (int) round($overtimeHours * $rate['overtime']);
                $assistantShare = (int) array_sum($assistantBranches);

                return [
                    'employee' => $e,
                    'role' => $role,
                    'base' => $base,
                    'hours' => $workHours,
                    'ud' => $ud,
                    'from_attendance' => $fromAttendance,
                    'shift_morning' => $morning,
                    'shift_evening' => $evening,
                    'rate_morning' => $rateMorning,
                    'rate_evening' => $rateEvening,
                    'overtime_hours' => $overtimeHours,
                    'overtime' => $overtime,
                    'assisted' => (int) $items->sum(fn ($i) => static::netAmount($i)),
                    'assisted_count' => $items->count(),
                    'assistant_percent' => $rate['percent'],
                    'assistant_share' => $assistantShare,
                    'assistant_branches' => $assistantBranches,
                    'bonus' => $bonus,
                    'deduction' => $deduction,
                    'adjustments' => $adj->values(),
                    'total' => max(0, $base + $ud + $overtime + $assistantShare + $bonus - $deduction),
                ];
            });
    }

    /** Omzet (pembayaran lunas) tahun berjalan s.d. akhir bulan terpilih vs target tahunan. */
    public static function yearToDate(int $clinicId, CarbonInterface $month): array
    {
        $revenue = (int) Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereHas('branch', fn ($q) => $q->where('clinic_id', $clinicId))
            ->whereBetween('paid_at', [$month->copy()->startOfYear(), $month->copy()->endOfMonth()])
            ->sum('total');
        $target = Settings::int('annual_revenue_target');

        return ['revenue' => $revenue, 'target' => $target, 'reached' => $target > 0 && $revenue >= $target];
    }

    /**
     * Data pendukung tindakan bertahap: riwayat tindakan & order lab pasien yang relevan.
     *
     * @return array{lab_at_procedure:bool, minus_lab_ids:array<int,int>, history:Collection, labs:Collection}
     */
    private static function stagedContext(int $clinicId, Collection $items, CarbonInterface $end): array
    {
        $minusLabIds = Procedure::where('clinic_id', $clinicId)->where('fee_minus_lab', true)->pluck('id')->all();
        $context = ['lab_at_procedure' => $minusLabIds !== [], 'minus_lab_ids' => $minusLabIds, 'history' => collect(), 'labs' => collect()];

        $staged = $items->filter(fn (InvoiceItem $i) => static::patientOf($i)
            && ($i->procedure?->fee_from_procedure_id || $i->procedure?->fee_minus_lab));
        if ($staged->isEmpty()) {
            return $context;
        }

        $patientIds = $staged->map(fn (InvoiceItem $i) => static::patientOf($i))->unique()->values()->all();
        $procedureIds = $staged->pluck('procedure_id')
            ->merge($staged->pluck('procedure.fee_from_procedure_id'))
            ->merge($minusLabIds)->filter()->unique()->values()->all();

        $context['history'] = InvoiceItem::query()
            ->whereIn('procedure_id', $procedureIds)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', self::COUNTED_STATUSES)
                ->whereHas('visit', fn ($v) => $v->whereIn('patient_id', $patientIds)->where('visit_date', '<=', $end->toDateString())))
            ->with(['invoice:id,visit_id,patient_id,subtotal,discount', 'invoice.visit:id,patient_id,visit_date,examined_at,called_at,created_at'])
            ->get(['id', 'invoice_id', 'procedure_id', 'amount'])
            ->map(fn (InvoiceItem $h) => [
                'id' => $h->id, 'procedure_id' => (int) $h->procedure_id, 'patient_id' => static::patientOf($h),
                'at' => static::performedAt($h)->getTimestamp(), 'amount' => static::netAmount($h),
            ]);

        if ($context['lab_at_procedure']) {
            $context['labs'] = LabOrder::query()
                ->where('clinic_id', $clinicId)
                ->whereIn('patient_id', $patientIds)
                ->where('status', '!=', 'batal')
                ->whereNotNull('sent_at')
                ->where('sent_at', '<=', $end->toDateString())
                ->get(['patient_id', 'sent_at', 'cost']);
        }

        return $context;
    }

    /** Pasien tagihan: kunjungan atau pembeli penjualan langsung. */
    private static function patientOf(InvoiceItem $i): int
    {
        return (int) ($i->invoice?->visit?->patient_id ?? $i->invoice?->patient_id);
    }

    /**
     * [tindakan tahap awal yang ikut dasar fee, biaya lab yang dipotong] untuk satu baris tindakan.
     * Jendela = sejak tindakan akhir sebelumnya milik pasien yang sama (kasus lama tidak terhitung dua kali).
     *
     * @return array{0:int,1:int}
     */
    private static function stageAmounts(InvoiceItem $i, array $staged): array
    {
        $procedure = $i->procedure;
        $patientId = static::patientOf($i);
        if (! $patientId || ! $procedure || (! $procedure->fee_from_procedure_id && ! $procedure->fee_minus_lab)) {
            return [0, 0];
        }

        $at = static::performedAt($i)->getTimestamp();
        $mine = $staged['history']->where('patient_id', $patientId);
        // Baris sebelum baris ini (urut waktu tindakan, lalu id) di antara tindakan tertentu.
        $previous = fn (array $ids) => $mine
            ->filter(fn ($h) => in_array($h['procedure_id'], $ids, true) && ($h['at'] < $at || ($h['at'] === $at && $h['id'] < $i->id)))
            ->max('at');

        $carry = 0;
        if ($procedure->fee_from_procedure_id) {
            $since = $previous([(int) $i->procedure_id]);
            $carry = (int) $mine
                ->filter(fn ($h) => $h['procedure_id'] === (int) $procedure->fee_from_procedure_id && $h['at'] <= $at && ($since === null || $h['at'] > $since))
                ->sum('amount');
        }

        $lab = 0;
        if ($procedure->fee_minus_lab) {
            $since = $previous($staged['minus_lab_ids']);
            $sinceDate = $since === null ? null : date('Y-m-d', $since);
            $today = date('Y-m-d', $at);
            $lab = (int) $staged['labs']
                ->filter(fn ($l) => (int) $l->patient_id === $patientId && $l->sent_at->toDateString() <= $today
                    && ($sinceDate === null || $l->sent_at->toDateString() > $sinceDate))
                ->sum('cost');
        }

        return [$carry, $lab];
    }

    /** @return array{0:int,1:int} [bonus (termasuk THR & bonus akhir tahun), potongan] */
    private static function sumAdjustments(Collection $adj): array
    {
        return [
            (int) $adj->where('type', '!=', PayrollAdjustment::TYPE_DEDUCTION)->sum('amount'),
            (int) $adj->where('type', PayrollAdjustment::TYPE_DEDUCTION)->sum('amount'),
        ];
    }

    /** @return Collection<int, PayrollHour> per payee_id */
    private static function hours(int $clinicId, CarbonInterface $start, string $payeeType): Collection
    {
        return PayrollHour::query()
            ->where('clinic_id', $clinicId)
            ->where('payee_type', $payeeType)
            ->whereDate('period', $start->toDateString())
            ->get()
            ->keyBy('payee_id');
    }

    /** @return Collection<int, Collection<int, PayrollAdjustment>> dikelompokkan per payee_id */
    private static function adjustments(int $clinicId, CarbonInterface $start, string $payeeType): Collection
    {
        return PayrollAdjustment::query()
            ->where('clinic_id', $clinicId)
            ->where('payee_type', $payeeType)
            ->whereDate('period', $start->toDateString())
            ->orderBy('id')
            ->get()
            ->groupBy('payee_id');
    }
}
