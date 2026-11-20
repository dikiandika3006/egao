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
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Perhitungan gaji & bagi hasil (FR-GJI-01 … 08), komponen per peran diatur di Pengaturan → Gaji & bagi hasil.
 *
 * Dokter  = gaji pokok + UD (jam hadir × tarif/jam) + fee − potongan lab + bonus/THR − potongan.
 *           Fee per tindakan = (harga − biaya modal × qty) × %fee, dari tagihan LUNAS dalam periode.
 *           Prioritas %fee: khusus dokter × tindakan → tindakan → dokter → global.
 *           Potongan lab = biaya order lab dokter (dikirim dalam periode, tidak batal) × %fee dokter.
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
            ->with(['invoice:id,branch_id,paid_at', 'procedure:id,share_percent,cost_amount'])
            ->get()
            ->groupBy('doctor_id');

        $labs = LabOrder::query()
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
            ->map(function (Doctor $doctor) use ($items, $labs, $global, $hourly, $adjustments, $hours, $special) {
                $rows = ($items->get($doctor->id) ?? collect())->map(function (InvoiceItem $i) use ($doctor, $global, $special) {
                    $percent = (float) ($special[$doctor->id.':'.$i->procedure_id] ?? $i->procedure?->share_percent ?? $doctor->share_percent ?? $global);
                    $cost = min((int) $i->amount, (int) ($i->procedure?->cost_amount ?? 0) * (int) $i->quantity);

                    return [
                        'name' => $i->name,
                        'branch_id' => $i->invoice->branch_id,
                        'paid_at' => $i->invoice->paid_at,
                        'quantity' => (int) $i->quantity,
                        'gross' => (int) $i->amount,
                        'cost' => $cost,
                        'percent' => $percent,
                        'share' => (int) round(($i->amount - $cost) * $percent / 100),
                    ];
                });

                // Potongan lab memakai %fee dokter (atau global), dibebankan ke cabang order lab.
                $doctorPercent = (float) ($doctor->share_percent ?? $global);
                $labRows = $labs->get($doctor->id) ?? collect();
                $labByBranch = $labRows->groupBy('branch_id')
                    ->map(fn ($g) => (int) round($g->sum('cost') * $doctorPercent / 100));

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
                    'lab' => (int) $labRows->sum('cost'),
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
                        'name' => $name, 'count' => $g->count(), 'gross' => $g->sum('gross'), 'cost' => $g->sum('cost'),
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
