<?php

use App\Livewire\Modules\Stok;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', 'MLG')->first();
    $this->item = Item::where('code', 'PCT500')->first();
});

function purchaseLogin(string $username, string $role, string|int $branch): void
{
    test()->actingAs(User::where('username', $username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

function qtyAt(int $itemId, int $branchId): int
{
    return app(StockService::class)->available($itemId, $branchId);
}

function buyValue(int $itemId): int
{
    return (int) StockMovement::where('item_id', $itemId)->where('type', 'masuk')->get()->sum(fn ($m) => $m->quantity * $m->unit_cost);
}

function makePurchase(int $itemId, int $qty, int $cost): Purchase
{
    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->set('purchase.supplier', 'Apotek Uji')
        ->set('purchase.reference', 'F-1')
        ->set('purchase.lines.0.item_id', (string) $itemId)
        ->set('purchase.lines.0.quantity', $qty)
        ->set('purchase.lines.0.unit_cost', $cost)
        ->call('savePurchase')
        ->assertHasNoErrors();

    return Purchase::where('reference', 'F-1')->latest('id')->firstOrFail();
}

it('mengubah jumlah & harga pembelian: stok, pengeluaran, dan nilai beli ikut terkoreksi', function () {
    purchaseLogin('owner', 'owner', $this->batu->id);
    $stockBefore = qtyAt($this->item->id, $this->batu->id);
    $valueBefore = buyValue($this->item->id);

    $p = makePurchase($this->item->id, 100, 300);

    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->call('editPurchase', $p->id)
        ->assertSet('purchase.lines.0.quantity', 100)
        ->set('purchase.lines.0.quantity', 80)
        ->set('purchase.lines.0.unit_cost', 350)
        ->call('savePurchase')
        ->assertHasNoErrors()
        ->assertSet('editingPurchaseId', null);

    $p->refresh();
    expect(qtyAt($this->item->id, $this->batu->id))->toBe($stockBefore + 80)
        ->and($p->total)->toBe(28000)
        ->and(Expense::find($p->expense_id)->amount)->toBe(28000)
        ->and(buyValue($this->item->id) - $valueBefore)->toBe(28000)
        ->and($this->item->fresh()->buy_price)->toBe(350);
});

it('menolak pengurangan bila stok pembelian sudah terpakai', function () {
    purchaseLogin('owner', 'owner', $this->batu->id);
    $p = makePurchase($this->item->id, 10, 300);
    $left = qtyAt($this->item->id, $this->batu->id);

    // Pakai hampir semua stok sehingga sisa < 10.
    app(StockService::class)->move($this->item->id, $this->batu->id, -($left - 3), 'pakai');

    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->call('editPurchase', $p->id)
        ->set('purchase.lines.0.quantity', 1)
        ->call('savePurchase')
        ->assertHasErrors('stock');

    expect($p->fresh()->total)->toBe(3000)->and(qtyAt($this->item->id, $this->batu->id))->toBe(3);
});

it('membatalkan pembelian: stok kembali dan pengeluaran dihapus', function () {
    purchaseLogin('owner', 'owner', $this->batu->id);
    $before = qtyAt($this->item->id, $this->batu->id);
    $p = makePurchase($this->item->id, 20, 500);
    $expenseId = $p->expense_id;

    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->call('askCancelPurchase', $p->id)
        ->call('confirmCancelPurchase')
        ->assertHasErrors('cancelReason')
        ->set('cancelReason', 'Salah input faktur')
        ->call('confirmCancelPurchase')
        ->assertHasNoErrors();

    expect(qtyAt($this->item->id, $this->batu->id))->toBe($before)
        ->and(Purchase::find($p->id))->toBeNull()
        ->and(Expense::find($expenseId))->toBeNull();
});

it('owner di semua cabang bisa memindahkan pembelian ke cabang lain', function () {
    purchaseLogin('owner', 'owner', 'all');
    $batuBefore = qtyAt($this->item->id, $this->batu->id);
    $malangBefore = qtyAt($this->item->id, $this->malang->id);

    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->set('branchPick', (string) $this->batu->id)
        ->set('purchase.reference', 'F-2')
        ->set('purchase.lines.0.item_id', (string) $this->item->id)
        ->set('purchase.lines.0.quantity', 5)
        ->set('purchase.lines.0.unit_cost', 400)
        ->call('savePurchase')
        ->assertHasNoErrors();
    $p = Purchase::where('reference', 'F-2')->firstOrFail();

    Livewire::test(Stok::class)
        ->call('setTab', 'pembelian')
        ->call('editPurchase', $p->id)
        ->assertSet('branchPick', (string) $this->batu->id)
        ->set('branchPick', (string) $this->malang->id)
        ->call('savePurchase')
        ->assertHasNoErrors();

    expect(qtyAt($this->item->id, $this->batu->id))->toBe($batuBefore)
        ->and(qtyAt($this->item->id, $this->malang->id))->toBe($malangBefore + 5)
        ->and(Expense::find($p->fresh()->expense_id)->branch_id)->toBe($this->malang->id);
});

it('admin yang diberi akses stok tetap tidak bisa mengubah pembelian cabang lain', function () {
    // Bawaan Admin tidak boleh pembelian (khusus Owner); Owner bisa membukanya lewat Hak akses peran.
    $matrix = \App\Support\Access::matrix();
    $matrix['admin']['stok'] = ['lihat' => true, 'edit' => true, 'keuangan' => true];
    \App\Support\Access::save($matrix);
    purchaseLogin('admin.malang', 'admin', $this->malang->id);
    $other = Purchase::create(['branch_id' => $this->batu->id, 'date' => today(), 'total' => 0]);

    expect(fn () => Livewire::test(Stok::class)->call('editPurchase', $other->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
