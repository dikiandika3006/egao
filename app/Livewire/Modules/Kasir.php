<?php

namespace App\Livewire\Modules;

use App\Enums\VisitStatus;
use App\Livewire\Concerns\ModulePage;
use App\Livewire\Concerns\PicksBranch;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Receipt;
use App\Enums\Role;
use App\Support\BranchContext;
use App\Support\Format;
use App\Support\Receipts;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * 05 Kasir (FR-KSR-01 … 06, FR-CTK-01 … 03): tagihan dari tindakan dokter, diskon, pembayaran, kwitansi.
 */
class Kasir extends Component
{
    use ModulePage, PicksBranch;

    public const MENU = 'kasir';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['open', 'close'];

    #[Url(as: 'tagihan', except: null)]
    public ?int $selected = null;

    #[Url(as: 'tab', except: 'tagihan')]
    public string $tab = 'tagihan';

    public array $pay = ['discount' => 0, 'discount_reason' => '', 'method' => 'tunai', 'amount' => 0, 'cash' => null];

    public function mount(): void
    {
        if ($visitId = request()->integer('kunjungan')) {
            $invoice = $this->query()->where('visit_id', $visitId)->first();
            $invoice && $this->open($invoice->id);
        } elseif ($this->selected) {
            $this->open($this->selected);
        }
    }

    public function open(int $id): void
    {
        $invoice = $this->query()->findOrFail($id);
        $this->selected = $invoice->id;
        $this->resetValidation();
        $this->pay = [
            'discount' => (int) $invoice->discount,
            'discount_reason' => (string) $invoice->discount_reason,
            'method' => array_key_first(Settings::paymentMethods()),
            'amount' => $invoice->outstanding(),
            'cash' => null,
        ];
    }

    public function close(): void
    {
        $this->selected = null;
    }

    /** Owner tanpa batas; Admin dibatasi persentase di Pengaturan → Aplikasi. */
    private function maxDiscount(Invoice $invoice): int
    {
        if (app(BranchContext::class)->is(Role::Owner)) {
            return (int) $invoice->subtotal;
        }

        return (int) floor($invoice->subtotal * (int) Settings::get('admin_max_discount_percent') / 100);
    }

    /** FR-KSR-03: diskon wajib beralasan, hanya sebelum ada pembayaran. */
    public function applyDiscount(): void
    {
        $invoice = $this->query()->findOrFail($this->selected);
        abort_unless($invoice->paid === 0 && $invoice->status === Invoice::STATUS_UNPAID, 422);

        $this->validate([
            'pay.discount' => ['required', 'integer', 'min:0', 'max:'.$this->maxDiscount($invoice)],
            'pay.discount_reason' => [Rule::requiredIf((int) $this->pay['discount'] > 0), 'nullable', 'string', 'max:255'],
        ], [
            'pay.discount.max' => 'Diskon maksimal '.Format::rupiah($this->maxDiscount($invoice)).'.',
        ], ['pay.discount' => 'diskon', 'pay.discount_reason' => 'alasan diskon']);

        $invoice->update([
            'discount' => (int) $this->pay['discount'],
            'discount_reason' => $this->pay['discount'] > 0 ? $this->pay['discount_reason'] : null,
        ]);
        $invoice->recalculate();
        AuditLog::record('invoice.discount', $invoice, ['discount' => $invoice->discount, 'reason' => $invoice->discount_reason]);

        $this->pay['amount'] = $invoice->outstanding();
        $this->toast('Diskon diterapkan.');
    }

