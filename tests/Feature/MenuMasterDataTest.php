<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Navigation;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
});

function masukSebagaiPeran(string $username, string $role, string|int $branch)
{
    return test()->actingAs(User::where('username', $username)->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => $role,
        BranchContext::SESSION_BRANCH => $branch,
    ]);
}

it('mengelompokkan data master dalam satu grup', function () {
    expect(array_keys(Navigation::visible(Role::Owner, 'master')))
        ->toBe(['pasien', 'dokter', 'master-barang', 'tindakan', 'kategori-pengeluaran', 'tarif-gaji'])
        ->and(array_keys(Navigation::visible(Role::Admin, 'master')))
        ->toBe(['pasien', 'dokter', 'master-barang', 'tindakan', 'kategori-pengeluaran'])
        ->and(array_keys(Navigation::visible(Role::Dokter, 'master')))->toBe(['pasien'])
        ->and(Navigation::visible(Role::Owner, 'klinik'))->not->toHaveKeys(['pasien', 'dokter']);
});

it('pintasan master membuka tab modul induk dan tidak punya route sendiri', function () {
    expect(Navigation::url(Navigation::item('master-barang')))->toBe(route('stok', ['tab' => 'barang']))
        ->and(Navigation::url(Navigation::item('tindakan')))->toBe(route('pengaturan', ['tab' => 'tindakan']))
        ->and(Navigation::url(Navigation::item('kategori-pengeluaran')))->toBe(route('pengaturan', ['tab' => 'kategori']))
        ->and(Navigation::url(Navigation::item('tarif-gaji')))->toBe(route('pengaturan', ['tab' => 'bagi-hasil']))
        ->and(Navigation::modules())->not->toHaveKeys(['master-barang', 'tindakan', 'kategori-pengeluaran', 'tarif-gaji']);
});

it('menyorot pintasan sesuai tab yang terbuka', function () {
    $stok = Navigation::item('stok');
    $barang = Navigation::item('master-barang');

    expect(Navigation::isActive('master-barang', $barang, 'stok', 'barang'))->toBeTrue()
        ->and(Navigation::isActive('stok', $stok, 'stok', 'barang'))->toBeFalse()
        ->and(Navigation::isActive('stok', $stok, 'stok', 'pembelian'))->toBeTrue()
        ->and(Navigation::isActive('master-barang', $barang, 'stok', 'pembelian'))->toBeFalse()
        ->and(Navigation::isActive('pasien', Navigation::item('pasien'), 'pasien'))->toBeTrue();
});

it('sidebar menampilkan grup Master data sesuai peran', function () {
    masukSebagaiPeran('admin', 'admin', $this->batu->id)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Ruang klinik', 'Master data', 'Data pasien', 'Data dokter', 'Master barang', 'Tindakan &amp; tarif', 'Kategori pengeluaran', 'Manajemen'], false)
        ->assertSee(route('stok', ['tab' => 'barang']), false);

    masukSebagaiPeran('nadia', 'dokter', $this->batu->id)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Master data')
        ->assertDontSee('Master barang')
        ->assertDontSee('Kategori pengeluaran');
});

it('hak akses data pasien dan dokter tidak berubah', function () {
    masukSebagaiPeran('nadia', 'dokter', $this->batu->id);

    $this->get(route('pasien'))->assertOk();
    $this->get(route('dokter'))->assertForbidden();
    $this->get(route('stok', ['tab' => 'barang']))->assertForbidden();
});

it('menu Lainnya di ponsel ikut dikelompokkan', function () {
    masukSebagaiPeran('owner', 'owner', 'all')
        ->get(route('lainnya'))
        ->assertOk()
        ->assertSeeInOrder(['Master data', 'Data pasien', 'Master barang', 'Manajemen'])
        ->assertSee(route('pengaturan', ['tab' => 'tindakan']), false);
});

it('tab Tindakan & tarif tidak dobel di Pengaturan tetapi tetap bisa dibuka dari Master data', function () {
    masukSebagaiPeran('owner', 'owner', 'all');

    $this->get(route('pengaturan'))->assertOk()
        ->assertSee('Klinik &amp; cabang', false)
        ->assertDontSee("setTab('tindakan')", false);

    $this->get(route('pengaturan', ['tab' => 'tindakan']))->assertOk()
        ->assertSee('Impor Excel')
        ->assertDontSee("setTab('tindakan')", false);
});

it('Pengaturan hanya berisi pengaturan program; tab data master tidak tampil di deretan tab', function () {
    masukSebagaiPeran('owner', 'owner', 'all');

    $this->get(route('pengaturan'))->assertOk()
        ->assertSee("setTab('klinik')", false)
        ->assertSee("setTab('pengguna')", false)
        ->assertDontSee("setTab('bagi-hasil')", false)
        ->assertDontSee("setTab('kategori')", false);

    $this->get(route('pengaturan', ['tab' => 'bagi-hasil']))->assertOk()
        ->assertSee('Tarif gaji &amp; bagi hasil', false)
        ->assertSee('wire:submit="saveShares"', false)
        ->assertDontSee("setTab('klinik')", false);
});

it('menu Pengaturan disembunyikan untuk Admin, data master tetap bisa dibuka', function () {
    expect(Navigation::visible(Role::Admin, 'manajemen'))->not->toHaveKey('pengaturan')
        ->and(Navigation::visible(Role::Owner, 'manajemen'))->toHaveKey('pengaturan')
        ->and(Navigation::visible(Role::Admin, 'master'))->not->toHaveKey('tarif-gaji');

    masukSebagaiPeran('admin', 'admin', $this->batu->id);

    $this->get(route('pengaturan'))->assertOk()->assertSee('Impor Excel');
    $this->get(route('pengaturan', ['tab' => 'kategori']))->assertOk()->assertSee('Kategori pengeluaran');
    $this->get(route('pengaturan', ['tab' => 'bagi-hasil']))->assertOk()->assertDontSee('wire:submit="saveShares"', false);
});
