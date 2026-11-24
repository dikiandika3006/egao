<?php

use App\Enums\VisitStatus;
use App\Livewire\Modules\Antrean;
use App\Livewire\Modules\JanjiTemu;
use App\Models\Appointment;
use App\Models\AppointmentReschedule;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', '!=', 'BTU')->first();
    $this->patient = Patient::first();
    janjiLogin('admin', 'admin', $this->batu->id);
});

function janjiLogin(string $username, string $role, string|int $branch): User
{
    $user = User::where('username', $username)->firstOrFail();
    test()->actingAs($user);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);

    return $user;
}

function janjiBaru(array $attrs = []): Appointment
{
    $t = test();

    return Appointment::create($attrs + [
        'clinic_id' => $t->patient->clinic_id, 'patient_id' => $t->patient->id, 'branch_id' => $t->batu->id,
        'date' => today()->addDays(2)->toDateString(), 'time' => '10:00:00', 'purpose' => 'Scaling',
        'status' => Appointment::SCHEDULED,
    ]);
}

it('menjadwal ulang janji ke cabang lain dengan alasan dan menyimpan riwayat', function () {
    $a = janjiBaru();
    $newDate = today()->addDays(5)->toDateString();

    Livewire::test(JanjiTemu::class)
        ->call('edit', $a->id)
        ->assertSet('form.branch_id', (string) $this->batu->id)
        ->set('form.branch_id', (string) $this->malang->id)
        ->set('form.date', $newDate)
        ->call('save')
        ->assertHasErrors('form.reason')
        ->set('form.reason', 'Minta jadwal lain')
        ->call('save')
        ->assertHasNoErrors();

    $a->refresh();
    $log = AppointmentReschedule::where('appointment_id', $a->id)->sole();

    expect($a->branch_id)->toBe($this->malang->id)
        ->and($a->date->toDateString())->toBe($newDate)
        ->and($a->calendar_dirty)->toBeTrue()
        ->and($log->from_branch_id)->toBe($this->batu->id)
        ->and($log->to_branch_id)->toBe($this->malang->id)
        ->and($log->reason)->toBe('Minta jadwal lain')
        ->and($log->from_status)->toBe(Appointment::SCHEDULED);

    $this->assertDatabaseHas('audit_logs', ['action' => 'janji.dijadwal_ulang']);
});

it('janji tidak datang di tanggal lewat bisa dijadwal ulang dan kembali terjadwal', function () {
    $a = janjiBaru(['date' => today()->subDays(3)->toDateString(), 'status' => Appointment::NO_SHOW, 'status_reason' => 'Pasien sakit']);

    Livewire::test(JanjiTemu::class)
        ->call('edit', $a->id)
        ->assertSet('form.date', today()->toDateString())
        ->set('form.date', today()->addDay()->toDateString())
        ->set('form.reason', 'Pasien sudah sehat')
        ->call('save')
        ->assertHasNoErrors();

    $a->refresh();
    expect($a->status)->toBe(Appointment::SCHEDULED)
        ->and($a->status_reason)->toBeNull()
        ->and($a->reschedules()->sole()->from_status)->toBe(Appointment::NO_SHOW);
});

it('mengubah keperluan saja tidak meminta alasan dan tidak mencatat jadwal ulang', function () {
    $a = janjiBaru();

    Livewire::test(JanjiTemu::class)
        ->call('edit', $a->id)
        ->set('form.purpose', 'Scaling + polishing')
        ->call('save')
        ->assertHasNoErrors();

    expect($a->fresh()->purpose)->toBe('Scaling + polishing')
        ->and(AppointmentReschedule::count())->toBe(0);
});

it('jadwal ulang tetap menolak bentrok jadwal dokter di cabang tujuan', function () {
    $doctor = Doctor::first();
    $date = today()->addDays(6)->toDateString();
    janjiBaru(['branch_id' => $this->malang->id, 'doctor_id' => $doctor->id, 'date' => $date, 'time' => '10:00:00']);
    $a = janjiBaru(['patient_id' => Patient::skip(1)->first()->id]);

    Livewire::test(JanjiTemu::class)
        ->call('edit', $a->id)
        ->set('form.branch_id', (string) $this->malang->id)
        ->set('form.doctor_id', (string) $doctor->id)
        ->set('form.date', $date)
        ->set('form.time', '10:15')
        ->set('form.reason', 'Minta jadwal lain')
        ->call('save')
        ->assertHasErrors('form.time');
});

