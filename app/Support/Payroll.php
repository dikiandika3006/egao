<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\DoctorProcedureShare;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrder;
use App\Models\PayrollAdjustment;
use App\Models\PayrollHour;
use App\Models\Procedure;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Perhitungan gaji & bagi hasil (FR-GJI-01 … 08), komponen per peran diatur di Pengaturan → Gaji & bagi hasil.
 *
 * Dokter  = gaji pokok + UD (jam hadir × tarif/jam) + fee − potongan lab + bonus/THR − potongan.
 *           Fee per tindakan = (harga − biaya modal × qty) × %fee, dari tagihan LUNAS dalam periode.
 *           Prioritas %fee: khusus dokter × tindakan → tindakan → dokter → global.
 *           Tindakan bertahap (gigi palsu, behel): bila tindakan punya "dasar ikut tindakan" (fee_from_procedure_id),
 *           pembayaran tindakan tahap awal pasien yang sama sejak tahap akhir sebelumnya ikut dasar fee (DP cetak → insersi).
 *           Potongan lab = biaya lab × %fee. Bila ada tindakan bertanda "potong lab" (fee_minus_lab), lab pasien dipotong
 *           saat tindakan itu lunas (mis. insersi) memakai %fee tindakan; bila tidak ada, cara lama: order lab dokter
 *           yang dikirim dalam periode (tidak batal) × %fee dokter.
 * Asisten = gaji pokok + UD + %fee asisten × tindakan lunas pada kunjungan yang didampingi + lembur + bonus/THR − potongan.
 * Admin   = gaji pokok + lembur + bonus/THR − potongan (semua jabatan selain asisten).
 */
class Payroll
{
    public const ROLE_ASSISTANT = 'asisten';

    public const ROLE_ADMIN = 'admin';

    /** Peran gaji karyawan berdasarkan jabatannya. */
    public static function employeeRole(Employee $e): string
    {
        return $e->position === 'Asisten dokter' ? self::ROLE_ASSISTANT : self::ROLE_ADMIN;
    }

