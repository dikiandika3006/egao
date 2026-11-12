<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Employee;
use App\Support\BranchContext;
use App\Support\Payroll;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Slip gaji PDF (FR-GJI-03, FR-GJI-05). Owner: semua slip. Dokter/karyawan: slip miliknya sendiri.
 */
class PayslipController extends Controller
{
    public function __invoke(Request $request, BranchContext $context, string $type, int $id)
    {
        $user = $request->user();
        $clinicId = (int) $user->clinic_id;
        $month = rescue(fn () => Carbon::createFromFormat('Y-m', (string) $request->query('bulan', now()->format('Y-m')))->startOfMonth(), now()->startOfMonth(), false);

        $payee = match ($type) {
            'doctor' => Doctor::where('clinic_id', $clinicId)->findOrFail($id),
            'employee' => Employee::where('clinic_id', $clinicId)->findOrFail($id),
            default => abort(404),
        };

        $isOwner = $context->is(Role::Owner);
        abort_unless($isOwner || ($payee->user_id && $payee->user_id === $user->id), 403, 'Slip ini hanya dapat dibuka pemiliknya atau Owner.');

        $row = $type === 'doctor'
            ? Payroll::forMonth($clinicId, $month)->first(fn ($r) => $r['doctor']->id === $payee->id)
            : Payroll::employeesForMonth($clinicId, $month)->first(fn ($r) => $r['employee']->id === $payee->id);

        abort_unless($row, 404, 'Belum ada data gaji untuk periode ini.');

        AuditLog::record('payroll.slip.viewed', $payee, ['month' => $month->format('Y-m')]);

        $name = sprintf('slip-gaji-%s-%s.pdf', str($payee->name)->slug(), $month->format('Y-m'));

        return Pdf::loadView('pdf.payslip', [
            'type' => $type,
            'payee' => $payee,
            'row' => $row,
            'month' => $month,
            'clinic' => $user->clinic,
            'branches' => Branch::where('clinic_id', $clinicId)->get()->keyBy('id'),
        ])->setPaper('a5')->stream($name);
    }
}
