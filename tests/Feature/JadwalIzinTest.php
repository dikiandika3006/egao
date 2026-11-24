<?php

use App\Livewire\Modules\Jadwal;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Support\BranchContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    ScheduleOverride::query()->delete();
    DoctorSchedule::query()->delete();
    $this->batu = Branch::where('code', 'BTU')->firstOrFail();
    $this->izin = Doctor::where('name', 'drg. Nadia Putri')->firstOrFail();
    $this->pengganti = Doctor::where('name', 'drg. Arya Wijaya')->firstOrFail();
    DoctorSchedule::create([
        'doctor_id' => $this->izin->id, 'branch_id' => $this->batu->id,
        'day_of_week' => 1, 'starts_at' => '17:00', 'ends_at' => '20:00',
    ]);

    $this->actingAs(User::where('username', 'owner')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'owner', BranchContext::SESSION_BRANCH => 'all']);
});

it('izin dengan pengganti tetap tersimpan walau isian cabang & jam tersembunyi berisi sisa yang tidak valid', function () {
    Livewire::test(Jadwal::class)
        ->call('openDay', $this->izin->id, '2026-11-23')
        // Sisa isian dari tab "Praktik / pindah cabang" yang lalu tersembunyi saat memilih Libur.
        ->set('override.branch_id', 999999)
        ->set('override.starts_at', '20:00')
        ->set('override.ends_at', '10:00')
        ->set('override.type', 'off')
        ->set('override.substitute_id', (string) $this->pengganti->id)
        ->call('saveOverride')
        ->assertHasNoErrors()
        ->assertSet('form', null);

    expect(ScheduleOverride::where('doctor_id', $this->izin->id)->value('type'))->toBe('off')
        ->and(ScheduleOverride::where('doctor_id', $this->pengganti->id)->value('branch_id'))->toBe($this->batu->id);
});

it('pesan validasi tanpa kolom di formulir tetap ditampilkan di modal', function () {
    $this->izin->update(['is_active' => false]);

    Livewire::test(Jadwal::class)
        ->set('override', [
            'doctor_id' => $this->izin->id, 'date' => '2026-11-23', 'type' => 'off', 'branch_id' => null,
            'starts_at' => '', 'ends_at' => '', 'note' => '', 'substitute_id' => '', 'move_appointments' => true,
        ])
        ->set('form', 'override')
        ->call('saveOverride')
        ->assertHasErrors('override.doctor_id')
        ->assertSee('bg-rose-soft px-4 py-3', false);
});
