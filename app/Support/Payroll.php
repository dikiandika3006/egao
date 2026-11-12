<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\DoctorProcedureShare;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Perhitungan gaji & bagi hasil dokter (FR-GJI-01 … 08).
 *
 * Bagi hasil dihitung dari tindakan kotor (sebelum diskon) pada tagihan LUNAS dalam periode.
 * Prioritas tarif: khusus dokter × tindakan → tarif tindakan → tarif dokter → tarif global (Pengaturan → Bagi hasil).
 */
class Payroll
{
    /**
     * @return Collection<int, array{doctor:Doctor, gross:int, share:int, base:int, total:int, count:int,
     *     branches:array<int,array{gross:int,share:int}>, procedures:Collection}>
     */
    public static function forMonth(int $clinicId, CarbonInterface $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $global = Settings::globalShare();

        $items = InvoiceItem::query()
            ->whereNotNull('doctor_id')
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_PAID)->whereBetween('paid_at', [$start, $end]))
            ->with(['invoice:id,branch_id,paid_at', 'procedure:id,share_percent'])
            ->get()
            ->groupBy('doctor_id');

        $adjustments = static::adjustments($clinicId, $start, PayrollAdjustment::PAYEE_DOCTOR);

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
            ->map(function (Doctor $doctor) use ($items, $global, $adjustments, $special) {
                $rows = ($items->get($doctor->id) ?? collect())->map(function (InvoiceItem $i) use ($doctor, $global, $special) {
                    $percent = (float) ($special[$doctor->id.':'.$i->procedure_id] ?? $i->procedure?->share_percent ?? $doctor->share_percent ?? $global);

                    return [
                        'name' => $i->name,
                        'branch_id' => $i->invoice->branch_id,
                        'paid_at' => $i->invoice->paid_at,
                        'quantity' => (int) $i->quantity,
                        'gross' => (int) $i->amount,
                        'percent' => $percent,
                        'share' => (int) round($i->amount * $percent / 100),
                    ];
                });

                $branches = $rows->groupBy('branch_id')
                    ->map(fn ($g) => ['gross' => $g->sum('gross'), 'share' => $g->sum('share')])
                    ->all();

                $share = (int) $rows->sum('share');
                $base = (int) $doctor->base_salary;
                $adj = $adjustments->get($doctor->id, collect());
                $bonus = (int) $adj->where('type', PayrollAdjustment::TYPE_BONUS)->sum('amount');
                $deduction = (int) $adj->where('type', PayrollAdjustment::TYPE_DEDUCTION)->sum('amount');

                return [
                    'doctor' => $doctor,
                    'gross' => (int) $rows->sum('gross'),
                    'share' => $share,
                    'base' => $base,
                    'bonus' => $bonus,
                    'deduction' => $deduction,
                    'adjustments' => $adj->values(),
                    'total' => max(0, $share + $base + $bonus - $deduction),
                    'count' => $rows->count(),
                    'branches' => $branches,
                    'procedures' => $rows->groupBy('name')->map(fn ($g, $name) => [
                        'name' => $name, 'count' => $g->count(), 'gross' => $g->sum('gross'),
                        'percent' => $g->first()['percent'], 'share' => $g->sum('share'),
                    ])->sortByDesc('gross')->values(),
                    // Rincian per tanggal & cabang untuk slip (FR-GJI-03).
                    'lines' => $rows->sortBy('paid_at')->values(),
                ];
            });
    }

    /**
     * Gaji karyawan non-dokter (FR-GJI-05): gaji pokok + bonus − potongan.
     *
     * @return Collection<int, array{employee:Employee, base:int, bonus:int, deduction:int, total:int, adjustments:Collection}>
     */
    public static function employeesForMonth(int $clinicId, CarbonInterface $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $adjustments = static::adjustments($clinicId, $start, PayrollAdjustment::PAYEE_EMPLOYEE);

        return Employee::query()
            ->where('clinic_id', $clinicId)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $adjustments->keys()))
            ->with('primaryBranch')
            ->orderBy('name')
            ->get()
            ->map(function (Employee $e) use ($adjustments) {
                $adj = $adjustments->get($e->id, collect());
                $bonus = (int) $adj->where('type', PayrollAdjustment::TYPE_BONUS)->sum('amount');
                $deduction = (int) $adj->where('type', PayrollAdjustment::TYPE_DEDUCTION)->sum('amount');
                $base = (int) $e->base_salary;

                return [
                    'employee' => $e,
                    'base' => $base,
                    'bonus' => $bonus,
                    'deduction' => $deduction,
                    'adjustments' => $adj->values(),
                    'total' => max(0, $base + $bonus - $deduction),
                ];
            });
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
