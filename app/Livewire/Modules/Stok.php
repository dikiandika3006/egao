<?php

namespace App\Livewire\Modules;

use App\Exports\ArrayExport;
use App\Livewire\Concerns\ModulePage;
use App\Livewire\Concerns\PicksBranch;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Report;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Stok obat, bahan & alat (permintaan klien poin 5 & 8).
 * Tab: Barang · Pembelian · Pemakaian & opname · Mutasi · Laporan jual-beli.
 */
class Stok extends Component
{
    use ModulePage, PicksBranch;

    public const MENU = 'stok';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['setTab', 'export', 'cancelPurchaseEdit', 'closeCancelPurchase'];

    /** Aksi master barang mengikuti izin menu "Master barang". */
    private const ITEM_ACTIONS = ['newItem', 'editItem', 'saveItem'];

    protected function menuKey(?string $method = null): string
    {
        if ($method !== null) {
            return in_array($method, self::ITEM_ACTIONS, true) ? 'master-barang' : 'stok';
        }

        return $this->tab === 'barang' ? 'master-barang' : 'stok';
    }

    /** Tab barang ikut izin "Master barang", tab lain ikut izin "Stok obat & bahan". */
    private function tabs(): array
    {
        return array_filter(
            self::TABS,
            fn ($k) => Access::can($k === 'barang' ? 'master-barang' : 'stok', 'lihat'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public const TABS = ['barang' => 'Barang', 'pembelian' => 'Pembelian', 'pakai' => 'Pemakaian & opname', 'mutasi' => 'Mutasi stok', 'laporan' => 'Laporan jual-beli'];

    #[Url(except: 'barang')]
    public string $tab = 'barang';

    #[Url(as: 'kategori', except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    // Form barang
    public bool $showItemForm = false;

    public ?int $editingId = null;

    public array $itemForm = [];

    // Form pembelian
    public array $purchase = [];

    /** Pembelian yang sedang diubah (null = catat baru). */
    public ?int $editingPurchaseId = null;

    /** Pembelian yang akan dibatalkan + alasannya. */
    public ?int $cancellingPurchaseId = null;

    public string $cancelReason = '';

    // Form pemakaian / opname
    public array $usage = ['item_id' => '', 'mode' => 'pakai', 'quantity' => '', 'note' => ''];

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
        $this->resetItem();
        $this->resetPurchase();
    }

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, $this->tabs()), 404);
        $this->tab = $tab;
        $this->resetErrorBag();
    }

    private function items()
    {
        return Item::where('clinic_id', $this->clinicId());
    }

    // ── Barang ──────────────────────────────────────────────────────

    private function resetItem(): void
    {
        $this->itemForm = ['code' => '', 'name' => '', 'category' => 'obat', 'unit' => 'tablet', 'buy_price' => null, 'sell_price' => null, 'sellable' => true, 'min_stock' => 0, 'is_active' => true];
        $this->editingId = null;
    }

    public function newItem(): void
    {
        $this->resetItem();
        $this->resetErrorBag();
        $this->showItemForm = true;
    }

    public function editItem(int $id): void
    {
        $i = $this->items()->findOrFail($id);
        $this->editingId = $i->id;
        $this->itemForm = [
            'code' => $i->code, 'name' => $i->name, 'category' => $i->category, 'unit' => $i->unit,
            'buy_price' => (int) $i->buy_price, 'sell_price' => $i->sell_price !== null ? (int) $i->sell_price : null,
            'sellable' => $i->sell_price !== null, 'min_stock' => $i->min_stock, 'is_active' => $i->is_active,
        ];
        $this->resetErrorBag();
        $this->showItemForm = true;
    }

    public function saveItem(): void
    {
        $data = $this->validate([
            'itemForm.code' => ['required', 'string', 'max:20', Rule::unique('items', 'code')->where('clinic_id', $this->clinicId())->ignore($this->editingId)],
            'itemForm.name' => ['required', 'string', 'max:255'],
            'itemForm.category' => ['required', Rule::in(array_keys(Item::CATEGORIES))],
            'itemForm.unit' => ['required', 'string', 'max:20'],
            'itemForm.buy_price' => ['nullable', 'integer', 'min:0'],
            'itemForm.sellable' => ['boolean'],
            'itemForm.sell_price' => [Rule::requiredIf((bool) $this->itemForm['sellable']), 'nullable', 'integer', 'min:0'],
            'itemForm.min_stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'itemForm.is_active' => ['boolean'],
        ], attributes: [
            'itemForm.code' => 'kode', 'itemForm.name' => 'nama', 'itemForm.unit' => 'satuan', 'itemForm.buy_price' => 'harga beli',
            'itemForm.sell_price' => 'harga jual', 'itemForm.min_stock' => 'stok minimum',
        ])['itemForm'];

        $item = $this->editingId ? $this->items()->findOrFail($this->editingId) : new Item(['clinic_id' => $this->clinicId()]);
        $item->fill([
            'code' => strtoupper($data['code']), 'name' => $data['name'], 'category' => $data['category'], 'unit' => $data['unit'],
            'buy_price' => (int) ($data['buy_price'] ?? 0), 'sell_price' => $data['sellable'] ? (int) $data['sell_price'] : null,
            'min_stock' => (int) $data['min_stock'], 'is_active' => (bool) $data['is_active'],
        ])->save();

        AuditLog::record($this->editingId ? 'stok.barang.updated' : 'stok.barang.created', $item, $item->only(['code', 'sell_price', 'buy_price']));
        $this->showItemForm = false;
        $this->toast('Barang tersimpan.');
    }

    // ── Pembelian ───────────────────────────────────────────────────

    /** Pembelian di cabang yang boleh diakses. */
    private function purchaseQuery(BranchContext $context)
    {
        $branchIds = $context->id() ? [$context->id()] : $context->options()->pluck('id')->all();

        return Purchase::whereIn('branch_id', $branchIds);
    }

    public function editPurchase(int $id, BranchContext $context): void
    {
        $p = $this->purchaseQuery($context)->with('items')->findOrFail($id);
        $this->resetValidation();
        $this->editingPurchaseId = $p->id;
        $this->branchPick = (string) $p->branch_id;
        $this->purchase = [
            'date' => $p->date->toDateString(),
            'supplier' => (string) $p->supplier,
            'reference' => (string) $p->reference,
            'lines' => $p->items->map(fn ($l) => ['item_id' => (string) $l->item_id, 'quantity' => (int) $l->quantity, 'unit_cost' => (int) $l->unit_cost])->values()->all(),
        ];
    }

    public function cancelPurchaseEdit(): void
    {
        $this->resetValidation();
        $this->resetPurchase();
    }

    public function askCancelPurchase(int $id, BranchContext $context): void
    {
        $this->cancellingPurchaseId = $this->purchaseQuery($context)->findOrFail($id)->id;
        $this->cancelReason = '';
        $this->resetValidation();
    }

    public function closeCancelPurchase(): void
    {
        $this->cancellingPurchaseId = null;
        $this->resetValidation();
    }

    public function confirmCancelPurchase(BranchContext $context, StockService $stock): void
    {
        $this->validate(['cancelReason' => ['required', 'string', 'min:5', 'max:255']], attributes: ['cancelReason' => 'alasan']);
        $p = $this->purchaseQuery($context)->findOrFail($this->cancellingPurchaseId);

        $stock->cancelPurchase($p, $this->cancelReason);

        if ($this->editingPurchaseId === $p->id) {
            $this->resetPurchase();
        }
        $this->cancellingPurchaseId = null;
        $this->toast('Pembelian dibatalkan: stok dikurangi dan pengeluarannya dihapus.');
    }

    private function resetPurchase(): void
    {
        $this->editingPurchaseId = null;
        $this->purchase =['date' => today()->toDateString(), 'supplier' => '', 'reference' => '', 'lines' => [['item_id' => '', 'quantity' => 1, 'unit_cost' => null]]];
    }

    public function addLine(): void
    {
        $this->purchase['lines'][] = ['item_id' => '', 'quantity' => 1, 'unit_cost' => null];
    }

    public function removeLine(int $i): void
    {
        unset($this->purchase['lines'][$i]);
        $this->purchase['lines'] = array_values($this->purchase['lines']) ?: [['item_id' => '', 'quantity' => 1, 'unit_cost' => null]];
    }

    /** Pilih barang → harga beli terakhir terisi otomatis. */
    public function updatedPurchase($value, string $key): void
    {
        if (preg_match('/^lines\.(\d+)\.item_id$/', $key, $m) && filled($value)) {
            $last = $this->items()->whereKey((int) $value)->value('buy_price');
            if ($last && blank($this->purchase['lines'][$m[1]]['unit_cost'] ?? null)) {
                $this->purchase['lines'][$m[1]]['unit_cost'] = (int) $last;
            }
        }
    }

    public function savePurchase(BranchContext $context, StockService $stock): void
    {
        $branchId = $this->targetBranch($context);

        $data = $this->validate([
            'purchase.date' => ['required', 'date', 'before_or_equal:today'],
            'purchase.supplier' => ['nullable', 'string', 'max:255'],
            'purchase.reference' => ['nullable', 'string', 'max:50'],
            'purchase.lines' => ['required', 'array', 'min:1'],
            'purchase.lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('clinic_id', $this->clinicId())],
            'purchase.lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'purchase.lines.*.unit_cost' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ], attributes: [
            'purchase.date' => 'tanggal', 'purchase.lines.*.item_id' => 'barang', 'purchase.lines.*.quantity' => 'jumlah', 'purchase.lines.*.unit_cost' => 'harga beli',
        ])['purchase'];

        if ($this->editingPurchaseId) {
            $p = $stock->updatePurchase(
                $this->purchaseQuery($context)->findOrFail($this->editingPurchaseId),
                $branchId, Carbon::parse($data['date']), $data['supplier'] ?: null, $data['reference'] ?: null, $data['lines'],
            );
            $this->resetPurchase();
            $this->toast('Pembelian diperbarui: stok dan pengeluaran '.\App\Support\Format::rupiah($p->total).' ikut disesuaikan.');

            return;
        }

        $p = $stock->purchase($this->clinicId(), $branchId, Carbon::parse($data['date']), $data['supplier'] ?: null, $data['reference'] ?: null, $data['lines']);

        $this->resetPurchase();
        $this->toast('Pembelian tersimpan: stok bertambah dan pengeluaran '.\App\Support\Format::rupiah($p->total).' tercatat.');
    }

    // ── Pemakaian & opname ──────────────────────────────────────────

    public function saveUsage(BranchContext $context, StockService $stock): void
    {
        $branchId = $this->targetBranch($context);

        $data = $this->validate([
            'usage.item_id' => ['required', Rule::exists('items', 'id')->where('clinic_id', $this->clinicId())],
            'usage.mode' => ['required', Rule::in(['pakai', 'opname'])],
            'usage.quantity' => ['required', 'integer', 'min:'.($this->usage['mode'] === 'opname' ? 0 : 1), 'max:100000'],
            'usage.note' => [Rule::requiredIf($this->usage['mode'] === 'opname'), 'nullable', 'string', 'max:255'],
        ], attributes: ['usage.item_id' => 'barang', 'usage.quantity' => 'jumlah', 'usage.note' => 'keterangan'])['usage'];

        $current = $stock->available((int) $data['item_id'], $branchId);
        $qty = (int) $data['quantity'];

        if ($data['mode'] === 'pakai') {
            $stock->move((int) $data['item_id'], $branchId, -$qty, 'pakai', ['note' => $data['note'] ?: 'Pemakaian klinik']);
            $msg = "Pemakaian {$qty} dicatat.";
        } else {
            $delta = $qty - $current;
            if ($delta === 0) {
                $this->toast('Stok fisik sama dengan sistem, tidak ada perubahan.');

                return;
            }
            $stock->move((int) $data['item_id'], $branchId, $delta, 'penyesuaian', ['note' => 'Opname: '.$data['note']], allowNegative: true);
            $msg = 'Stok disesuaikan '.($delta > 0 ? '+' : '').$delta.'.';
        }

        AuditLog::record('stok.'.$data['mode'], null, ['item_id' => (int) $data['item_id'], 'qty' => $qty, 'before' => $current]);
        $this->usage = ['item_id' => '', 'mode' => $data['mode'], 'quantity' => '', 'note' => ''];
        $this->toast($msg);
    }

    // ── Laporan jual-beli ───────────────────────────────────────────

    /** Per barang: beli (qty & nilai), jual (qty, omzet, HPP, laba kotor), pakai. */
    private function report(BranchContext $context): array
    {
        [$from, $to] = Report::range($this->from, $this->to);
        $branchIds = $context->id() ? [$context->id()] : $context->options()->pluck('id')->all();

        $moves = StockMovement::whereIn('branch_id', $branchIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereHas('item', fn ($q) => $q->where('clinic_id', $this->clinicId()))
            ->with('item')->get();

        $rows = $moves->groupBy('item_id')->map(function (Collection $g) {
            $sold = $g->whereIn('type', ['jual', 'batal_jual']);
            $soldQty = -$sold->sum('quantity');
            $revenue = (int) $sold->sum(fn ($m) => -$m->quantity * (int) $m->unit_price);
            $cogs = (int) $sold->sum(fn ($m) => -$m->quantity * (int) $m->unit_cost);
            $in = $g->where('type', 'masuk');
            $used = $g->where('type', 'pakai');

            return [
                'item' => $g->first()->item,
                'buy_qty' => (int) $in->sum('quantity'),
                'buy_value' => (int) $in->sum(fn ($m) => $m->quantity * $m->unit_cost),
                'sell_qty' => (int) $soldQty,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'profit' => $revenue - $cogs,
                'use_qty' => (int) -$used->sum('quantity'),
                'use_value' => (int) $used->sum(fn ($m) => -$m->quantity * $m->unit_cost),
            ];
        })->sortBy(fn ($r) => $r['item']->name)->values();

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => [
            'buy_value' => $rows->sum('buy_value'), 'revenue' => $rows->sum('revenue'), 'cogs' => $rows->sum('cogs'),
            'profit' => $rows->sum('profit'), 'use_value' => $rows->sum('use_value'),
        ]];
    }

    public function export(BranchContext $context)
    {
        abort_unless(Access::can('stok', 'lihat') && Access::can('stok', 'keuangan'), 403);
        $r = $this->report($context);
        $rows = $r['rows']->map(fn ($x) => [
            $x['item']->code, $x['item']->name, Item::CATEGORIES[$x['item']->category] ?? $x['item']->category, $x['item']->unit,
            $x['buy_qty'], $x['buy_value'], $x['sell_qty'], $x['revenue'], $x['cogs'], $x['profit'], $x['use_qty'], $x['use_value'],
        ])->all();
        $rows[] = ['TOTAL', '', '', '', '', $r['totals']['buy_value'], '', $r['totals']['revenue'], $r['totals']['cogs'], $r['totals']['profit'], '', $r['totals']['use_value']];

        return Excel::download(
            new ArrayExport(['Kode', 'Barang', 'Kategori', 'Satuan', 'Beli (qty)', 'Beli (Rp)', 'Jual (qty)', 'Penjualan (Rp)', 'HPP (Rp)', 'Laba kotor (Rp)', 'Pakai (qty)', 'Pakai (Rp)'], $rows, 'Jual-beli'),
            sprintf('laporan-stok-%s-%s_%s.xlsx', str($context->label())->slug(), $r['from']->format('Ymd'), $r['to']->format('Ymd')),
        );
    }

    public function render(BranchContext $context)
    {
        $tabs = $this->tabs();
        if (! array_key_exists($this->tab, $tabs)) {
            $this->tab = (string) array_key_first($tabs);
        }

        $branchIds = $context->id() ? [$context->id()] : $context->options()->pluck('id')->all();
        $branches = $context->options()->whereIn('id', $branchIds)->keyBy('id');

        $items = $this->items()
            ->when($this->category, fn ($q) => $q->where('category', $this->category))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('code', 'like', trim($this->search).'%')))
            ->with(['stocks' => fn ($q) => $q->whereIn('branch_id', $branchIds)])
            ->orderByDesc('is_active')->orderBy('category')->orderBy('name')
            ->get();

        $allItems = $this->items()->where('is_active', true)->with(['stocks' => fn ($q) => $q->whereIn('branch_id', $branchIds)])->orderBy('name')->get();
        $low = $allItems->filter(fn (Item $i) => $i->min_stock > 0 && $branches->contains(fn ($b) => $i->stockAt($b->id) <= $i->min_stock));

        $data = [
            'context' => $context,
            'branches' => $branches,
            'items' => $items,
            'allItems' => $allItems,
            'lowCount' => $low->count(),
            'stockValue' => (int) $allItems->sum(fn (Item $i) => $i->stocks->sum(fn ($s) => max(0, $s->quantity) * $s->avg_cost)),
            'tabs' => $tabs,
        ];

        if ($this->tab === 'pembelian') {
            $data['purchases'] = Purchase::whereIn('branch_id', $branchIds)->with(['items.item', 'branch'])->latest('date')->latest('id')->limit(30)->get();
            $data['purchaseTotal'] = (int) collect($this->purchase['lines'])->sum(fn ($l) => (int) ($l['quantity'] ?? 0) * (int) ($l['unit_cost'] ?? 0));
        }

        if ($this->tab === 'mutasi') {
            [$from, $to] = Report::range($this->from, $this->to);
            $data['movements'] = StockMovement::whereIn('branch_id', $branchIds)->whereBetween('occurred_at', [$from, $to])
                ->whereHas('item', fn ($q) => $q->where('clinic_id', $this->clinicId())
                    ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')))
                ->with(['item', 'branch', 'user'])->latest('occurred_at')->latest('id')->limit(300)->get();
        }

        if ($this->tab === 'laporan') {
            $data['r'] = $this->report($context);
        }

        if ($this->tab === 'pakai' && filled($this->usage['item_id']) && $this->formBranchId($context)) {
            $data['currentStock'] = app(StockService::class)->available((int) $this->usage['item_id'], $this->formBranchId($context));
        }

        return $this->page('stok', 'livewire.modules.stok', $data);
    }
}
