<?php

namespace App\Livewire\Modules;

use App\Exports\ArrayExport;
use App\Livewire\Concerns\ModulePage;
use App\Models\ExpenseCategory;
use App\Support\BranchContext;
use App\Support\Report;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan (FR-LAP-01 … 10): ringkasan kas, rincian pembayaran, dan pengeluaran per kategori.
 * Angka dihitung di App\Support\Report agar sama dengan ekspor Excel & PDF.
 */
class Laporan extends Component
{
    use ModulePage;

    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    #[Url(except: 'ringkasan')]
    public string $tab = 'ringkasan';

    /** Filter kategori di tab Pengeluaran (mis. Listrik dan air, Lab gigi). */
    #[Url(as: 'kategori', except: null)]
    public ?int $category = null;

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function preset(string $key): void
    {
        [$from, $to] = match ($key) {
            'hari' => [today(), today()],
            'minggu' => [today()->subDays(6), today()],
            'bulan-lalu' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'tahun' => [now()->startOfYear(), today()],
            default => [now()->startOfMonth(), today()],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, ['ringkasan', 'pembayaran', 'pengeluaran'], true), 404);
        $this->tab = $tab;
    }

    public function filterCategory(?int $id): void
    {
        $this->category = $id ?: null;
        $this->tab = 'pengeluaran';
    }

    private function report(BranchContext $context): array
    {
        [$from, $to] = Report::range($this->from, $this->to);
        $branches = $context->options()
            ->when($context->id(), fn ($c) => $c->where('id', $context->id()))
            ->keyBy('id');

        return Report::build($branches, $from, $to, $this->category);
    }

    /** FR-LAP-06: ekspor Excel sesuai tab yang dibuka. */
    public function export(BranchContext $context)
    {
        $r = $this->report($context);
        $period = $r['from']->format('Ymd').'_'.$r['to']->format('Ymd');
        $slug = str($context->label())->slug();

        if ($this->tab === 'pembayaran') {
            $rows = $r['payments']->map(fn ($p) => [
                $p['paid_at']->format('Y-m-d H:i'), $r['branches']->get($p['branch_id'])?->shortName(),
                $p['patient']?->rmCode(), $p['buyer'], $p['doctor'], $p['receipt'], $p['method_label'], $p['amount'], $p['cashier'],
            ])->all();
            $rows[] = ['TOTAL', '', '', '', '', '', '', $r['in'], ''];

            return Excel::download(new ArrayExport(['Waktu', 'Cabang', 'No. RM', 'Pasien', 'Dokter', 'Kwitansi', 'Metode', 'Jumlah', 'Kasir'], $rows, 'Pembayaran'), "laporan-pembayaran-{$slug}-{$period}.xlsx");
        }

        if ($this->tab === 'pengeluaran') {
            $rows = $r['expenses']->map(fn ($e) => [
                $e->date->format('Y-m-d'), $r['branches']->get($e->branch_id)?->shortName(), $e->category->name, $e->description, (int) $e->amount,
            ])->all();
            $rows[] = ['TOTAL', '', '', '', (int) $r['expenses']->sum('amount')];
            $cat = $r['category'] ? '-'.str($r['category']->name)->slug() : '';

            return Excel::download(new ArrayExport(['Tanggal', 'Cabang', 'Kategori', 'Keterangan', 'Jumlah'], $rows, 'Pengeluaran'), "laporan-pengeluaran{$cat}-{$slug}-{$period}.xlsx");
        }

        $rows = $r['daily']->reverse()->map(fn ($d) => [$d['date']->format('Y-m-d'), $d['in'], $d['out'], $d['net']])->values()->all();
        $rows[] = ['TOTAL', $r['in'], $r['out'], $r['net']];

        return Excel::download(new ArrayExport(['Tanggal', 'Pemasukan', 'Pengeluaran', 'Selisih'], $rows, 'Harian'), "laporan-{$slug}-{$period}.xlsx");
    }

    public function render(BranchContext $context)
    {
        $report = $this->report($context);

        return $this->page('laporan', 'livewire.modules.laporan', [
            'context' => $context,
            'r' => $report,
            'maxDaily' => max(1, $report['daily']->max('in') ?? 0, $report['daily']->max('out') ?? 0),
            'categoryOptions' => ExpenseCategory::where('clinic_id', $this->clinicId())->orderBy('name')->get(),
            'pdfUrl' => route('laporan.pdf', array_filter([
                'dari' => $report['from']->toDateString(), 'sampai' => $report['to']->toDateString(), 'kategori' => $this->category,
            ])),
        ]);
    }
}
