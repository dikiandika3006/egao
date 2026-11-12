<?php

namespace App\Support;

use App\Enums\VisitStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Visit;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Data laporan keuangan (FR-LAP-01 … 10) — dipakai halaman Laporan, ekspor Excel, dan PDF
 * agar angka di semua keluaran sama.
 */
class Report
{
    /** @return array{0:Carbon,1:Carbon} rentang aman (maks. 1 tahun) */
    public static function range(?string $from, ?string $to): array
    {
        $from = rescue(fn () => Carbon::parse($from ?: now()->startOfMonth())->startOfDay(), now()->startOfMonth(), false);
        $to = rescue(fn () => Carbon::parse($to ?: today())->endOfDay(), today()->endOfDay(), false);

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to->min($from->copy()->addYear()->endOfDay())];
    }

    /**
     * @param  Collection  $branches  cabang yang dilaporkan, keyBy id
     */
    public static function build(Collection $branches, Carbon $from, Carbon $to, ?int $categoryId = null): array
    {
        $branchIds = $branches->keys()->all();

        // Rincian pembayaran per transaksi (laporan pembayaran).
        $payments = Payment::query()
            ->whereHas('invoice', fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereBetween('paid_at', [$from, $to])
            ->with(['invoice.visit.patient', 'invoice.visit.doctor', 'invoice.patient', 'invoice.receipts', 'receiver'])
            ->orderBy('paid_at')
            ->get()
            ->map(fn (Payment $p) => [
                'paid_at' => $p->paid_at,
                'branch_id' => $p->invoice->branch_id,
                'patient' => $p->invoice->payerPatient(),
                'buyer' => $p->invoice->payerName(),
                'doctor' => $p->invoice->visit?->doctor?->name ?? ($p->invoice->isDirectSale() ? 'Penjualan langsung' : null),
                'receipt' => $p->invoice->receipts->whereNull('voided_at')->last()?->code,
                'method' => $p->method,
                'method_label' => Payment::METHODS[$p->method] ?? $p->method,
                'amount' => (int) $p->amount,
                'cashier' => $p->receiver?->name,
            ]);

        $expenses = Expense::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('category')
            ->orderBy('date')
            ->get();

        $daily = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()))
            ->map(function (Carbon $d) use ($payments, $expenses) {
                $in = (int) $payments->filter(fn ($p) => $p['paid_at']->isSameDay($d))->sum('amount');
                $out = (int) $expenses->filter(fn ($e) => $e->date->isSameDay($d))->sum('amount');

                return ['date' => $d, 'in' => $in, 'out' => $out, 'net' => $in - $out];
            })
            ->reverse()->values();

        $perBranch = $branches->map(fn ($b) => [
            'branch' => $b,
            'in' => (int) $payments->where('branch_id', $b->id)->sum('amount'),
            'out' => (int) $expenses->where('branch_id', $b->id)->sum('amount'),
        ])->map(fn ($r) => $r + ['net' => $r['in'] - $r['out']])->values();

        $procedures = InvoiceItem::query()
            ->whereHas('invoice', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('status', Invoice::STATUS_PAID)->whereBetween('paid_at', [$from, $to]))
            ->whereNull('item_id')
            ->selectRaw('name, SUM(quantity) as qty, SUM(amount) as total')
            ->groupBy('name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $visits = Visit::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', VisitStatus::Selesai)
            ->count();

        $outTotal = (int) $expenses->sum('amount');

        // Pengeluaran per kategori, dengan rincian per cabang (listrik & air, lab gigi, dll).
        $categories = $expenses->groupBy('expense_category_id')
            ->map(fn (Collection $g) => [
                'id' => $g->first()->expense_category_id,
                'name' => $g->first()->category->name,
                'total' => (int) $g->sum('amount'),
                'count' => $g->count(),
                'share' => $outTotal ? round($g->sum('amount') / $outTotal * 100) : 0,
                'branches' => $g->groupBy('branch_id')->map(fn ($b) => (int) $b->sum('amount'))->all(),
            ])
            ->sortByDesc('total')->values();

        $in = (int) $payments->sum('amount');

        return [
            'from' => $from, 'to' => $to, 'branches' => $branches,
            'in' => $in, 'out' => $outTotal, 'net' => $in - $outTotal, 'visits' => $visits,
            'daily' => $daily,
            'perBranch' => $perBranch,
            'payments' => $payments,
            'methods' => $payments->groupBy('method')->map(fn (Collection $g) => [
                'label' => $g->first()['method_label'], 'total' => (int) $g->sum('amount'), 'count' => $g->count(),
            ])->sortByDesc('total')->values(),
            'categories' => $categories,
            'expenses' => $categoryId ? $expenses->where('expense_category_id', $categoryId)->values() : $expenses,
            'category' => $categoryId ? ExpenseCategory::find($categoryId) : null,
            'procedures' => $procedures,
        ];
    }
}
