<?php

use App\Livewire\MedicalRecords\Exam;
use App\Livewire\Modules\Kasir;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\User;
use App\Models\Visit;
use App\Services\StockService;
use App\Support\BranchContext;
use App\Support\Settings;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Settings::set('admin_fee', '0');
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->visit = Visit::whereDate('visit_date', today())->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))
        ->with('doctor.user')->firstOrFail();
    $this->odol = Item::create([
        'clinic_id' => $this->visit->doctor->clinic_id, 'code' => 'ODOL', 'name' => 'Odol sensitif', 'category' => 'barang',
        'unit' => 'pcs', 'buy_price' => 15_000, 'sell_price' => 25_000, 'min_stock' => 0,
    ]);
    app(StockService::class)->move($this->odol->id, $this->batu->id, 5, 'masuk', ['note' => 'stok awal test']);
});

function sebagaiDokterKunjungan(Visit $visit): void
{
    test()->actingAs(User::where('username', $visit->doctor->user->username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $visit->branch_id]);
}

it('barang lain-lain ikut ditagihkan tanpa bagi hasil dan tidak tercatat di resep', function () {
    sebagaiDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->call('addGoods')
        ->set('goods.0.item_id', (string) $this->odol->id)
        ->set('goods.0.quantity', 2)
        ->assertSet('goodsTotal', 50_000)
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertHasNoErrors();

    $visit = $this->visit->fresh();
    $line = $visit->invoice->items()->where('item_id', $this->odol->id)->first();

    expect($line)->not->toBeNull()
        ->and($line->quantity)->toBe(2)
        ->and($line->amount)->toBe(50_000)
        ->and($line->doctor_id)->toBeNull()
        ->and($visit->prescriptions()->count())->toBe(0);
});

it('barang yang sudah dipilih dimuat ulang saat halaman dibuka lagi', function () {
    sebagaiDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->call('addGoods')
        ->set('goods.0.item_id', (string) $this->odol->id)
        ->set('goods.0.quantity', 3)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $exam = Livewire::test(Exam::class, ['visit' => $this->visit->fresh()]);
    expect($exam->get('goods'))->toHaveCount(1)
        ->and((int) $exam->get('goods.0.item_id'))->toBe($this->odol->id)
        ->and((int) $exam->get('goods.0.quantity'))->toBe(3);
});

it('menolak barang lain-lain bila stok cabang tidak cukup', function () {
    sebagaiDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->call('addGoods')
        ->set('goods.0.item_id', (string) $this->odol->id)
        ->set('goods.0.quantity', 6)
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertHasErrors('goods');

    expect($this->visit->fresh()->invoice?->items()->where('item_id', $this->odol->id)->exists())->toBeFalsy();
});

it('obat dan barang klinik lain tidak bisa dipilih di lain-lain', function () {
    sebagaiDokterKunjungan($this->visit);
    $obat = Item::where('code', 'AMX500')->first();
    $lain = Clinic::create(['name' => 'Klinik Lain']);
    $asing = Item::create([
        'clinic_id' => $lain->id, 'code' => 'X1', 'name' => 'Odol klinik lain', 'category' => 'barang',
        'unit' => 'pcs', 'sell_price' => 10_000, 'min_stock' => 0,
    ]);

    foreach ([$obat, $asing] as $item) {
        Livewire::test(Exam::class, ['visit' => $this->visit])
            ->call('addGoods')
            ->set('goods.0.item_id', (string) $item->id)
            ->set('allergyConfirmed', true)
            ->call('confirmSend')
            ->assertHasErrors('goods.0.item_id');
    }

    expect($this->visit->fresh()->invoice?->items()->whereIn('item_id', [$obat->id, $asing->id])->exists())->toBeFalsy();
});

it('stok barang lain-lain dipotong saat tagihan lunas', function () {
    sebagaiDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->call('addGoods')
        ->set('goods.0.item_id', (string) $this->odol->id)
        ->set('goods.0.quantity', 2)
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertHasNoErrors();

    $stock = app(StockService::class);
    expect($stock->available($this->odol->id, $this->batu->id))->toBe(5);

    $invoice = $this->visit->fresh()->invoice;
    test()->actingAs(User::where('username', 'admin')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id]);

    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->set('pay.amount', $invoice->total)
        ->call('submitPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and($stock->available($this->odol->id, $this->batu->id))->toBe(3);
});
