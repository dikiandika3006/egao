<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stok obat, bahan, dan alat per cabang.
 * Semua perubahan stok lewat move() → baris item_stocks dikunci, buku stok (stock_movements) tercatat.
 */
class StockService
{
    public const PURCHASE_CATEGORY = 'Pembelian obat & bahan';

    public function available(int $itemId, int $branchId): int
    {
        return (int) DB::table('item_stocks')->where(['item_id' => $itemId, 'branch_id' => $branchId])->value('quantity');
    }

    /**
     * Ubah stok satu barang di satu cabang.
     *
     * @param  int  $delta  + masuk, − keluar
     */
    public function move(int $itemId, int $branchId, int $delta, string $type, array $attrs = [], bool $allowNegative = false): StockMovement
    {
        return DB::transaction(function () use ($itemId, $branchId, $delta, $type, $attrs, $allowNegative) {
            $key = ['item_id' => $itemId, 'branch_id' => $branchId];
            DB::table('item_stocks')->insertOrIgnore($key + ['quantity' => 0, 'avg_cost' => 0]);
            $row = DB::table('item_stocks')->where($key)->lockForUpdate()->first();

            $newQty = $row->quantity + $delta;
            if ($delta < 0 && $newQty < 0 && ! $allowNegative) {
                $item = Item::find($itemId);
                throw ValidationException::withMessages([
                    'stock' => "Stok {$item->name} tidak cukup (tersisa {$row->quantity} {$item->unit}).",
                ]);
            }

            // HPP rata-rata tertimbang hanya berubah saat barang masuk dengan harga beli.
            $avg = (int) $row->avg_cost;
            if ($type === 'masuk' && $delta > 0) {
                $cost = (int) ($attrs['unit_cost'] ?? 0);
                $base = max(0, (int) $row->quantity);
                $avg = (int) round((($base * $avg) + ($delta * $cost)) / max(1, $base + $delta));
            }

            DB::table('item_stocks')->where($key)->update(['quantity' => $newQty, 'avg_cost' => $avg]);

            return StockMovement::create($key + [
                'type' => $type,
                'quantity' => $delta,
                'unit_cost' => (int) ($attrs['unit_cost'] ?? $avg),
                'unit_price' => $attrs['unit_price'] ?? null,
                'purchase_id' => $attrs['purchase_id'] ?? null,
                'invoice_id' => $attrs['invoice_id'] ?? null,
                'note' => $attrs['note'] ?? null,
                'user_id' => auth()->id(),
                'occurred_at' => $attrs['occurred_at'] ?? now(),
            ]);
        });
    }

