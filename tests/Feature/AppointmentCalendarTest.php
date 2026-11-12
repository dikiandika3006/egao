<?php

use App\Livewire\Modules\JanjiTemu;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->actingAs(User::where('username', 'admin')->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => 'admin',
        BranchContext::SESSION_BRANCH => $this->batu->id,
    ]);
});

it('menampilkan kalender bulanan berisi nama pasien', function () {
    $patient = Patient::first();
    $date = today()->startOfMonth()->addDays(14);
    Appointment::create([
        'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'branch_id' => $this->batu->id,
        'date' => $date->toDateString(), 'time' => '09:30', 'purpose' => 'Kontrol',
    ]);

    $test = Livewire::test(JanjiTemu::class)
        ->call('setView', 'bulan')
        ->assertSet('view', 'bulan')
        ->assertSee(today()->translatedFormat('F Y'))
        ->assertSee($patient->name)
        ->assertSee('09.30');

    // Grid selalu kelipatan 7 hari dan menutup seluruh bulan.
    $month = $test->viewData('month');
    expect($month->count() % 7)->toBe(0)
        ->and($month->first()['date']->lte(today()->startOfMonth()))->toBeTrue()
        ->and($month->last()['date']->gte(today()->endOfMonth()->startOfDay()))->toBeTrue();
});

it('berpindah bulan dan memilih tanggal dari kalender', function () {
    $next = today()->startOfMonth()->addMonthNoOverflow();

    Livewire::test(JanjiTemu::class)
        ->call('setView', 'bulan')
        ->call('shiftMonth', 1)
        ->assertSet('date', $next->toDateString())
        ->assertSee($next->translatedFormat('F Y'))
        ->set('date', $next->copy()->addDays(3)->toDateString())
        ->assertSee($next->copy()->addDays(3)->translatedFormat('l, j F Y'));
});

it('janji batal tidak tampil di kalender', function () {
    $patient = Patient::first();
    Appointment::create([
        'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'branch_id' => $this->batu->id,
        'date' => today()->toDateString(), 'status' => Appointment::CANCELLED, 'purpose' => 'Batal',
    ]);

    $month = Livewire::test(JanjiTemu::class)->call('setView', 'bulan')->viewData('month');
    $cell = $month->first(fn ($c) => $c['date']->isToday());

    expect($cell['items']->where('status', Appointment::CANCELLED))->toBeEmpty();
});
