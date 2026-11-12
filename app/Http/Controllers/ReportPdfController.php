<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\BranchContext;
use App\Support\Report;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * FR-LAP-06: laporan keuangan PDF — ringkasan kas, per cabang, pengeluaran per kategori, rincian pembayaran.
 * Cabang mengikuti cabang aktif pengguna (Owner "Semua cabang" = gabungan).
 */
class ReportPdfController extends Controller
{
    public function __invoke(Request $request, BranchContext $context)
    {
        [$from, $to] = Report::range($request->query('dari'), $request->query('sampai'));

        $branches = $context->options()
            ->when($context->id(), fn ($c) => $c->where('id', $context->id()))
            ->keyBy('id');

        $categoryId = $request->integer('kategori') ?: null;
        $r = Report::build($branches, $from, $to, $categoryId);

        AuditLog::record('laporan.pdf', null, ['from' => $from->toDateString(), 'to' => $to->toDateString()]);

        $name = sprintf('laporan-%s-%s_%s.pdf', str($context->label())->slug(), $from->format('Ymd'), $to->format('Ymd'));

        return Pdf::loadView('pdf.report', [
            'r' => $r,
            'label' => $context->label(),
            'clinic' => $request->user()->clinic,
        ])->setPaper('a4')->stream($name);
    }
}
