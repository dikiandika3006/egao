<?php

use App\Http\Middleware\EnforceIdleTimeout;
use App\Livewire\Modules\Kasir;
use App\Livewire\Modules\Pengaturan;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Settings;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
});

function settingsLogin(string $username, string $role, string|int $branch): void
{
    test()->actingAs(User::where('username', $username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

it('halaman tentang terbuka untuk semua peran', function (string $username, string $role) {
    settingsLogin($username, $role, $this->batu->id);
    $this->get(route('tentang'))->assertOk()->assertSee('Aturan sistem')->assertSee('Riwayat perubahan');
    $this->get(route('tentang', ['tab' => 'panduan']))->assertOk()->assertSee('Panduan &amp; FAQ', false);
})->with([['owner', 'owner'], ['admin', 'admin'], ['nadia', 'dokter']]);

it('tab aplikasi hanya untuk owner', function () {
    settingsLogin('admin', 'admin', $this->batu->id);
    Livewire::test(Pengaturan::class)->call('setTab', 'aplikasi')->assertForbidden();
    Livewire::test(Pengaturan::class)->call('saveApp')->assertForbidden();

    settingsLogin('owner', 'owner', 'all');
    Livewire::test(Pengaturan::class)->call('setTab', 'aplikasi')->assertOk()->assertSee('Pemeliharaan');
});

it('owner menyimpan pengaturan aplikasi', function () {
    settingsLogin('owner', 'owner', 'all');

    Livewire::test(Pengaturan::class)
        ->set('tab', 'aplikasi')
        ->set('app.receipt_prefix', 'egd')
        ->set('app.payment_methods', ['tunai', 'qris'])
        ->set('app.admin_max_discount_percent', 10)
        ->set('app.session_idle_hours', 4)
        ->call('saveApp')
        ->assertHasNoErrors();

    expect(Settings::get('receipt_prefix'))->toBe('EGD')
        ->and(array_keys(Settings::paymentMethods()))->toBe(['tunai', 'qris'])
        ->and(Settings::idleHours())->toBe(4);
});

it('kasir memakai metode aktif, batas diskon admin, dan prefix kwitansi', function () {
    Settings::set('payment_methods', 'tunai');
    Settings::set('admin_max_discount_percent', '10');
    Settings::set('receipt_prefix', 'EGD');

    settingsLogin('admin', 'admin', $this->batu->id);
    $invoice = Invoice::where('branch_id', $this->batu->id)->where('status', Invoice::STATUS_UNPAID)->firstOrFail();

    Livewire::test(Kasir::class)
        ->call('open', $invoice->id)
        ->set('pay.discount', (int) ceil($invoice->subtotal * 0.2))
        ->set('pay.discount_reason', 'Coba')
        ->call('applyDiscount')
        ->assertHasErrors('pay.discount')
        ->set('pay.discount', 0)
        ->set('pay.method', 'qris')
        ->call('submitPayment')
        ->assertHasErrors('pay.method')
        ->set('pay.method', 'tunai')
        ->call('submitPayment')
        ->assertHasNoErrors();

    expect($invoice->receipts()->first()->code)->toStartWith('EGD/BTU/');
});

it('keluar otomatis setelah melewati batas idle', function () {
    Settings::set('session_idle_hours', '1');
    $user = User::where('username', 'admin')->first();

    $this->actingAs($user)
        ->withSession([
            BranchContext::SESSION_ROLE => 'admin',
            BranchContext::SESSION_BRANCH => $this->batu->id,
            EnforceIdleTimeout::KEY => time() - 7200,
        ])
        ->get('/')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('owner mengatur bagi hasil per dokter, per tindakan, dan khusus dokter × tindakan', function () {
    settingsLogin('owner', 'owner', 'all');
    $nadia = App\Models\Doctor::where('name', 'drg. Nadia Putri')->first();
    $scaling = App\Models\Procedure::where('name', 'Scaling')->first();
    $tambal = App\Models\Procedure::where('name', 'Tambal gigi')->first();

    Livewire::test(Pengaturan::class)
        ->call('setTab', 'bagi-hasil')
        ->assertSee('Tarif khusus dokter')
        ->set("shares.doctors.{$nadia->id}.share_percent", '30')
        ->set("shares.doctors.{$nadia->id}.base_salary", 750000)
        ->set("shares.procedures.{$tambal->id}", '25,5')
        ->call('saveShares')
        ->assertHasNoErrors()
        ->set('special.doctor_id', $nadia->id)
        ->set('special.procedure_id', $scaling->id)
        ->set('special.share_percent', '40')
        ->call('addSpecial')
        ->assertHasNoErrors();

    expect((float) $nadia->fresh()->share_percent)->toBe(30.0)
        ->and($nadia->fresh()->base_salary)->toBe(750000)
        ->and((float) $tambal->fresh()->share_percent)->toBe(25.5);

    // Prioritas: khusus (40%) untuk Scaling Nadia, tarif tindakan (25,5%) untuk Tambal, tarif dokter (30%) lainnya.
    $row = App\Support\Payroll::forMonth($nadia->clinic_id, now())->firstWhere('doctor.id', $nadia->id);
    $byName = $row['procedures']->keyBy('name');
    if ($byName->has('Scaling')) {
        expect((float) $byName['Scaling']['percent'])->toBe(40.0);
    }
    if ($byName->has('Tambal gigi')) {
        expect((float) $byName['Tambal gigi']['percent'])->toBe(25.5);
    }
    if ($byName->has('Konsultasi')) {
        expect((float) $byName['Konsultasi']['percent'])->toBe(30.0);
    }
});

it('admin tidak melihat tab bagi hasil', function () {
    settingsLogin('admin', 'admin', $this->batu->id);
    Livewire::test(Pengaturan::class)->call('setTab', 'bagi-hasil')->assertForbidden();
});
