<?php

use App\Livewire\Modules\Tentang;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Faq;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->actingAs(User::where('username', 'owner')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'owner',
        BranchContext::SESSION_BRANCH => 'all',
    ]);
});

it('mencari panduan gaji pokok', function () {
    $hits = collect(Faq::search('gaji pokok'))->pluck('q');

    expect($hits)->toContain('Di mana mengatur gaji pokok dokter?')
        ->and($hits)->toContain('Di mana mengatur gaji karyawan (admin, perawat)?')
        ->and($hits)->not->toContain('Bagaimana memberi diskon?');
});

it('tanpa kata kunci menampilkan semua panduan', function () {
    expect(Faq::search('  '))->toHaveCount(count(Faq::ITEMS));
});

it('halaman tentang menampilkan dan menyaring FAQ', function () {
    Livewire::test(Tentang::class)
        ->call('setTab', 'panduan')
        ->assertSee('Panduan & FAQ')
        ->set('search', 'gaji pokok')
        ->assertSee('Pengaturan › Bagi hasil')
        ->assertDontSee('Bagaimana memberi diskon?')
        ->set('search', 'zzzxxy')
        ->assertSee('Belum ada panduan');
});

it('memisahkan FAQ dan info aplikasi di tab berbeda', function () {
    Livewire::test(Tentang::class)
        ->assertSet('tab', 'aplikasi')
        ->assertSee('Aturan sistem')
        ->assertSee('Riwayat perubahan')
        ->assertDontSee('Cari panduan')
        ->call('setTab', 'panduan')
        ->assertSee('Panduan &amp; FAQ', false)
        ->assertDontSee('Riwayat perubahan');
});

it('tautan pencarian langsung membuka tab panduan', function () {
    Livewire::withQueryParams(['cari' => 'gaji'])
        ->test(Tentang::class)
        ->assertSet('tab', 'panduan')
        ->assertSee('Di mana mengatur gaji pokok dokter?');
});