    /**
     * Pembelian: stok masuk + harga beli terakhir + pengeluaran otomatis (kas keluar tercatat di laporan).
     *
     * @param  list<array{item_id:int, quantity:int, unit_cost:int}>  $lines
     */
    public function purchase(int $clinicId, int $branchId, CarbonInterface $date, ?string $supplier, ?string $reference, array $lines): Purchase
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Tambahkan minimal satu barang.']);
        }

        return DB::transaction(function () use ($clinicId, $branchId, $date, $supplier, $reference, $lines) {
            $items = Item::where('clinic_id', $clinicId)->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');

            $purchase = Purchase::create([
                'branch_id' => $branchId, 'date' => $date->toDateString(), 'supplier' => $supplier,
                'reference' => $reference, 'created_by' => auth()->id(),
            ]);

            $total = 0;
            foreach ($lines as $line) {
                $item = $items->get((int) $line['item_id']) ?? throw ValidationException::withMessages(['lines' => 'Barang tidak ditemukan.']);
                $qty = (int) $line['quantity'];
                $cost = (int) $line['unit_cost'];
                $amount = $qty * $cost;
                $total += $amount;

                $purchase->items()->create(['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $cost, 'amount' => $amount]);
                $this->move($item->id, $branchId, $qty, 'masuk', [
                    'unit_cost' => $cost, 'purchase_id' => $purchase->id, 'occurred_at' => $date->copy()->setTimeFrom(now()),
                    'note' => trim(($supplier ? "Dari {$supplier}" : 'Pembelian').($reference ? " • {$reference}" : '')),
                ]);
                $item->update(['buy_price' => $cost]);
            }

            $category = ExpenseCategory::firstOrCreate(['clinic_id' => $clinicId, 'name' => self::PURCHASE_CATEGORY], ['is_system' => false]);
            $expense = Expense::create([
                'branch_id' => $branchId, 'expense_category_id' => $category->id, 'created_by' => auth()->id(),
                'date' => $date->toDateString(), 'amount' => $total,
                'description' => '[STOK] '.($supplier ?: 'Pembelian').($reference ? " • {$reference}" : '').' — '.count($lines).' barang',
            ]);

            $purchase->update(['total' => $total, 'expense_id' => $expense->id]);
            AuditLog::record('stok.pembelian', $purchase, ['total' => $total]);

            return $purchase;
        });
    }

    /**
     * Ubah pembelian yang sudah tercatat.
     *
     * Hanya barang yang berubah (jumlah/harga/cabang/tanggal) yang dikoreksi: baris baru dicatat sebagai 'masuk'
     * pada tanggal baru, lalu baris lama dibalik sebagai 'masuk' bernilai negatif pada tanggal lama. Dengan begitu
     * nilai beli di Laporan jual-beli tetap tepat per periode. Penambahan dicatat lebih dulu agar pengecekan stok
     * memakai saldo bersih; bila stok sudah terpakai/terjual sehingga koreksi membuat stok minus, edit ditolak.
     * Catatan: HPP rata-rata (avg_cost) tidak dihitung ulang ke belakang.
     *
     * @param  list<array{item_id:int, quantity:int, unit_cost:int}>  $lines
     */
    public function updatePurchase(Purchase $purchase, int $branchId, CarbonInterface $date, ?string $supplier, ?string $reference, array $lines): Purchase
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Tambahkan minimal satu barang.']);
        }

        return DB::transaction(function () use ($purchase, $branchId, $date, $supplier, $reference, $lines) {
            $purchase = Purchase::with('items.item')->lockForUpdate()->findOrFail($purchase->id);
            $clinicId = (int) Item::whereKey($purchase->items->first()?->item_id)->value('clinic_id') ?: (int) auth()->user()->clinic_id;
            $items = Item::where('clinic_id', $clinicId)->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');

            $before = $purchase->only(['branch_id', 'supplier', 'reference', 'total']) + [
                'date' => $purchase->date->toDateString(),
                'lines' => $purchase->items->map->only(['item_id', 'quantity', 'unit_cost'])->all(),
            ];

            $sig = fn ($rows) => collect($rows)->map(fn ($l) => (int) $l['quantity'].'@'.(int) $l['unit_cost'])->sort()->values()->all();
            $oldByItem = $purchase->items->groupBy('item_id');
            $newByItem = collect($lines)->groupBy(fn ($l) => (int) $l['item_id']);
            $moved = $purchase->branch_id !== $branchId || ! $purchase->date->isSameDay($date);
            $note = trim('Koreksi pembelian'.($supplier ? " {$supplier}" : '').($reference ? " • {$reference}" : ''));

            // 1) Tambahkan baris baru untuk barang yang berubah.
            foreach ($newByItem as $itemId => $rows) {
                $old = $oldByItem->get($itemId, collect());
                if (! $moved && $sig($old->map->only(['quantity', 'unit_cost'])) === $sig($rows)) {
                    continue;
                }
                $items->get($itemId) ?? throw ValidationException::withMessages(['lines' => 'Barang tidak ditemukan.']);
                foreach ($rows as $l) {
                    $this->move($itemId, $branchId, (int) $l['quantity'], 'masuk', [
                        'unit_cost' => (int) $l['unit_cost'], 'purchase_id' => $purchase->id,
                        'occurred_at' => $date->copy()->setTimeFrom(now()), 'note' => $note,
                    ]);
                }
            }

            // 2) Balik baris lama untuk barang yang berubah/dihapus.
            foreach ($oldByItem as $itemId => $old) {
                $rows = $newByItem->get($itemId, collect());
                if (! $moved && $sig($old->map->only(['quantity', 'unit_cost'])) === $sig($rows)) {
                    continue;
                }
                foreach ($old as $l) {
                    $this->reversePurchaseLine($purchase, $l, $note);
                }
            }

            // 3) Simpan rincian & header baru.
            $purchase->items()->delete();
            $total = 0;
            foreach ($lines as $l) {
                $amount = (int) $l['quantity'] * (int) $l['unit_cost'];
                $total += $amount;
                $purchase->items()->create(['item_id' => (int) $l['item_id'], 'quantity' => (int) $l['quantity'], 'unit_cost' => (int) $l['unit_cost'], 'amount' => $amount]);
                $items->get((int) $l['item_id'])?->update(['buy_price' => (int) $l['unit_cost']]);
            }

            $purchase->update([
                'branch_id' => $branchId, 'date' => $date->toDateString(), 'supplier' => $supplier,
                'reference' => $reference, 'total' => $total,
            ]);

            // 4) Pengeluaran otomatis ikut diperbarui.
            $description = '[STOK] '.($supplier ?: 'Pembelian').($reference ? " • {$reference}" : '').' — '.count($lines).' barang';
            if ($purchase->expense_id && ($expense = Expense::find($purchase->expense_id))) {
                $expense->update(['branch_id' => $branchId, 'date' => $date->toDateString(), 'amount' => $total, 'description' => $description]);
            }

            AuditLog::record('stok.pembelian.diubah', $purchase, ['sebelum' => $before, 'sesudah' => [
                'branch_id' => $branchId, 'date' => $date->toDateString(), 'supplier' => $supplier, 'reference' => $reference,
                'total' => $total, 'lines' => $lines,
            ]]);

            return $purchase->fresh('items');
        });
    }

    /** Batalkan pembelian: stok dikurangi kembali, pengeluaran dihapus. Ditolak bila stok sudah terpakai. */
    public function cancelPurchase(Purchase $purchase, string $reason): void
    {
        DB::transaction(function () use ($purchase, $reason) {
            $purchase = Purchase::with('items.item')->lockForUpdate()->findOrFail($purchase->id);
            $note = 'Batal pembelian'.($purchase->reference ? " • {$purchase->reference}" : '').' — '.$reason;

            foreach ($purchase->items as $l) {
                $this->reversePurchaseLine($purchase, $l, $note);
            }

            if ($purchase->expense_id) {
                Expense::whereKey($purchase->expense_id)->delete();
            }

            AuditLog::record('stok.pembelian.dibatalkan', $purchase, [
                'alasan' => $reason, 'total' => $purchase->total, 'date' => $purchase->date->toDateString(),
                'lines' => $purchase->items->map->only(['item_id', 'quantity', 'unit_cost'])->all(),
            ]);

            $purchase->items()->delete();
            $purchase->delete(); // mutasi stok tetap tersimpan (purchase_id → null) sebagai jejak.
        });
    }

    /** 'masuk' bernilai negatif pada tanggal pembelian lama; pesan jelas bila stok sudah terpakai. */
    private function reversePurchaseLine(Purchase $purchase, $line, string $note): void
    {
        try {
            $this->move($line->item_id, $purchase->branch_id, -(int) $line->quantity, 'masuk', [
                'unit_cost' => (int) $line->unit_cost, 'purchase_id' => $purchase->id,
                'occurred_at' => $purchase->date->copy()->setTimeFrom(now()), 'note' => $note,
            ]);
        } catch (ValidationException) {
            $item = $line->item;
            $left = $this->available($line->item_id, $purchase->branch_id);
            throw ValidationException::withMessages([
                'stock' => "{$item->name} dari pembelian ini sudah terpakai/terjual (sisa stok {$left} {$item->unit}), jadi jumlahnya tidak bisa dikurangi sebanyak itu. Kurangi sebagian saja, atau catat lewat stok opname.",
            ]);
        }
    }

    /** Stok keluar saat tagihan lunas (dipanggil dari event model Invoice). */
    public function postSale(Invoice $invoice): void
    {
        if ($invoice->stock_posted_at) {
            return;
        }

        foreach ($invoice->items()->whereNotNull('item_id')->get() as $line) {
            $this->move($line->item_id, $invoice->branch_id, -$line->quantity, 'jual', [
                'unit_price' => $line->unit_price, 'invoice_id' => $invoice->id, 'note' => 'Tagihan #'.$invoice->id,
            ]);
        }

        $invoice->forceFill(['stock_posted_at' => now()])->saveQuietly();
    }

    /** Tagihan lunas dibatalkan → stok dikembalikan. */
    public function reverseSale(Invoice $invoice): void
    {
        if (! $invoice->stock_posted_at) {
            return;
        }

        foreach ($invoice->items()->whereNotNull('item_id')->get() as $line) {
            $this->move($line->item_id, $invoice->branch_id, $line->quantity, 'batal_jual', [
                'unit_price' => $line->unit_price, 'invoice_id' => $invoice->id, 'note' => 'Batal tagihan #'.$invoice->id,
            ]);
        }

        $invoice->forceFill(['stock_posted_at' => null])->saveQuietly();
    }

    /** Harga pokok satu satuan barang di cabang: HPP rata-rata, atau harga beli terakhir bila belum ada. */
    public function unitCost(Item $item, int $branchId): int
    {
        $avg = (int) DB::table('item_stocks')->where(['item_id' => $item->id, 'branch_id' => $branchId])->value('avg_cost');

        return $avg > 0 ? $avg : (int) $item->buy_price;
    }

    /**
     * Pemakaian bahan resep tindakan (FR aturan Owner 24 Nov). Takaran pecahan dikumpulkan di item_usage_balances;
     * stok baru dipotong tiap saldo genap 1 satuan. Hanya selisih terhadap yang sudah diposting tagihan ini yang diproses,
     * jadi aman dipanggil berulang (simpan ulang pemeriksaan, batal tagihan). Stok boleh minus agar pemeriksaan tidak terhambat.
     */
    public function syncMaterials(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->find($invoice->id);
            if (! $invoice) {
                return;
            }

            $desired = [];
            if (! in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID], true)) {
                $lines = $invoice->items()->whereNotNull('procedure_id')->with('procedure.materials')->get();
                foreach ($lines as $line) {
                    foreach ($line->procedure?->materials ?? [] as $m) {
                        $desired[$m->item_id] = ($desired[$m->item_id] ?? 0) + $m->quantity * (int) $line->quantity;
                    }
                }
            }

            $posted = (array) ($invoice->materials_posted ?? []);
            $itemIds = array_unique(array_merge(array_keys($desired), array_keys($posted)));

            foreach ($itemIds as $itemId) {
                $delta = round(($desired[$itemId] ?? 0) - (float) ($posted[$itemId] ?? 0), 4);
                if ($delta != 0.0) {
                    $this->consume((int) $itemId, $invoice->branch_id, $delta, $invoice);
                }
            }

            $invoice->forceFill(['materials_posted' => array_map(fn ($q) => round($q, 4), $desired) ?: null])->saveQuietly();
        });
    }

    /** Tambah (+) / kurangi (−) saldo pemakaian; potong/kembalikan stok per satuan utuh. */
    private function consume(int $itemId, int $branchId, float $delta, Invoice $invoice): void
    {
        $key = ['item_id' => $itemId, 'branch_id' => $branchId];
        DB::table('item_usage_balances')->insertOrIgnore($key + ['pending' => 0]);
        $pending = (float) DB::table('item_usage_balances')->where($key)->lockForUpdate()->value('pending') + $delta;

        $whole = $pending >= 1 ? (int) floor($pending + 1e-9) : ($pending < 0 ? (int) floor($pending) : 0);
        if ($whole !== 0) {
            $this->move($itemId, $branchId, -$whole, $whole > 0 ? 'pakai' : 'batal_pakai', [
                'invoice_id' => $invoice->id, 'note' => 'Bahan tindakan tagihan #'.$invoice->id,
            ], allowNegative: true);
            $pending -= $whole;
        }

        DB::table('item_usage_balances')->where($key)->update(['pending' => round($pending, 4)]);
    }
}
