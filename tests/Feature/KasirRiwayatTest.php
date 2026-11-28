<?php

use App\Livewire\Modules\Kasir;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Access;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->branches = Branch::orderBy('id')->get();
});

function riwayatLogin(?int $branchId, string $role = 'admin'): void
{
    test()->actingAs(User::where('username', $role === 'owner' ? 'owner' : 'admin')->firstOrFail());
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branchId]);
    Access::flush();
}

/** Tagihan penjualan langsung yang sudah lunas pada waktu tertentu, lengkap dengan kwitansinya. */
function tagihanLunas(Branch $branch, string $buyer, \DateTimeInterface $paidAt, string $code): Invoice
{
    $invoice = Invoice::create([
        'branch_id' => $branch->id, 'buyer_name' => $buyer,
        'subtotal' => 50_000, 'total' => 50_000, 'paid' => 50_000,
        'status' => Invoice::STATUS_PAID, 'paid_at' => $paidAt,
    ]);
    $invoice->receipts()->create(['branch_id' => $branch->id, 'number' => (int) \App\Models\Receipt::where('branch_id', $branch->id)->max('number') + 1,'code' => $code]);

    return $invoice;
}

it('tagihan lunas kemarin tampil di riwayat dan kwitansinya bisa dicetak ulang', function () {
    $batu = $this->branches->first();
    $invoice = tagihanLunas($batu, 'Pembeli Kemarin', now()->subDay(), 'KW/UJI/0001');
    riwayatLogin($batu->id);

    Livewire::test(Kasir::class)->set('tab', 'lunas')->assertDontSee('Pembeli Kemarin');

    Livewire::test(Kasir::class)->set('tab', 'riwayat')
        ->assertSet('historyFrom', today()->subDays(30)->toDateString())
        ->assertSee('Pembeli Kemarin')
        ->assertSee('KW/UJI/0001')
        ->call('open', $invoice->id)
        ->assertSee('Cetak kwitansi KW/UJI/0001');

    $this->get(route('kasir.kwitansi', $invoice->receipts()->first()))->assertOk();
});

it('riwayat bisa dicari dengan nomor kwitansi atau nama', function () {
    $batu = $this->branches->first();
    tagihanLunas($batu, 'Budi Riwayat', now()->subDays(3), 'KW/UJI/0002');
    tagihanLunas($batu, 'Sinta Riwayat', now()->subDays(4), 'KW/UJI/0003');
    riwayatLogin($batu->id);

    Livewire::test(Kasir::class)->set('tab', 'riwayat')
        ->set('historySearch', 'KW/UJI/0003')
        ->assertSee('Sinta Riwayat')->assertDontSee('Budi Riwayat')
        ->set('historySearch', 'budi')
        ->assertSee('Budi Riwayat')->assertDontSee('Sinta Riwayat');
});

it('riwayat dibatasi rentang tanggal dan cabang aktif', function () {
    [$batu, $malang] = [$this->branches->first(), $this->branches->last()];
    tagihanLunas($batu, 'Pasien Lama Sekali', now()->subDays(60), 'KW/UJI/0004');
    tagihanLunas($malang, 'Pembeli Malang', now()->subDays(2), 'KW/UJI/0005');
    riwayatLogin($batu->id);

    $kasir = Livewire::test(Kasir::class)->set('tab', 'riwayat')
        ->assertDontSee('Pasien Lama Sekali')
        ->assertDontSee('Pembeli Malang');

    $kasir->set('historyFrom', today()->subDays(90)->toDateString())->assertSee('Pasien Lama Sekali');

    // Tagihan cabang lain tidak bisa dibuka walau ID-nya diketahui.
    $other = Invoice::where('buyer_name', 'Pembeli Malang')->firstOrFail();
    expect(fn () => $kasir->call('open', $other->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('owner di semua cabang melihat riwayat kedua cabang', function () {
    [$batu, $malang] = [$this->branches->first(), $this->branches->last()];
    tagihanLunas($batu, 'Pembeli Batu', now()->subDays(2), 'KW/UJI/0006');
    tagihanLunas($malang, 'Pembeli Malang', now()->subDays(2), 'KW/UJI/0007');
    riwayatLogin(null, 'owner');

    Livewire::test(Kasir::class)->set('tab', 'riwayat')
        ->assertSee('Pembeli Batu')->assertSee('Pembeli Malang');
});
