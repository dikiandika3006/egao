<?php

use App\Enums\Role;
use App\Livewire\Modules\Stok;
use App\Models\Branch;
use App\Models\Item;
use App\Models\User;
use App\Services\StockService;
use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Navigation;
use Livewire\Livewire;

/*
 * Grup menu "Manajemen stok" (25 Nov 2026): Posisi stok, Kartu stok, Mutasi stok, Pembelian & opname, Laporan jual-beli.
 * Admin/Asisten hanya melihat posisi & kartu stok tanpa harga. Mutasi stok digabung ke Kartu stok.
 */

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    $this->item = Item::where('is_active', true)->orderBy('id')->firstOrFail();
});

function stokLogin(string $username, string $role, string|int $branch): void
{
    $ctx = [BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch];
    test()->actingAs(User::where('username', $username)->firstOrFail())->withSession($ctx);
    session($ctx);
    Access::flush();
}

it('stok menjadi grup menu sendiri "Manajemen stok"', function () {
    expect(Navigation::GROUPS)->toHaveKey('stok', 'Manajemen stok')
        ->and(array_keys(Navigation::visible(Role::Owner, 'stok')))
        ->toBe(['posisi-stok', 'kartu-stok', 'stok', 'laporan-stok'])
        ->and(array_keys(Navigation::visible(Role::Admin, 'stok')))
        ->toBe(['posisi-stok', 'kartu-stok'])
        ->and(Navigation::url(Navigation::item('kartu-stok')))->toBe(route('stok', ['tab' => 'kartu']))
        ->and(Navigation::isActive('kartu-stok', Navigation::item('kartu-stok'), 'stok', 'kartu'))->toBeTrue()
        ->and(Navigation::isActive('stok', Navigation::item('stok'), 'stok', 'kartu'))->toBeFalse();
});

it('Admin melihat posisi dan kartu stok (semua mutasi & satu barang) tanpa harga', function () {
    stokLogin('admin', 'admin', $this->batu->id);

    foreach ([['tab' => 'posisi'], ['tab' => 'kartu'], ['tab' => 'kartu', 'barang' => $this->item->id], ['tab' => 'mutasi']] as $q) {
        $this->get(route('stok', $q))->assertOk()
            ->assertDontSee('Nilai persediaan')->assertDontSee('Rp•••')
            ->assertDontSeeHtml('<th class="text-right">Nilai</th>')->assertDontSee('Harga beli');
    }
});

it('Admin tidak bisa membuka pembelian, opname, laporan jual-beli, atau menyimpannya', function () {
    stokLogin('admin', 'admin', $this->batu->id);

    Livewire::test(Stok::class)
        ->assertDontSee("setTab('pembelian')", false)
        ->call('setTab', 'pembelian')->assertNotFound();
    Livewire::test(Stok::class)->call('setTab', 'laporan')->assertNotFound();
    Livewire::test(Stok::class)->call('savePurchase')->assertForbidden();
    Livewire::test(Stok::class)->call('saveUsage')->assertForbidden();
    Livewire::test(Stok::class)->call('export')->assertForbidden();
});

it('posisi stok menandai Habis, Menipis, dan Aman', function () {
    expect(Stok::positionStatus(0, 5))->toBe('habis')
        ->and(Stok::positionStatus(-1, 0))->toBe('habis')
        ->and(Stok::positionStatus(5, 5))->toBe('menipis')
        ->and(Stok::positionStatus(6, 5))->toBe('aman')
        ->and(Stok::positionStatus(3, 0))->toBe('aman');
});

it('kartu stok menampilkan saldo awal, masuk, keluar, dan saldo berjalan yang cocok dengan stok', function () {
    $stock = app(StockService::class);
    $id = $this->item->id;
    $now = $stock->available($id, $this->batu->id);
    $stock->move($id, $this->batu->id, 10, 'penyesuaian', ['note' => 'Uji masuk', 'occurred_at' => now()->subDays(40)], allowNegative: true);
    $stock->move($id, $this->batu->id, 7, 'penyesuaian', ['note' => 'Uji tambah'], allowNegative: true);
    $stock->move($id, $this->batu->id, -3, 'penyesuaian', ['note' => 'Uji kurang'], allowNegative: true);
    $final = $stock->available($id, $this->batu->id);

    stokLogin('admin', 'admin', $this->batu->id);
    $card = Livewire::test(Stok::class)
        ->call('setTab', 'kartu')
        ->set('from', today()->subDays(7)->toDateString())
        ->set('to', today()->toDateString())
        ->set('cardItem', (string) $id)
        ->assertSee('Uji tambah')->assertSee('Uji kurang')->assertDontSee('Uji masuk')
        ->viewData('card');

    expect($final)->toBe($now + 14)
        ->and($card['closing'])->toBe($final)
        ->and($card['opening'] + $card['in'] - $card['out'])->toBe($final)
        ->and($card['rows']->last()['balance'])->toBe($final);
});

it('Owner melihat nilai persediaan dan bisa mengekspor posisi stok', function () {
    stokLogin('owner', 'owner', $this->batu->id);

    $this->get(route('stok', ['tab' => 'posisi']))->assertOk()->assertSee('Nilai persediaan');
    Livewire::test(Stok::class)->call('setTab', 'posisi')->call('exportPosition')->assertFileDownloaded();
    Livewire::test(Stok::class)->call('setTab', 'pembelian')->assertOk();
});

it('halaman stok hanya menampilkan tab milik menu yang dibuka (tidak dobel dengan sidebar)', function () {
    stokLogin('owner', 'owner', $this->batu->id);

    Livewire::test(Stok::class)->call('setTab', 'pembelian')
        ->assertSee("setTab('pakai')", false)->assertDontSee("setTab('posisi')", false)->assertDontSee("setTab('laporan')", false);
    Livewire::test(Stok::class)->call('setTab', 'posisi')
        ->assertDontSee("setTab('kartu')", false)->assertDontSee("setTab('pembelian')", false);
});

it('migrasi menutup izin pembelian stok Admin yang sudah tersimpan', function () {
    $matrix = Access::matrix();
    $matrix['admin']['stok'] = ['lihat' => true, 'edit' => true, 'keuangan' => false];
    Access::save($matrix);

    (require database_path('migrations/2026_11_25_000002_tutup_pembelian_stok_untuk_admin.php'))->up();
    Access::flush();

    expect(Access::can('stok', 'lihat', Role::Admin))->toBeFalse()
        ->and(Access::can('kasir', 'edit', Role::Admin))->toBeTrue();
});

it('Mutasi stok digabung ke Kartu stok: tanpa barang = semua mutasi, tab lama tetap terbuka', function () {
    app(StockService::class)->move($this->item->id, $this->batu->id, 4, 'penyesuaian', ['note' => 'Uji gabung'], allowNegative: true);
    stokLogin('admin', 'admin', $this->batu->id);

    expect(Navigation::items())->not->toHaveKey('mutasi-stok');
    Livewire::test(Stok::class)->call('setTab', 'mutasi')
        ->assertSet('tab', 'kartu')
        ->assertSee('Semua barang (daftar mutasi)')->assertSee('Uji gabung')
        ->set('cardItem', (string) $this->item->id)
        ->assertSee('Saldo awal')->assertSee('Uji gabung');
    expect(Navigation::isActive('kartu-stok', Navigation::item('kartu-stok'), 'stok', 'mutasi'))->toBeTrue();
});
