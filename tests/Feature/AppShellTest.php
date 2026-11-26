<?php

use App\Enums\VisitStatus;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\SelectBranch;
use App\Livewire\Dashboard;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', 'MLG')->first();
});

function actingWithBranch(string $username, string $role, string|int $branch)
{
    $user = User::where('username', $username)->firstOrFail();

    return test()->actingAs($user)->withSession([
        BranchContext::SESSION_ROLE => $role,
        BranchContext::SESSION_BRANCH => $branch,
    ]);
}

it('mengarahkan tamu ke halaman masuk', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('masuk dengan username dan kata sandi', function () {
    Livewire::test(Login::class)
        ->set('username', 'owner')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(route('select-branch'));

    $this->assertAuthenticated();
});

it('menolak kata sandi salah', function () {
    Livewire::test(Login::class)
        ->set('username', 'owner')
        ->set('password', 'salah')
        ->call('login')
        ->assertHasErrors('username');

    $this->assertGuest();
});

it('mewajibkan pilih cabang sebelum masuk aplikasi', function () {
    $this->actingAs(User::where('username', 'owner')->first())
        ->get('/')
        ->assertRedirect(route('select-branch'));
});

it('memilih cabang otomatis bila hanya satu peran dan satu cabang', function () {
    $this->actingAs(User::where('username', 'admin')->first());

    Livewire::test(SelectBranch::class)->assertRedirect(route('dashboard'));

    expect(session(BranchContext::SESSION_BRANCH))->toBe($this->batu->id);
});

it('owner langsung masuk ke semua cabang tanpa memilih', function () {
    $this->actingAs(User::where('username', 'owner')->first());

    Livewire::test(SelectBranch::class)->assertRedirect(route('dashboard'));

    expect(session(BranchContext::SESSION_BRANCH))->toBe(BranchContext::ALL)
        ->and(session(BranchContext::SESSION_ROLE))->toBe('owner');
});

it('dokter tidak diarahkan ke halaman khusus owner yang teringat dari sesi sebelumnya', function () {
    $this->actingAs(User::where('username', 'nadia')->first())
        ->withSession(['url.intended' => route('dokter')]);

    Livewire::test(SelectBranch::class)
        ->set('role', 'dokter')
        ->set('branch', (string) $this->batu->id)
        ->call('choose')
        ->assertRedirect(route('dashboard'));
});

it('tetap kembali ke halaman yang teringat bila peran boleh membukanya', function () {
    $this->actingAs(User::where('username', 'nadia')->first())
        ->withSession(['url.intended' => route('pasien')]);

    Livewire::test(SelectBranch::class)
        ->set('role', 'dokter')
        ->set('branch', (string) $this->batu->id)
        ->call('choose')
        ->assertRedirect(route('pasien'));
});

it('hanya owner yang boleh memilih semua cabang', function () {
    $this->actingAs(User::where('username', 'owner')->first())
        ->withSession([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => BranchContext::ALL]);

    Livewire::test(SelectBranch::class)
        ->set('role', 'admin')
        ->set('branch', 'all')
        ->call('choose')
        ->assertForbidden();

    Livewire::test(SelectBranch::class)
        ->set('role', 'owner')
        ->set('branch', 'all')
        ->call('choose')
        ->assertRedirect(route('dashboard'));
});

it('menampilkan ringkasan dan antrean cabang aktif saja', function () {
    actingWithBranch('admin', 'admin', $this->batu->id)
        ->get('/')
        ->assertOk()
        ->assertSee('Antrean hari ini')
        ->assertSee('Rina Kusuma')
        ->assertSee('RM-00128')
        ->assertSee('Dokter bertugas')
        ->assertDontSee('Omzet 7 hari'); // khusus owner
});

it('menampilkan ringkasan owner untuk semua cabang', function () {
    actingWithBranch('owner', 'owner', 'all')
        ->get('/')
        ->assertOk()
        ->assertSee('Omzet 7 hari')
        ->assertSee('Per cabang')
        ->assertSee('Batu')
        ->assertSee('Malang');
});

it('memanggil pasien dari antrean', function () {
    $visit = Visit::where('branch_id', $this->batu->id)
        ->where('status', VisitStatus::Menunggu)
        ->whereDate('visit_date', today())
        ->first();

    actingWithBranch('admin', 'admin', $this->batu->id);

    Livewire::test(Dashboard::class)->call('callPatient', $visit->id);

    expect($visit->fresh()->status)->toBe(VisitStatus::Dipanggil);
});

it('tidak bisa memanggil antrean cabang lain', function () {
    $visit = Visit::where('branch_id', $this->malang->id)
        ->where('status', VisitStatus::Menunggu)
        ->whereDate('visit_date', today())
        ->firstOrFail();

    actingWithBranch('admin', 'admin', $this->batu->id);

    expect(fn () => Livewire::test(Dashboard::class)->call('callPatient', $visit->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('membatasi menu sesuai peran', function () {
    actingWithBranch('nadia', 'dokter', $this->batu->id);

    $this->get(route('rekam-medis'))->assertOk();
    $this->get(route('kasir'))->assertForbidden();
    $this->get(route('gaji'))->assertForbidden();
});

it('semua menu terbuka untuk owner', function (string $route) {
    actingWithBranch('owner', 'owner', 'all')->get(route($route))->assertOk();
})->with(['antrean', 'pasien', 'rekam-medis', 'kasir', 'jadwal', 'laporan', 'pengeluaran', 'gaji', 'pengaturan', 'lainnya']);

it('pasien tidak memiliki cabang dan nomor telepon disamarkan', function () {
    $rina = Patient::where('medical_record_number', 128)->first();

    expect(Schema::hasColumn('patients', 'branch_id'))->toBeFalse()
        ->and($rina->maskedPhone())->toBe('0812 •••• 2741')
        ->and($rina->hasAllergy())->toBeTrue();
});

it('jadwal pindah cabang sementara tidak mengubah jadwal rutin', function () {
    $sinta = Doctor::where('name', 'drg. Sinta Dewi')->with('schedules', 'overrides')->first();

    $override = $sinta->scheduleOn(Carbon::parse('2026-11-18'));      // Rabu, pindah ke Batu
    $routine = $sinta->scheduleOn(Carbon::parse('2026-11-11'));       // Rabu biasa, Malang

    expect($override['branch_id'])->toBe($this->batu->id)
        ->and($override['moved'])->toBeTrue()
        ->and($routine['branch_id'])->toBe($this->malang->id)
        ->and($sinta->scheduleOn(Carbon::parse('2026-11-09')))->toBeNull(); // Senin libur
});
