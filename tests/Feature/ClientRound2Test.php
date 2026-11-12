<?php

/**
 * Penyesuaian dari jawaban klien (12 Nov 2026):
 *  - Janji temu: tolak janji ganda, panel ketersediaan dokter, tombol Kirim WA, penanda dokter tidak bertugas.
 *  - Jadwal: dokter izin & digantikan dalam satu langkah.
 *  - Kasir: penjualan langsung tanpa kunjungan (pasien rujukan / pembeli umum).
 */

use App\Livewire\Modules\Jadwal;
use App\Livewire\Modules\JanjiTemu;
use App\Livewire\Modules\Kasir;
use App\Livewire\Modules\Laporan;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Patient;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Services\StockService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', 'MLG')->first();
});

function round2Login(string $username, string $role, string|int $branch): User
{
    $user = User::where('username', $username)->firstOrFail();
    test()->actingAs($user);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);

    return $user;
}

/** Tanggal berikutnya (≥ besok) saat dokter terjadwal di cabang tsb. */
function nextDutyDate(Doctor $doctor, Branch $branch): Carbon
{
    $doctor->load('schedules', 'overrides');
    for ($d = today()->addDay(); ; $d->addDay()) {
        if (($doctor->scheduleOn($d)['branch_id'] ?? null) === $branch->id) {
            return $d->copy();
        }
    }
}

// ── Janji temu ──────────────────────────────────────────────────

it('menolak janji ganda untuk dokter yang sama di jam berdekatan', function () {
    round2Login('admin', 'admin', $this->batu->id);
    $nadia = Doctor::where('name', 'drg. Nadia Putri')->first();
    $date = nextDutyDate($nadia, $this->batu)->toDateString();
    [$p1, $p2] = Patient::limit(2)->get();

    $make = fn (Patient $p, string $time) => Livewire::test(JanjiTemu::class)
        ->call('create')->call('pickPatient', $p->id)
        ->set('form.date', $date)->set('form.time', $time)->set('form.doctor_id', (string) $nadia->id)
        ->call('save');

    $make($p1, '13:00')->assertHasNoErrors();
    $make($p2, '13:15')->assertHasErrors('form.time');
    $make($p2, '13:30')->assertHasNoErrors();
});

it('menampilkan dokter bertugas dan jam terisi di form janji', function () {
    round2Login('admin', 'admin', $this->batu->id);
    $nadia = Doctor::where('name', 'drg. Nadia Putri')->first();
    $date = nextDutyDate($nadia, $this->batu);
    Appointment::create([
        'clinic_id' => $nadia->clinic_id, 'patient_id' => Patient::first()->id, 'branch_id' => $this->batu->id,
        'doctor_id' => $nadia->id, 'date' => $date->toDateString(), 'time' => '14:00',
    ]);

    Livewire::test(JanjiTemu::class)
        ->call('create')
        ->set('form.date', $date->toDateString())
        ->assertSee('Dokter bertugas')
        ->assertSee($nadia->name)
        ->assertSee('14.00 terisi');
});

it('membuat link WhatsApp konfirmasi dengan nomor 62', function () {
    $a = Appointment::with(['patient', 'doctor', 'branch'])->whereNotNull('time')->firstOrFail();
    $a->patient->phone = '0812-3456-2741';

    $url = $a->whatsappUrl();

    expect($url)->toStartWith('https://wa.me/6281234562741?text=')
        ->and(rawurldecode($url))->toContain($a->patient->name)->toContain('Mohon balas YA');
});

it('menandai janji yang dokternya tidak bertugas hari itu', function () {
    round2Login('admin', 'admin', $this->batu->id);
    $nadia = Doctor::where('name', 'drg. Nadia Putri')->first();
    $date = nextDutyDate($nadia, $this->batu);
    Appointment::create([
        'clinic_id' => $nadia->clinic_id, 'patient_id' => Patient::first()->id, 'branch_id' => $this->batu->id,
        'doctor_id' => $nadia->id, 'date' => $date->toDateString(), 'time' => '15:00',
    ]);
    ScheduleOverride::create(['doctor_id' => $nadia->id, 'date' => $date->toDateString(), 'type' => ScheduleOverride::TYPE_OFF]);

    Livewire::test(JanjiTemu::class)->set('date', $date->toDateString())->assertSee('tidak bertugas di cabang ini');
});

// ── Jadwal: izin & digantikan ───────────────────────────────────

it('dokter izin langsung digantikan dan janji pasien ikut dipindah', function () {
    round2Login('owner', 'owner', 'all');
    $nadia = Doctor::where('name', 'drg. Nadia Putri')->first();
    $date = nextDutyDate($nadia, $this->batu);

    // Pengganti: dokter yang tidak bertugas di cabang lain pada tanggal itu.
    $sub = Doctor::where('clinic_id', $nadia->clinic_id)->whereKeyNot($nadia->id)->with('schedules', 'overrides')->get()
        ->first(fn (Doctor $d) => ! ($s = $d->scheduleOn($date)) || $s['branch_id'] === $this->batu->id);
    expect($sub)->not->toBeNull();

    $appt = Appointment::create([
        'clinic_id' => $nadia->clinic_id, 'patient_id' => Patient::first()->id, 'branch_id' => $this->batu->id,
        'doctor_id' => $nadia->id, 'date' => $date->toDateString(), 'time' => '10:30',
    ]);

    Livewire::test(Jadwal::class)
        ->call('openDay', $nadia->id, $date->toDateString())
        ->set('override.type', 'off')
        ->set('override.substitute_id', (string) $sub->id)
        ->set('override.move_appointments', true)
        ->set('override.note', 'Sakit')
        ->call('saveOverride')
        ->assertHasNoErrors();

    $nadia->refresh()->load('schedules', 'overrides');
    $sub->refresh()->load('schedules', 'overrides');

    expect($nadia->scheduleOn($date))->toBeNull()
        ->and($sub->scheduleOn($date)['branch_id'])->toBe($this->batu->id)
        ->and($appt->fresh()->doctor_id)->toBe($sub->id);
});

