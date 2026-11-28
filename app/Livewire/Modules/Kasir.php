<?php

namespace App\Livewire\Modules;

use App\Enums\VisitStatus;
use App\Livewire\Concerns\ModulePage;
use App\Livewire\Concerns\PicksBranch;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Receipt;
use App\Enums\Role;
use App\Services\ExamService;
use App\Support\BranchContext;
use App\Support\Format;
use App\Support\Receipts;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 05 Kasir (FR-KSR-01 … 06, FR-CTK-01 … 03): tagihan dari tindakan dokter, diskon, pembayaran, kwitansi.
 */
class Kasir extends Component
{
    use ModulePage, PicksBranch, WithPagination;

    public const MENU = 'kasir';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['open', 'close'];

    #[Url(as: 'tagihan', except: null)]
    public ?int $selected = null;

    #[Url(as: 'tab', except: 'tagihan')]
    public string $tab = 'tagihan';

    // ── Riwayat: tagihan lunas semua tanggal, untuk melihat & cetak ulang kwitansi lama ──

    #[Url(as: 'cari', except: '')]
    public string $historySearch = '';

    #[Url(as: 'dari', except: '')]
    public ?string $historyFrom = '';

    #[Url(as: 'sampai', except: '')]
    public ?string $historyTo = '';

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->tab === 'riwayat' && $this->fillHistoryRange();
    }

    /** Isi input tanggal riwayat dengan rentang yang berlaku (bawaan 30 hari terakhir). */
    private function fillHistoryRange(): void
    {
        [$from, $to] = $this->historyRange();
        $this->historyFrom = $from->toDateString();
        $this->historyTo = $to->toDateString();
    }

    public function updatedHistorySearch(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryFrom(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryTo(): void
    {
        $this->resetPage();
    }

    /** Rentang riwayat; bawaan 30 hari terakhir, tanggal tidak valid diabaikan, dari > sampai ditukar. */
    private function historyRange(): array
    {
        $parse = fn (?string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)
            ? rescue(fn () => Carbon::createFromFormat('!Y-m-d', $d), null, false)
            : null;
        $from = $parse($this->historyFrom) ?? today()->subDays(30);
        $to = $parse($this->historyTo) ?? today();

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    private function history()
    {
        [$from, $to] = $this->historyRange();
        $term = trim($this->historySearch);

        return $this->query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereDate('paid_at', '>=', $from)
            ->whereDate('paid_at', '<=', $to)
            ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term) {
                $q->where('buyer_name', 'like', "%{$term}%")
                    ->orWhereHas('receipts', fn ($r) => $r->where('code', 'like', "%{$term}%"))
                    ->orWhereHas('patient', fn ($p) => $p->search($term))
                    ->orWhereHas('visit.patient', fn ($p) => $p->search($term));
            }));
    }

    public array $pay = ['discount' => 0, 'discount_reason' => '', 'method' => 'tunai', 'amount' => 0, 'cash' => null];

    public function mount(): void
    {
        $this->tab === 'riwayat' && $this->fillHistoryRange();

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

    /**
     * Diskon diberikan dokter di pemeriksaan (ExamService). Di Kasir hanya Owner yang boleh memberi/mengubah diskon;
     * Admin/Asisten hanya mencatat pembayaran & mencetak kwitansi.
     */
    private function canDiscount(): bool
    {
        return app(BranchContext::class)->is(Role::Owner);
    }

    /** Owner tanpa batas. */
    private function maxDiscount(Invoice $invoice): int
    {
        return (int) $invoice->subtotal;
    }

    /** FR-KSR-03: diskon wajib beralasan, hanya sebelum ada pembayaran. Khusus Owner. */
    public function applyDiscount(): void
    {
        abort_unless($this->canDiscount(), 403);
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

            // Pembayaran pertama tagihan berbiaya pendaftaran → pasien tercatat sebagai pasien lama.
            if ($invoice->visit && $invoice->items()->where('fee_kind', InvoiceItem::FEE_REGISTRATION)->exists()) {
                Patient::whereKey($invoice->visit->patient_id)->update(['registration_paid' => true]);
            }

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

    /**
     * Hapus baris biaya admin. Hanya sebelum ada pembayaran; tidak ditagih ulang di tagihan ini.
     * Menghapus biaya pendaftaran = pasien ternyata pasien lama → tidak ditagih pendaftaran lagi.
     */
    public function removeAdminFee(int $lineId): void
    {
        $invoice = $this->query()->with('visit.patient')->findOrFail($this->selected);
        abort_unless($invoice->paid === 0 && $invoice->status === Invoice::STATUS_UNPAID, 422);

        $line = $invoice->items()->where('is_admin_fee', true)->findOrFail($lineId);
        AuditLog::record('invoice.admin_fee.removed', $invoice, ['item' => $line->name, 'jenis' => $line->fee_kind, 'amount' => $line->amount]);
        if ($line->fee_kind === InvoiceItem::FEE_REGISTRATION && $invoice->visit?->patient) {
            $invoice->visit->patient->update(['registration_paid' => true]);
        }
        $line->delete();
        $invoice->recalculate();
        $this->pay['amount'] = $invoice->fresh()->outstanding();
        $this->toast('Biaya admin dihapus dari tagihan ini.');
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

        // Piutang: tagihan belum lunas dari hari sebelumnya (cicilan/bayar sebagian). Fee dokter tidak menunggu pelunasan.
        $receivables = $pending->filter(fn (Invoice $i) => $i->created_at->lt(today()))
            ->sortBy('created_at')->values();

        $paidToday = $this->query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereDate('paid_at', today())
            ->with(['visit.patient', 'patient', 'branch', 'receipts', 'payments'])
            ->latest('paid_at')
            ->get();

        $invoice = $this->selected
            ? $this->query()->with(['visit.patient', 'visit.doctor', 'visit.assistant', 'visit.assistant2', 'patient', 'branch', 'items', 'payments.receiver', 'receipts'])->find($this->selected)
            : null;

        [$historyFrom, $historyTo] = $this->historyRange();
        $history = $this->tab === 'riwayat'
            ? $this->history()->with(['visit.patient', 'patient', 'branch', 'receipts'])->latest('paid_at')->paginate(25)
            : null;

        return $this->page('kasir', 'livewire.modules.kasir', [
            'context' => $context,
            'history' => $history,
            'historyTotal' => $history ? (int) $this->history()->sum('total') : 0,
            'rangeFrom' => $historyFrom,
            'rangeTo' => $historyTo,
            'pending' => $pending,
            // Tombol Edit: tagihan kunjungan yang belum dibayar sama sekali dan boleh diubah pengguna ini (aturan Mode edit pemeriksaan).
            'editableIds' => $pending->filter(fn (Invoice $i) => $i->visit && app(ExamService::class)->canEditSentBilling($i->visit))->pluck('id')->all(),
            'paidToday' => $paidToday,
            'receivables' => $receivables,
            'invoice' => $invoice,
            'methods' => Settings::paymentMethods(),
            'allMethods' => Payment::METHODS,
            'maxDiscount' => $invoice ? $this->maxDiscount($invoice) : 0,
            'canDiscount' => $this->canDiscount(),
            'directPatient' => $this->showDirectSale && ($this->direct['patient_id'] ?? null) ? \App\Models\Patient::where('clinic_id', $this->clinicId())->find($this->direct['patient_id']) : null,
            'directResults' => $this->showDirectSale && mb_strlen(trim($this->directSearch)) >= 2 ? \App\Models\Patient::where('clinic_id', $this->clinicId())->search($this->directSearch)->limit(6)->get() : collect(),
            'goodsOptions' => $invoice && $invoice->paid === 0 && $invoice->status === Invoice::STATUS_UNPAID
                ? \App\Models\Item::where('clinic_id', $this->clinicId())->where('is_active', true)->whereNotNull('sell_price')
                    ->with(['stocks' => fn ($q) => $q->where('branch_id', $invoice->branch_id)])->orderBy('category')->orderBy('name')->get()
                : collect(),
            'totalToday' => $paidToday->sum('total'),
        ]);
    }
}