    /**
     * @return Collection<int, array{doctor:Doctor, gross:int, cost:int, lab:int, lab_deduction:int, share:int, base:int,
     *     hours:float, ud:int, bonus:int, deduction:int, total:int, count:int,
     *     branches:array<int,array{gross:int,share:int}>, procedures:Collection, lines:Collection}>
     */
    public static function forMonth(int $clinicId, CarbonInterface $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $global = Settings::globalShare();
        $hourly = Settings::int('doctor_hourly_rate');

        $items = InvoiceItem::query()
            ->whereNotNull('doctor_id')
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_PAID)->whereBetween('paid_at', [$start, $end]))
            ->with(['invoice:id,visit_id,branch_id,paid_at,patient_id', 'invoice.visit:id,patient_id', 'procedure:id,share_percent,cost_amount,fee_from_procedure_id,fee_minus_lab'])
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

        // Tarif khusus dokter × tindakan, kunci "doctorId:procedureId".
        $special = DoctorProcedureShare::query()
            ->whereHas('doctor', fn ($q) => $q->where('clinic_id', $clinicId))
            ->get()
            ->mapWithKeys(fn (DoctorProcedureShare $s) => [$s->doctor_id.':'.$s->procedure_id => (float) $s->share_percent]);

        return Doctor::query()
            ->where('clinic_id', $clinicId)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $items->keys()))
            ->orderBy('name')
            ->get()
            ->map(function (Doctor $doctor) use ($items, $labs, $global, $hourly, $adjustments, $hours, $special, $staged) {
                $rows = ($items->get($doctor->id) ?? collect())->map(function (InvoiceItem $i) use ($doctor, $global, $special, $staged) {
                    $percent = (float) ($special[$doctor->id.':'.$i->procedure_id] ?? $i->procedure?->share_percent ?? $doctor->share_percent ?? $global);
                    $cost = min((int) $i->amount, (int) ($i->procedure?->cost_amount ?? 0) * (int) $i->quantity);
                    [$carry, $lab] = static::stageAmounts($i, $staged);
                    $share = (int) round(((int) $i->amount + $carry - $cost) * $percent / 100);

                    return [
                        'name' => $i->name,
                        'branch_id' => $i->invoice->branch_id,
                        'paid_at' => $i->invoice->paid_at,
                        'quantity' => (int) $i->quantity,
                        'gross' => (int) $i->amount,
                        'cost' => $cost,
                        'carry' => $carry,
                        'lab' => $lab,
                        'percent' => $percent,
                        'share' => $share,
                        // Potongan lab kasus ini (tidak melebihi fee barisnya).
                        'lab_cut' => min($share, (int) round($lab * $percent / 100)),
                    ];
                });

                // Potongan lab: saat tindakan "potong lab" lunas (cabang tindakan itu), atau cara lama
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
                $ud = (int) round($workHours * $hourly);
                $adj = $adjustments->get($doctor->id, collect());
                [$bonus, $deduction] = static::sumAdjustments($adj);

                return [
                    'doctor' => $doctor,
                    'gross' => (int) $rows->sum('gross'),
                    'cost' => (int) $rows->sum('cost'),
                    'lab' => $staged['lab_at_procedure'] ? (int) $rows->sum('lab') : (int) $labRows->sum('cost'),
                    'carry' => (int) $rows->sum('carry'),
                    'lab_deduction' => $labDeduction,
                    'share' => $share,
                    'base' => $base,
                    'hours' => $workHours,
                    'ud' => $ud,
                    'bonus' => $bonus,
                    'deduction' => $deduction,
                    'adjustments' => $adj->values(),
                    'total' => max(0, $share + $base + $ud + $bonus - $deduction),
                    'count' => $rows->count(),
                    'branches' => $branches,
                    'procedures' => $rows->groupBy('name')->map(fn ($g, $name) => [
                        'name' => $name, 'count' => $g->count(), 'gross' => $g->sum('gross'), 'cost' => $g->sum('cost'), 'carry' => $g->sum('carry'),
                        'percent' => $g->first()['percent'], 'share' => $g->sum('share'),
                    ])->sortByDesc('gross')->values(),
                    // Rincian per tanggal & cabang untuk slip (FR-GJI-03).
                    'lines' => $rows->sortBy('paid_at')->values(),
                ];
            });
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
            ],
            self::ROLE_ADMIN => ['hourly' => 0, 'overtime' => Settings::int('admin_overtime_rate'), 'percent' => 0.0],
        ];

        // Tindakan lunas pada kunjungan yang didampingi, per asisten per cabang.
        $assisted = InvoiceItem::query()
            ->whereNotNull('procedure_id')
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_PAID)
                ->whereBetween('paid_at', [$start, $end])
                ->whereHas('visit', fn ($v) => $v->whereNotNull('assistant_id')))
            ->with('invoice:id,visit_id,branch_id', 'invoice.visit:id,assistant_id')
            ->get(['id', 'invoice_id', 'amount'])
            ->groupBy(fn (InvoiceItem $i) => $i->invoice->visit->assistant_id);

        return Employee::query()
            ->where('clinic_id', $clinicId)
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', $adjustments->keys())
                ->orWhereIn('id', $assisted->keys()))
            ->with('primaryBranch')
            ->orderBy('name')
            ->get()
            ->map(function (Employee $e) use ($adjustments, $hours, $rates, $assisted) {
                $role = static::employeeRole($e);
                $rate = $rates[$role];
                $h = $hours->get($e->id);
                $workHours = $role === self::ROLE_ASSISTANT ? (float) ($h?->work_hours ?? 0) : 0.0;
                $overtimeHours = (float) ($h?->overtime_hours ?? 0);

                $items = $role === self::ROLE_ASSISTANT ? ($assisted->get($e->id) ?? collect()) : collect();
                $assistantBranches = $items->groupBy(fn ($i) => $i->invoice->branch_id)
                    ->map(fn ($g) => (int) round($g->sum('amount') * $rate['percent'] / 100))
                    ->all();

                $adj = $adjustments->get($e->id, collect());
                [$bonus, $deduction] = static::sumAdjustments($adj);
                $base = (int) $e->base_salary;
                $ud = (int) round($workHours * $rate['hourly']);
                $overtime = (int) round($overtimeHours * $rate['overtime']);
                $assistantShare = (int) array_sum($assistantBranches);

                return [
                    'employee' => $e,
                    'role' => $role,
                    'base' => $base,
                    'hours' => $workHours,
                    'ud' => $ud,
                    'overtime_hours' => $overtimeHours,
                    'overtime' => $overtime,
                    'assisted' => (int) $items->sum('amount'),
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
     * Data pendukung tindakan bertahap: riwayat tindakan lunas & order lab pasien yang relevan.
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
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_PAID)->where('paid_at', '<=', $end)
                ->where(fn ($q) => $q->whereIn('patient_id', $patientIds)->orWhereHas('visit', fn ($v) => $v->whereIn('patient_id', $patientIds))))
            ->with(['invoice:id,visit_id,patient_id,paid_at', 'invoice.visit:id,patient_id'])
            ->get(['id', 'invoice_id', 'procedure_id', 'amount'])
            ->map(fn (InvoiceItem $h) => [
                'id' => $h->id, 'procedure_id' => (int) $h->procedure_id, 'patient_id' => static::patientOf($h),
                'at' => $h->invoice->paid_at->getTimestamp(), 'amount' => (int) $h->amount,
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
     * [pembayaran tahap awal yang ikut dasar fee, biaya lab yang dipotong] untuk satu baris tindakan.
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

        $at = $i->invoice->paid_at->getTimestamp();
        $mine = $staged['history']->where('patient_id', $patientId);
        // Baris sebelum baris ini (urut waktu lunas, lalu id) di antara tindakan tertentu.
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
            $today = $i->invoice->paid_at->toDateString();
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
