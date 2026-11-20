<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Visit;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * 01 Dashboard (FR-DSH-01 … 07).
 */
class Dashboard extends Component
{
    #[Url(as: 'tab', except: 'aktif')]
    public string $tab = 'aktif';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function callPatient(int $visitId, BranchContext $context): void
    {
        abort_unless($context->is(Role::Admin, Role::Dokter, Role::Owner), 403);

        $visit = Visit::forBranch($context->id())->findOrFail($visitId);

        if ($visit->status === VisitStatus::Menunggu) {
            $visit->update(['status' => VisitStatus::Dipanggil, 'called_at' => now()]);
        }
    }

    public function render(BranchContext $context)
    {
        $branchId = $context->id();
        $today = today();

        $todayVisits = Visit::query()
            ->forBranch($branchId)
            ->whereDate('visit_date', $today)
            ->with(['patient', 'doctor', 'branch'])
            ->orderBy('branch_id')
            ->orderBy('queue_number')
            ->get();

        $active = $todayVisits->filter(fn (Visit $v) => $v->status->isActive());
        $done = $todayVisits->where('status', VisitStatus::Selesai);

        $list = ($this->tab === 'selesai' ? $done : $active)
            ->when($this->search !== '', function (Collection $c) {
                $term = mb_strtolower($this->search);

                return $c->filter(fn (Visit $v) => str_contains(mb_strtolower($v->patient->name), $term)
                    || str_contains($v->patient->rmCode(), strtoupper($term))
                    || (string) $v->patient->medical_record_number === \App\Models\Patient::normalizeRm($term));
            });

        $paymentsToday = $this->payments($branchId)->whereDate('payments.paid_at', $today);
        $examining = $todayVisits->where('status', VisitStatus::Diperiksa);

        return view('livewire.dashboard', [
            'context' => $context,
            'greeting' => $this->greeting(),
            'branch' => $context->branch(),
            'kpi' => [
                'patients' => $todayVisits->where('status', '!=', VisitStatus::Batal)->count(),
                'done' => $done->count(),
                'waiting' => $todayVisits->whereIn('status', [VisitStatus::Menunggu, VisitStatus::Dipanggil])->count(),
                'examining' => $examining->count(),
                'examiningDoctor' => $examining->pluck('doctor.name')->unique()->implode(', ') ?: 'Belum ada',
                'revenue' => (int) (clone $paymentsToday)->sum('payments.amount'),
                'paidCount' => (clone $paymentsToday)->distinct('payments.invoice_id')->count('payments.invoice_id'),
            ],
            'visits' => $list,
            'activeCount' => $active->count(),
            'doneCount' => $done->count(),
            'onDuty' => $this->doctorsOnDuty($context),
            'showOwner' => $context->is(Role::Owner),
            'owner' => $context->is(Role::Owner) ? $this->ownerSummary($context) : null,
        ])->title('Dashboard')->layoutData(['breadcrumb' => ['Operasional', 'Dashboard'], 'active' => 'dashboard']);
    }

    private function greeting(): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };
    }

    private function payments(?int $branchId): Builder
    {
        return Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->when($branchId, fn (Builder $q) => $q->where('invoices.branch_id', $branchId));
    }

    /** FR-DSH-04: dokter yang praktik hari ini di cabang aktif (atau semua cabang). */
    private function doctorsOnDuty(BranchContext $context): Collection
    {
        $today = today();
        $branches = $context->options()->keyBy('id');

        return Doctor::query()
            ->where('clinic_id', auth()->user()->clinic_id)
            ->where('is_active', true)
            ->with(['schedules', 'overrides' => fn ($q) => $q->whereDate('date', $today)])
            ->get()
            ->map(fn (Doctor $d) => ['doctor' => $d, 'schedule' => $d->scheduleOn($today)])
            ->filter(fn ($row) => $row['schedule'] && (! $context->id() || $row['schedule']['branch_id'] === $context->id()))
            ->map(fn ($row) => $row + ['branch' => $branches->get($row['schedule']['branch_id'])])
            ->sortBy(fn ($row) => $row['schedule']['branch_id'])
            ->values();
    }

    /** FR-DSH-05/06/07: omzet 7 hari, omzet per cabang, kas hari ini. */
    private function ownerSummary(BranchContext $context): array
    {
        $today = today();
        $start = $today->copy()->subDays(6);

        $daily = $this->payments($context->id())
            ->whereBetween('payments.paid_at', [$start->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->selectRaw('DATE(payments.paid_at) as d, SUM(payments.amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $days = collect(range(6, 0))->map(function (int $back) use ($today, $daily) {
            $date = $today->copy()->subDays($back);

            return ['date' => $date, 'total' => (int) ($daily[$date->toDateString()] ?? 0), 'today' => $back === 0];
        });

        $perBranch = $context->options()
            ->when($context->id(), fn (Collection $c) => $c->where('id', $context->id()))
            ->map(fn (Branch $b) => [
                'branch' => $b,
                'today' => (int) $this->payments($b->id)->whereDate('payments.paid_at', $today)->sum('payments.amount'),
                'month' => (int) $this->payments($b->id)->whereBetween('payments.paid_at', [$today->copy()->startOfMonth(), $today->copy()->endOfDay()])->sum('payments.amount'),
            ])->values();

        $expenseToday = (int) Expense::query()
            ->when($context->id(), fn (Builder $q) => $q->where('branch_id', $context->id()), fn (Builder $q) => $q->whereIn('branch_id', $perBranch->pluck('branch.id')))
            ->whereDate('date', $today)
            ->sum('amount');

        $revenueToday = $perBranch->sum('today');

        return [
            'days' => $days,
            'max' => max(1, $days->max('total')),
            'perBranch' => $perBranch,
            'revenueToday' => $revenueToday,
            'revenueMonth' => $perBranch->sum('month'),
            'expenseToday' => $expenseToday,
            'cashToday' => $revenueToday - $expenseToday,
        ];
    }
}
