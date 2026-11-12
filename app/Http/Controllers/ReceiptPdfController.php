<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Support\BranchContext;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Kwitansi A5 (FR-CTK-01 … 03). Hanya untuk cabang yang boleh diakses pengguna.
 */
class ReceiptPdfController extends Controller
{
    public function __invoke(Receipt $receipt, BranchContext $context)
    {
        abort_unless($context->options()->contains('id', $receipt->branch_id), 403);
        abort_if($context->id() && $context->id() !== $receipt->branch_id, 403);

        $receipt->load(['branch.clinic', 'invoice.items', 'invoice.payments.receiver', 'invoice.visit.patient', 'invoice.visit.doctor', 'invoice.patient']);

        if (! $receipt->printed_at) {
            $receipt->update(['printed_at' => now()]);
        }

        return Pdf::loadView('pdf.receipt', ['receipt' => $receipt])
            ->setPaper('a5', 'portrait')
            ->stream(str_replace('/', '-', $receipt->code).'.pdf');
    }
}