it('menolak cabang nonaktif atau milik klinik lain', function () {
    $a = janjiBaru();
    $other = Clinic::create(['name' => 'Klinik Lain']);
    $foreign = Branch::create(['clinic_id' => $other->id, 'code' => 'LAIN', 'name' => 'Cabang Lain']);
    $this->malang->update(['is_active' => false]);

    foreach ([$foreign->id, $this->malang->id] as $branchId) {
        Livewire::test(JanjiTemu::class)
            ->call('edit', $a->id)
            ->set('form.branch_id', (string) $branchId)
            ->set('form.reason', 'Coba')
            ->call('save')
            ->assertHasErrors('form.branch_id');
    }

    expect($a->fresh()->branch_id)->toBe($this->batu->id);
});

it('batal dengan alasan tersimpan dan pulihkan mengosongkannya', function () {
    $a = janjiBaru();

    Livewire::test(JanjiTemu::class)
        ->call('askStatus', $a->id, Appointment::CANCELLED)
        ->set('statusForm.reason', 'Tidak bisa dihubungi')
        ->call('confirmStatus')
        ->assertSet('statusForm.id', null);

    expect($a->fresh()->status)->toBe(Appointment::CANCELLED)
        ->and($a->fresh()->status_reason)->toBe('Tidak bisa dihubungi');

    Livewire::test(JanjiTemu::class)->call('setStatus', $a->id, Appointment::SCHEDULED);

    expect($a->fresh()->status)->toBe(Appointment::SCHEDULED)
        ->and($a->fresh()->status_reason)->toBeNull();
});

it('janji yang sudah datang tidak bisa dijadwal ulang', function () {
    $a = janjiBaru(['status' => Appointment::ARRIVED]);

    Livewire::test(JanjiTemu::class)->call('edit', $a->id)->assertStatus(422);
});

it('mengalihkan pasien walk-in ke cabang lain lalu masuk antrean di sana', function () {
    $visit = Visit::where('branch_id', $this->batu->id)->whereDate('visit_date', today())
        ->where('status', VisitStatus::Menunggu)->firstOrFail();
    $arya = Doctor::where('name', 'like', '%Arya%')->firstOrFail();

    Livewire::test(Antrean::class)
        ->call('openTransfer', $visit->id)
        ->set('transfer.branch_id', (string) $this->malang->id)
        ->set('transfer.doctor_id', (string) $arya->id)
        ->call('saveTransfer')
        ->assertHasErrors('transfer.reason')
        ->set('transfer.reason', 'Antrean penuh')
        ->call('saveTransfer')
        ->assertHasNoErrors()
        ->assertSet('transfer', []);

    $a = Appointment::where('source', Appointment::SOURCE_TRANSFER)->where('source_visit_id', $visit->id)->sole();
    expect($visit->fresh()->status)->toBe(VisitStatus::Batal)
        ->and($a->branch_id)->toBe($this->malang->id)
        ->and($a->status)->toBe(Appointment::SCHEDULED)
        ->and($a->patient_id)->toBe($visit->patient_id);

    // Tab Tidak hadir menampilkan tujuan pengalihan; "Masukkan lagi" ditolak.
    Livewire::test(Antrean::class)
        ->set('filter', 'batal')
        ->assertSee('Dialihkan ke')
        ->call('backToWaiting', $visit->id)
        ->assertHasErrors('antrean');
    expect($visit->fresh()->status)->toBe(VisitStatus::Batal);

    // Admin Malang memasukkan pasien ke antrean saat tiba.
    janjiLogin('admin.malang', 'admin', $this->malang->id);
    Livewire::test(JanjiTemu::class)->call('checkIn', $a->id)->assertHasNoErrors();

    expect($a->fresh()->status)->toBe(Appointment::ARRIVED)
        ->and(Visit::where('patient_id', $visit->patient_id)->where('branch_id', $this->malang->id)->whereDate('visit_date', today())->exists())->toBeTrue();
});

it('pengalihan ke cabang yang sama di hari yang sama ditolak', function () {
    $visit = Visit::where('branch_id', $this->batu->id)->whereDate('visit_date', today())
        ->where('status', VisitStatus::Menunggu)->firstOrFail();

    Livewire::test(Antrean::class)
        ->call('openTransfer', $visit->id)
        ->set('transfer.branch_id', (string) $this->batu->id)
        ->set('transfer.reason', 'Coba')
        ->call('saveTransfer')
        ->assertHasErrors('transfer.branch_id');

    expect($visit->fresh()->status)->toBe(VisitStatus::Menunggu);
});

it('dokter tidak bisa mengalihkan pasien', function () {
    $visit = Visit::where('branch_id', $this->batu->id)->whereDate('visit_date', today())
        ->where('status', VisitStatus::Menunggu)->firstOrFail();
    janjiLogin('nadia', 'dokter', $this->batu->id);

    Livewire::test(Antrean::class)->call('openTransfer', $visit->id)->assertForbidden();
});