    public function submitPayment(BranchContext $context): void
    {
        $invoice = $this->query()->with('visit')->findOrFail($this->selected);
        abort_unless(in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL], true), 422);

        $this->validate([
            'pay.method' => ['required', Rule::in(array_keys(Settings::paymentMethods()))],
            'pay.amount' => ['required', 'integer', 'min:1', 'max:'.$invoice->outstanding()],
            'pay.cash' => ['nullable', 'integer', Rule::when($this->pay['method'] === 'tunai' && filled($this->pay['cash']), ['gte:pay.amount'])],
        ], ['pay.amount.max' => 'Melebihi sisa tagihan ('.Format::rupiah($invoice->outstanding()).').', 'pay.cash.gte' => 'Uang diterima kurang dari jumlah bayar.'], [
            'pay.amount' => 'jumlah bayar', 'pay.cash' => 'uang diterima',
        ]);

        $receipt = DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ((int) $this->pay['amount'] > $invoice->outstanding()) {
                throw ValidationException::withMessages(['pay.amount' => 'Tagihan sudah berubah, muat ulang.']);
            }

            $invoice->payments()->create([
                'received_by' => auth()->id(),
                'method' => $this->pay['method'],
                'amount' => (int) $this->pay['amount'],
                'paid_at' => now(),
            ]);

            $paid = $invoice->paid + (int) $this->pay['amount'];
            $done = $paid >= $invoice->total;
            $invoice->update([
                'paid' => $paid,
                'status' => $done ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL,
                'paid_at' => $done ? now() : null,
            ]);

            if (! $done) {
                return null;
            }

            $invoice->visit()->update(['status' => VisitStatus::Selesai, 'completed_at' => now()]);

            return $this->issueReceipt($invoice);
        });

        AuditLog::record('invoice.payment', $invoice, ['amount' => (int) $this->pay['amount'], 'method' => $this->pay['method']]);

        if ($receipt) {
            $this->toast("Lunas. Kwitansi {$receipt->code} siap dicetak.");
        } else {
            $this->toast('Pembayaran sebagian dicatat.');
        }

        $this->open($invoice->id);
    }

    // ── Penjualan langsung tanpa kunjungan (pasien rujukan / pembeli umum) ──

    public bool $showDirectSale = false;

    public array $direct = ['patient_id' => null, 'buyer_name' => ''];

    public string $directSearch = '';

    public function openDirectSale(): void
    {
        $this->direct = ['patient_id' => null, 'buyer_name' => ''];
        $this->directSearch = '';
        $this->resetErrorBag();
        $this->showDirectSale = true;
    }

    /** Membuat tagihan kosong tanpa kunjungan; barang ditambahkan lewat "Tambah obat / barang". */
    public function createDirectSale(BranchContext $context): void
    {
        $branchId = $this->targetBranch($context);

        $this->validate([
            'direct.patient_id' => ['nullable', Rule::exists('patients', 'id')->where('clinic_id', $this->clinicId())],
            'direct.buyer_name' => [Rule::requiredIf(blank($this->direct['patient_id'])), 'nullable', 'string', 'max:255'],
        ], ['direct.buyer_name.required' => 'Pilih pasien terdaftar atau ketik nama pembeli.'], ['direct.buyer_name' => 'nama pembeli']);

        $invoice = Invoice::create([
            'visit_id' => null,
            'patient_id' => $this->direct['patient_id'] ?: null,
            'buyer_name' => $this->direct['patient_id'] ? null : trim($this->direct['buyer_name']),
            'branch_id' => $branchId,
            'subtotal' => 0, 'total' => 0, 'paid' => 0,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        AuditLog::record('invoice.direct_sale.created', $invoice, ['buyer' => $invoice->payerName()]);
        $this->showDirectSale = false;
        $this->tab = 'tagihan';
        $this->open($invoice->id);
        $this->toast('Tagihan penjualan langsung dibuat. Tambahkan barangnya.');
    }

    /** Tagihan penjualan langsung yang kosong boleh dihapus (bukan tagihan pemeriksaan). */
    public function discardDirectSale(): void
    {
        $invoice = $this->query()->findOrFail($this->selected);
        abort_unless($invoice->isDirectSale() && $invoice->paid === 0 && ! $invoice->items()->exists(), 422);

        AuditLog::record('invoice.direct_sale.discarded', $invoice);
        $invoice->delete();
        $this->selected = null;
    }

    // ── Penjualan obat/barang di kasir (permintaan klien poin 5 & 8) ──

    public array $goods = ['item_id' => '', 'quantity' => 1];

    /** Tambah obat/barang ke tagihan yang belum dibayar. Stok dicek sekarang, dipotong saat lunas. */
    public function addGoods(\App\Services\StockService $stock): void
    {
        $invoice = $this->query()->findOrFail($this->selected);
        abort_unless($invoice->paid === 0 && $invoice->status === Invoice::STATUS_UNPAID, 422);

        $this->validate([
            'goods.item_id' => ['required', 'integer'],
            'goods.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ], attributes: ['goods.item_id' => 'barang', 'goods.quantity' => 'jumlah']);

        $item = \App\Models\Item::where('clinic_id', $this->clinicId())->where('is_active', true)
            ->whereNotNull('sell_price')->findOrFail((int) $this->goods['item_id']);
        $qty = (int) $this->goods['quantity'];
        $already = (int) $invoice->items()->where('item_id', $item->id)->sum('quantity');

        if ($stock->available($item->id, $invoice->branch_id) < $already + $qty) {
            throw ValidationException::withMessages(['goods.quantity' => "Stok {$item->name} tidak cukup (tersisa {$stock->available($item->id, $invoice->branch_id)} {$item->unit})."]);
        }

        $invoice->items()->create([
            'item_id' => $item->id, 'name' => $item->name, 'quantity' => $qty,
            'unit_price' => (int) $item->sell_price, 'amount' => (int) $item->sell_price * $qty,
        ]);
        $invoice->recalculate();
        AuditLog::record('invoice.goods.added', $invoice, ['item' => $item->name, 'qty' => $qty]);

        $this->goods = ['item_id' => '', 'quantity' => 1];
        $this->pay['amount'] = $invoice->fresh()->outstanding();
        $this->toast("{$item->name} ditambahkan ke tagihan.");
    }

    public function removeGoods(int $lineId): void
    {
        $invoice = $this->query()->findOrFail($this->selected);
        abort_unless($invoice->paid === 0 && $invoice->status === Invoice::STATUS_UNPAID, 422);

        $line = $invoice->items()->whereNotNull('item_id')->findOrFail($lineId);
        AuditLog::record('invoice.goods.removed', $invoice, ['item' => $line->name, 'qty' => $line->quantity]);
        $line->delete();
        $invoice->recalculate();
        $this->pay['amount'] = $invoice->fresh()->outstanding();
    }

    /** FR-CTK-02: nomor kwitansi berurutan per cabang, mulai dari angka di pengaturan cabang. */
    private function issueReceipt(Invoice $invoice): Receipt
    {
        $branch = $invoice->branch()->lockForUpdate()->first();
        $last = (int) Receipt::where('branch_id', $branch->id)->max('number');
        $number = max($last + 1, (int) $branch->receipt_start_number);

        return Receipt::create([
            'invoice_id' => $invoice->id,
            'branch_id' => $branch->id,
            'number' => $number,
            'code' => Receipts::code($branch->code, $number),
        ]);
    }

    /** Asisten pendamping kunjungan (dasar fee asisten di Gaji). Boleh diubah sebelum/sesudah lunas. */
    public function assignAssistant($employeeId = null): void
    {
        $invoice = $this->query()->with('visit')->findOrFail($this->selected);
        abort_unless($invoice->visit, 422);

        $employeeId = $employeeId === '' || $employeeId === null ? null : (int) $employeeId;
        if ($employeeId) {
            abort_unless(\App\Models\Employee::where('clinic_id', $this->clinicId())->where('position', 'Asisten dokter')->whereKey($employeeId)->exists(), 404);
        }

        $before = $invoice->visit->assistant_id;
        if ($before === $employeeId) {
            return;
        }
        $invoice->visit->update(['assistant_id' => $employeeId]);
        AuditLog::record('visit.assistant.updated', $invoice->visit, ['before' => $before, 'after' => $employeeId]);
        $this->toast($employeeId ? 'Asisten pendamping disimpan.' : 'Asisten pendamping dikosongkan.');
    }

    private function query()
    {
        $context = app(BranchContext::class);

        return Invoice::query()
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->when($context->id(), fn ($q) => $q->where('branch_id', $context->id()), fn ($q) => $q->whereIn('branch_id', $context->options()->pluck('id')));
    }

    public function render(BranchContext $context)
    {
        $pending = $this->query()
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->with(['visit.patient', 'visit.doctor', 'patient', 'branch'])
            ->orderBy('updated_at')
            ->get();

        $paidToday = $this->query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereDate('paid_at', today())
            ->with(['visit.patient', 'patient', 'branch', 'receipts', 'payments'])
            ->latest('paid_at')
            ->get();

        $invoice = $this->selected
            ? $this->query()->with(['visit.patient', 'visit.doctor', 'patient', 'branch', 'items', 'payments.receiver', 'receipts'])->find($this->selected)
            : null;

        return $this->page('kasir', 'livewire.modules.kasir', [
            'context' => $context,
            'pending' => $pending,
            'paidToday' => $paidToday,
            'invoice' => $invoice,
            'methods' => Settings::paymentMethods(),
            'allMethods' => Payment::METHODS,
            'maxDiscount' => $invoice ? $this->maxDiscount($invoice) : 0,
            'directPatient' => $this->showDirectSale && ($this->direct['patient_id'] ?? null) ? \App\Models\Patient::where('clinic_id', $this->clinicId())->find($this->direct['patient_id']) : null,
            'directResults' => $this->showDirectSale && mb_strlen(trim($this->directSearch)) >= 2 ? \App\Models\Patient::where('clinic_id', $this->clinicId())->search($this->directSearch)->limit(6)->get() : collect(),
            'goodsOptions' => $invoice && $invoice->paid === 0 && $invoice->status === Invoice::STATUS_UNPAID
                ? \App\Models\Item::where('clinic_id', $this->clinicId())->where('is_active', true)->whereNotNull('sell_price')
                    ->with(['stocks' => fn ($q) => $q->where('branch_id', $invoice->branch_id)])->orderBy('category')->orderBy('name')->get()
                : collect(),
            'totalToday' => $paidToday->sum('total'),
            'assistants' => $invoice?->visit
                ? \App\Models\Employee::where('clinic_id', $this->clinicId())->where('position', 'Asisten dokter')
                    ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $invoice->visit->assistant_id))
                    ->orderBy('name')->get(['id', 'name', 'is_active'])
                : collect(),
        ]);
    }
}
