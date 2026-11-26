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
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
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

it('hanya 3 peran; bawaan Dokter mengikuti Navigation', function () {
    expect(array_map(fn ($r) => $r->value, Role::cases()))->toBe(['owner', 'admin', 'dokter']);

    foreach (Access::menus() as $key => $item) {
        $old = in_array(Role::Dokter, $item['roles'], true);
        expect(Access::can($key, 'lihat', Role::Dokter))->toBe($key === 'dashboard' ? true : $old, "$key dokter");
        expect(Access::can($key, 'edit', Role::Dokter))->toBe($old, "$key edit dokter");
    }

    expect(Access::can('gaji', 'lihat', Role::Owner))->toBeTrue()
        ->and(Access::can('absensi', 'lihat', Role::Owner))->toBeTrue()
        ->and(Access::can('pengaturan', 'lihat', Role::Admin))->toBeFalse()
        ->and(Access::can('tentang', 'lihat', Role::Admin))->toBeTrue();
});

it('peran Admin / Asisten: menu operasional saja', function () {
    foreach (['dashboard', 'antrean', 'janji', 'pasien', 'kasir', 'jadwal', 'absensi', 'lab'] as $menu) {
        expect(Access::can($menu, 'edit', Role::Admin))->toBeTrue($menu);
    }
    // Stok: hanya melihat posisi & kartu stok tanpa harga; pembelian/opname & laporan jual-beli tertutup.
    foreach (['posisi-stok', 'kartu-stok'] as $menu) {
        expect(Access::can($menu, 'lihat', Role::Admin))->toBeTrue($menu)
            ->and(Access::can($menu, 'edit', Role::Admin))->toBeFalse($menu)
            ->and(Access::can($menu, 'keuangan', Role::Admin))->toBeFalse($menu);
    }
    expect(Access::can('stok', 'lihat', Role::Admin))->toBeFalse()
        ->and(Access::can('laporan-stok', 'lihat', Role::Admin))->toBeFalse();
    // Rekam medis hanya baca: yang memeriksa dokter.
    expect(Access::can('rekam-medis', 'lihat', Role::Admin))->toBeTrue()
        ->and(Access::can('rekam-medis', 'edit', Role::Admin))->toBeFalse();
    foreach (['laporan', 'pengeluaran', 'gaji', 'tarif-gaji', 'dokter', 'master-barang', 'tindakan', 'kategori-pengeluaran'] as $menu) {
        expect(Access::can($menu, 'lihat', Role::Admin))->toBeFalse($menu);
    }
    expect(Access::can('dashboard', 'keuangan', Role::Admin))->toBeTrue(); // omzet hari ini tampil untuk totalan kas
});

it('akun Admin membuka antrean & kasir, tetapi tidak Laporan, Pengeluaran, atau Master data', function () {
    aksesLogin('admin', 'admin', $this->batu->id);

    $this->get(route('antrean'))->assertOk();
    $this->get(route('kasir'))->assertOk();
    $this->get(route('laporan'))->assertForbidden();
    $this->get(route('pengeluaran'))->assertForbidden();
    $this->get(route('dokter'))->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('href="'.route('laporan').'"', false);
});

it('migrasi menggabungkan peran asisten ke admin', function () {
    $user = User::create([
        'clinic_id' => Clinic::firstOrFail()->id, 'name' => 'Asisten Lama', 'username' => 'asisten.lama',
        'password' => 'rahasia123', 'roles' => ['admin'], 'is_active' => true,
    ]);
    DB::table('users')->where('id', $user->id)->update(['roles' => json_encode(['asisten', 'admin'])]);
    Settings::set(Access::KEY, json_encode(['asisten' => ['antrean' => ['lihat' => true]], 'dokter' => []]));

    (require database_path('migrations/2026_11_25_000001_gabung_peran_asisten_ke_admin.php'))->up();

    expect($user->fresh()->roles)->toBe(['admin'])
        ->and(json_decode(Settings::get(Access::KEY), true))->toBe(['dokter' => []]);
});

it('menu yang dimatikan hilang dari sidebar dan halamannya ditolak; Owner bisa membuka menu tambahan', function () {
    ubahAkses('admin', 'kasir', ['lihat' => false]);
    ubahAkses('admin', 'laporan', ['lihat' => true]);

    aksesLogin('admin', 'admin', $this->batu->id);

    expect(Navigation::visible(Role::Admin))->not->toHaveKey('kasir')->toHaveKey('laporan');
    $this->get(route('kasir'))->assertForbidden();
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
    ubahAkses('admin', 'master-barang', ['lihat' => true, 'edit' => true, 'keuangan' => true]);
    aksesLogin('admin', 'admin', $this->batu->id);
    $this->get(route('stok', ['tab' => 'barang']))->assertOk()->assertDontSee('Rp•••');

    ubahAkses('admin', 'master-barang', ['keuangan' => false]);
    $this->get(route('stok', ['tab' => 'barang']))->assertOk()->assertSee('Rp•••');
});

it('pintasan Master data tetap membuka halaman induk walau menu induk dimatikan', function () {
    ubahAkses('admin', 'master-barang', ['lihat' => true]);
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