it('menolak pengganti yang sedang bertugas di cabang lain', function () {
    round2Login('owner', 'owner', 'all');
    $nadia = Doctor::where('name', 'drg. Nadia Putri')->first();
    $date = nextDutyDate($nadia, $this->batu);
    $busy = Doctor::whereKeyNot($nadia->id)->with('schedules', 'overrides')->get()
        ->first(fn (Doctor $d) => ($d->scheduleOn($date)['branch_id'] ?? null) === $this->malang->id);

    if (! $busy) {
        $this->markTestSkipped('Tidak ada dokter di Malang pada tanggal itu.');
    }

    Livewire::test(Jadwal::class)
        ->call('openDay', $nadia->id, $date->toDateString())
        ->set('override.type', 'off')
        ->set('override.substitute_id', (string) $busy->id)
        ->call('saveOverride')
        ->assertHasErrors('override.substitute_id');
});

// ── Kasir: penjualan langsung ───────────────────────────────────

it('penjualan langsung ke pembeli rujukan: tagihan, stok, kwitansi, laporan', function () {
    round2Login('admin', 'admin', $this->batu->id);
    $floss = Item::where('code', 'SKG')->first();
    $stock = app(StockService::class);
    $before = $stock->available($floss->id, $this->batu->id);

    $kasir = Livewire::test(Kasir::class)
        ->call('openDirectSale')
        ->call('createDirectSale')
        ->assertHasErrors('direct.buyer_name')
        ->set('direct.buyer_name', 'drg. Rina (rujukan)')
        ->call('createDirectSale')
        ->assertHasNoErrors();

    $invoice = Invoice::whereNull('visit_id')->where('buyer_name', 'drg. Rina (rujukan)')->firstOrFail();
    expect($invoice->branch_id)->toBe($this->batu->id)->and($invoice->payerName())->toBe('drg. Rina (rujukan)');

    $kasir->set('goods.item_id', (string) $floss->id)->set('goods.quantity', 3)->call('addGoods')->assertHasNoErrors()
        ->assertSee('Penjualan langsung');

    $invoice->refresh();
    expect($invoice->total)->toBe(60000);

    $kasir->set('pay.amount', 60000)->call('submitPayment')->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(Invoice::STATUS_PAID)
        ->and($stock->available($floss->id, $this->batu->id))->toBe($before - 3)
        ->and($invoice->receipts()->count())->toBe(1);

    $this->get(route('kasir.kwitansi', $invoice->receipts()->first()))->assertOk()->assertHeader('content-type', 'application/pdf');

    Livewire::test(Laporan::class)->call('preset', 'hari')->call('setTab', 'pembayaran')->assertSee('drg. Rina (rujukan)');
});

it('penjualan langsung bisa untuk pasien terdaftar dan yang kosong bisa dihapus', function () {
    round2Login('admin', 'admin', $this->batu->id);
    $patient = Patient::where('medical_record_number', 128)->first();

    $kasir = Livewire::test(Kasir::class)
        ->call('openDirectSale')
        ->set('direct.patient_id', $patient->id)
        ->call('createDirectSale')
        ->assertHasNoErrors()
        ->assertSee($patient->name);

    $invoice = Invoice::whereNull('visit_id')->where('patient_id', $patient->id)->firstOrFail();

    $kasir->call('discardDirectSale');
    expect(Invoice::find($invoice->id))->toBeNull();
});

it('tagihan pemeriksaan tidak bisa dihapus lewat penjualan langsung', function () {
    round2Login('admin', 'admin', $this->batu->id);
    $invoice = Invoice::whereNotNull('visit_id')->where('branch_id', $this->batu->id)->where('status', Invoice::STATUS_UNPAID)->firstOrFail();

    Livewire::test(Kasir::class)->call('open', $invoice->id)->call('discardDirectSale')->assertStatus(422);
    expect(Invoice::find($invoice->id))->not->toBeNull();
});

it('owner di "Semua cabang" memilih cabang untuk penjualan langsung', function () {
    round2Login('owner', 'owner', 'all');

    Livewire::test(Kasir::class)
        ->call('openDirectSale')
        ->set('direct.buyer_name', 'Pembeli umum')
        ->call('createDirectSale')
        ->assertHasErrors('branchPick')
        ->set('branchPick', (string) $this->malang->id)
        ->call('createDirectSale')
        ->assertHasNoErrors();

    expect(Invoice::whereNull('visit_id')->where('buyer_name', 'Pembeli umum')->value('branch_id'))->toBe($this->malang->id);
});
