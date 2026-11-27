<?php

use App\Livewire\Modules\JanjiTemu;
use App\Livewire\Modules\Pengaturan;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSync;
use App\Support\BranchContext;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->monday = today()->next(Carbon::MONDAY);
    $this->sunday = today()->next(Carbon::SUNDAY);
});

function jamLogin(string $username, string $role, string|int $branch): void
{
    test()->actingAs(User::where('username', $username)->firstOrFail())
        ->withSession([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

it('cabang memakai jam kerja bawaan dengan istirahat di hari kerja', function () {
    $b = $this->batu->fresh();

    expect($b->hoursLabel($this->monday))->toBe('10.00–13.00, 15.00–20.00')
        ->and($b->hoursLabel($this->sunday))->toBe('10.00–17.00')
        ->and($b->isOpenAt($this->monday, '10:30'))->toBeTrue()
        ->and($b->isOpenAt($this->monday, '14:00'))->toBeFalse() // istirahat
        ->and($b->isOpenAt($this->monday, '20:00'))->toBeFalse()
        ->and($b->isOpenAt($this->sunday, '16:30'))->toBeTrue()
        ->and($b->openingTime($this->monday))->toBe('10:00');
});

it('owner mengatur jam kerja per cabang', function () {
    jamLogin('owner', 'owner', 'all');

    $test = Livewire::test(Pengaturan::class)->call('setTab', 'klinik')->call('editBranch', $this->batu->id);

    // Sesi 1 tidak valid → error.
    $test->set('data.hours.1.s1_start', '13:00')->set('data.hours.1.s1_end', '09:00')
        ->call('saveBranch')
        ->assertHasErrors('data.hours.1.s1_end');

    // Senin 09–12 & 13–21, Minggu tutup.
    $test->set('data.hours.1.s1_start', '09:00')->set('data.hours.1.s1_end', '12:00')
        ->set('data.hours.1.s2_start', '13:00')->set('data.hours.1.s2_end', '21:00')
        ->set('data.hours.7.closed', true)
        ->call('saveBranch')
        ->assertHasNoErrors();

    $b = $this->batu->fresh();
    expect($b->hoursLabel($this->monday))->toBe('09.00–12.00, 13.00–21.00')
        ->and($b->hoursLabel($this->sunday))->toBe('Tutup')
        ->and(substr($b->opens_at, 0, 5))->toBe('09:00')
        ->and(substr($b->closes_at, 0, 5))->toBe('21:00')
        // Cabang lain tidak ikut berubah.
        ->and(Branch::where('code', 'MLG')->first()->hoursLabel($this->sunday))->toBe('10.00–17.00');
});

it('owner mengatur nomor WhatsApp per cabang', function () {
    jamLogin('owner', 'owner', 'all');

    $test = Livewire::test(Pengaturan::class)->call('setTab', 'klinik')->call('editBranch', $this->batu->id);
    $test->set('data.whatsapp', 'abc')->call('saveBranch')->assertHasErrors('data.whatsapp');
    $test->set('data.whatsapp', '0812 3456 7890')->call('saveBranch')->assertHasNoErrors();

    expect($this->batu->fresh()->whatsapp)->toBe('0812 3456 7890')
        ->and(Branch::where('code', 'MLG')->first()->whatsapp)->not->toBe('0812 3456 7890');
});

it('janji di luar jam kerja hanya diberi peringatan dan tetap tersimpan', function () {
    jamLogin('admin', 'admin', $this->batu->id);
    $patient = Patient::first();

    Livewire::test(JanjiTemu::class)
        ->call('create')
        ->set('form.patient_id', $patient->id)
        ->set('form.date', $this->monday->toDateString())
        ->assertSee('Jam kerja Batu')
        ->set('form.time', '10:30')
        ->assertDontSee('di luar jam kerja')
        ->set('form.time', '14:00')
        ->assertSee('Jam 14.00 di luar jam kerja Batu')
        ->call('save')
        ->assertHasNoErrors();

    expect(Appointment::where('patient_id', $patient->id)->whereDate('date', $this->monday)->where('time', 'like', '14:00%')->exists())->toBeTrue();

    Livewire::test(JanjiTemu::class)
        ->set('date', $this->monday->toDateString())
        ->assertSee('Di luar jam kerja Batu');
});

it('peringatan cabang tutup di hari libur', function () {
    $this->batu->update(['operating_hours' => [7 => []] + Branch::DEFAULT_HOURS]);
    jamLogin('admin', 'admin', $this->batu->id);

    Livewire::test(JanjiTemu::class)
        ->call('create')
        ->set('form.date', $this->sunday->toDateString())
        ->assertSee('Batu tutup di hari Minggu');
});

it('janji tanpa jam di google calendar dipasang pada jam buka cabang + 1 jam', function () {
    $this->batu->update(['operating_hours' => [1 => [['09:00', '12:00']]] + Branch::DEFAULT_HOURS]);
    $patient = Patient::first();

    $monday = Appointment::create([
        'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'branch_id' => $this->batu->id,
        'date' => $this->monday->toDateString(), 'status' => Appointment::SCHEDULED, 'source' => 'manual',
    ])->load('branch');
    $sunday = $monday->replicate()->fill(['date' => $this->sunday->toDateString()]);
    $sunday->setRelation('branch', $this->batu->fresh());
    $monday->setRelation('branch', $this->batu->fresh());

    expect(GoogleCalendarSync::defaultTimeFor($monday))->toBe('10:00')
        ->and(GoogleCalendarSync::defaultTimeFor($sunday))->toBe('11:00');
});
