<?php

use App\Enums\Role;
use App\Livewire\Modules\Pengaturan;
use App\Livewire\Modules\Pengeluaran;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Navigation;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
});

function aksesLogin(string $username, string $role, string|int $branch)
{
    test()->actingAs(User::where('username', $username)->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => $role,
        BranchContext::SESSION_BRANCH => $branch,
    ]);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
    Access::flush();

    return test();
}

/** Matriks bawaan dengan satu izin diubah, lalu disimpan sebagai Owner. */
function ubahAkses(string $role, string $menu, array $perm): void
{
    $matrix = Access::matrix();
    $matrix[$role][$menu] = $perm + $matrix[$role][$menu];
    Access::save($matrix);
}

it('bawaan sama dengan hak akses lama per peran', function () {
    foreach ([Role::Admin, Role::Dokter] as $role) {
        foreach (Access::menus() as $key => $item) {
            $old = in_array($role, $item['roles'], true);
            expect(Access::can($key, 'lihat', $role))->toBe($key === 'dashboard' ? true : $old, "$key {$role->value}");
            expect(Access::can($key, 'edit', $role))->toBe($old, "$key edit {$role->value}");
        }
    }

    expect(Access::can('gaji', 'lihat', Role::Owner))->toBeTrue()
        ->and(Access::can('pengaturan', 'lihat', Role::Admin))->toBeFalse()
        ->and(Access::can('tentang', 'lihat', Role::Asisten))->toBeTrue();
});

it('peran Asisten punya bawaan sendiri', function () {
    expect(Access::can('antrean', 'edit', Role::Asisten))->toBeTrue()
        ->and(Access::can('pasien', 'lihat', Role::Asisten))->toBeTrue()
        ->and(Access::can('pasien', 'edit', Role::Asisten))->toBeFalse()
        ->and(Access::can('kasir', 'lihat', Role::Asisten))->toBeFalse()
        ->and(Access::can('dashboard', 'keuangan', Role::Asisten))->toBeFalse();
});

it('akun Asisten bisa login, membuka antrean, tetapi tidak membuka kasir', function () {
    $user = User::create([
        'clinic_id' => Clinic::firstOrFail()->id, 'name' => 'Asisten Batu', 'username' => 'asisten.batu',
        'password' => 'rahasia123', 'roles' => [Role::Asisten->value], 'is_active' => true,
    ]);
    $user->branches()->attach($this->batu->id);

    aksesLogin('asisten.batu', 'asisten', $this->batu->id);

    $this->get(route('antrean'))->assertOk();
    $this->get(route('kasir'))->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('href="'.route('kasir').'"', false);
});

it('menu yang dimatikan hilang dari sidebar dan halamannya ditolak', function () {
    ubahAkses('admin', 'pengeluaran', ['lihat' => false]);

    aksesLogin('admin', 'admin', $this->batu->id);

    expect(Navigation::visible(Role::Admin))->not->toHaveKey('pengeluaran');
    $this->get(route('pengeluaran'))->assertForbidden();
    $this->get(route('laporan'))->assertOk();
});

it('tanpa izin Edit aksi tulis ditolak, aksi lihat tetap jalan', function () {
    ubahAkses('admin', 'pengeluaran', ['lihat' => true, 'edit' => false]);
    aksesLogin('admin', 'admin', $this->batu->id);

    Livewire::test(Pengeluaran::class)
        ->assertDontSee('Catat pengeluaran')
        ->call('shiftMonth', -1)->assertOk()
        ->call('create')->assertForbidden();
});

it('tanpa izin Keuangan angka rupiah disamarkan', function () {
    aksesLogin('admin', 'admin', $this->batu->id);
    $this->get(route('stok', ['tab' => 'barang']))->assertOk()->assertDontSee('Rp•••');

    ubahAkses('admin', 'master-barang', ['keuangan' => false]);
    $this->get(route('stok', ['tab' => 'barang']))->assertOk()->assertSee('Rp•••');
});

it('pintasan Master data tetap membuka halaman induk walau menu induk dimatikan', function () {
    ubahAkses('admin', 'stok', ['lihat' => false]);
    aksesLogin('admin', 'admin', $this->batu->id);

    $this->get(route('stok', ['tab' => 'barang']))->assertOk();
    $this->get(route('stok', ['tab' => 'pembelian']))->assertOk()->assertDontSee('wire:click="setTab(\'pembelian\')"', false);
});

it('aksi pembelian stok mengikuti izin menu Stok, bukan tab yang sedang dibuka', function () {
    ubahAkses('admin', 'stok', ['lihat' => true, 'edit' => false]);
    aksesLogin('admin', 'admin', $this->batu->id);

    Livewire::test(\App\Livewire\Modules\Stok::class)
        ->set('tab', 'barang')
        ->call('savePurchase')->assertForbidden();
});

it('owner mengatur hak akses dari Pengaturan; peran lain ditolak', function () {
    aksesLogin('owner', 'owner', 'all');
    Livewire::test(Pengaturan::class)
        ->call('setTab', 'hak-akses')
        ->assertSee('Hak akses menu per peran')
        ->set('access.dokter.jadwal.lihat', false)
        ->call('saveAccess')->assertOk();

    Access::flush();
    expect(Access::can('jadwal', 'lihat', Role::Dokter))->toBeFalse()
        ->and(Access::can('jadwal', 'edit', Role::Dokter))->toBeFalse();

    Livewire::test(Pengaturan::class)->call('resetAccess')->assertOk();
    Access::flush();
    expect(Access::can('jadwal', 'lihat', Role::Dokter))->toBeTrue();

    aksesLogin('admin', 'admin', $this->batu->id);
    Livewire::test(Pengaturan::class)->call('saveAccess')->assertForbidden();
    Livewire::test(Pengaturan::class)->call('setTab', 'hak-akses')->assertForbidden();
});

it('aksi baca di READ_ACTIONS tidak memuat aksi tulis', function () {
    $components = [
        \App\Livewire\Modules\Antrean::class, \App\Livewire\Modules\Pasien::class, \App\Livewire\Modules\JanjiTemu::class,
        \App\Livewire\Modules\Jadwal::class, \App\Livewire\Modules\Dokter::class, \App\Livewire\Modules\Kasir::class,
        \App\Livewire\Modules\Stok::class, \App\Livewire\Modules\OrderLab::class, Pengeluaran::class,
        \App\Livewire\Modules\Gaji::class, \App\Livewire\Modules\Laporan::class, Pengaturan::class,
        \App\Livewire\MedicalRecords\Exam::class,
    ];

    foreach ($components as $class) {
        expect(defined($class.'::MENU'))->toBeTrue($class);
        foreach ($class::READ_ACTIONS as $method) {
            expect(method_exists($class, $method))->toBeTrue("$class::$method tidak ada")
                ->and($method)->not->toMatch('/^(save|store|delete|remove|add|post|pay|submit|import|mark|unmark|confirm|create|apply|assign|send|run|reset)/', "$class::$method");
        }
    }
});
