<?php

use App\Enums\VisitStatus;
use App\Livewire\Modules\Antrean;
use App\Models\Branch;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->actingAs(User::where('username', 'admin')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $this->batu->id]);
});

it('mengelompokkan antrean menjadi menunggu, selesai tindakan, dan tidak hadir', function () {
    expect(VisitStatus::Dipanggil->queueGroup())->toBe('menunggu')
        ->and(VisitStatus::Diperiksa->queueGroup())->toBe('menunggu')
        ->and(VisitStatus::MenungguPembayaran->queueLabel())->toBe('Selesai tindakan')
        ->and(VisitStatus::Selesai->queueLabel())->toBe('Selesai tindakan')
        ->and(VisitStatus::Batal->queueLabel())->toBe('Tidak hadir');

    Livewire::test(Antrean::class)
        ->assertSee('Selesai tindakan')
        ->assertDontSee('Panggil</button>', false)
        ->set('filter', 'selesai')
        ->assertOk()
        ->set('filter', 'tidak-dikenal') // URL lama (?status=bayar dsb.) jatuh ke Menunggu
        ->assertViewHas('tab', 'menunggu');
});

it('admin bisa menandai pasien menunggu sebagai tidak hadir', function () {
    $visit = Visit::where('branch_id', $this->batu->id)->whereDate('visit_date', today())
        ->where('status', VisitStatus::Menunggu)->firstOrFail();

    Livewire::test(Antrean::class)->call('cancel', $visit->id);

    expect($visit->fresh()->status)->toBe(VisitStatus::Batal);
});
