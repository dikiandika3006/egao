<?php

use App\Livewire\MedicalRecords\Exam;
use App\Livewire\Modules\Kasir;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Models\Visit;
use App\Support\BranchContext;
use App\Support\Settings;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Settings::set('admin_fee', '0');
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->visit = Visit::whereDate('visit_date', today())->whereHas('patient', fn ($q) => $q->where('name', 'Ahmad Fauzi'))
        ->with('doctor.user')->firstOrFail();
    $clinicId = $this->batu->clinic_id;
    $this->asisten = Employee::create(['clinic_id' => $clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Pendamping Uji', 'position' => 'Asisten dokter', 'base_salary' => 0]);
    $this->adminStaff = Employee::create(['clinic_id' => $clinicId, 'primary_branch_id' => $this->batu->id, 'name' => 'Admin Bukan Asisten', 'position' => 'Admin', 'base_salary' => 0]);
});

function masukDokterKunjungan(Visit $visit): void
{
    test()->actingAs(User::where('username', $visit->doctor->user->username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'dokter', BranchContext::SESSION_BRANCH => $visit->branch_id]);
}

it('dokter memilih asisten pendamping saat tindakan, tersimpan di kunjungan dan tercatat di audit', function () {
    masukDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->assertSee('Asisten pendamping')
        ->set('assistantId', $this->asisten->id)
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($this->visit->fresh()->assistant_id)->toBe($this->asisten->id)
        ->and(AuditLog::where('action', 'visit.assistant.updated')->exists())->toBeTrue();

    // Dimuat ulang saat halaman dibuka lagi, lalu bisa dikosongkan.
    $exam = Livewire::test(Exam::class, ['visit' => $this->visit->fresh()]);
    expect($exam->get('assistantId'))->toBe($this->asisten->id);
    $exam->set('assistantId', null)->call('saveDraft')->assertHasNoErrors();
    expect($this->visit->fresh()->assistant_id)->toBeNull();
});

it('boleh dua asisten: tersimpan, tidak boleh sama, dan asisten 2 naik bila asisten 1 dikosongkan', function () {
    $kedua = Employee::create(['clinic_id' => $this->batu->clinic_id, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Kedua Uji', 'position' => 'Asisten dokter', 'base_salary' => 0]);
    masukDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->set('assistantId', $this->asisten->id)
        ->assertSee('Pilih maksimal 2 asisten.')
        ->set('assistant2Id', $kedua->id)
        ->call('saveDraft')
        ->assertHasNoErrors();
    $visit = $this->visit->fresh();
    expect([$visit->assistant_id, $visit->assistant2_id])->toBe([$this->asisten->id, $kedua->id])
        ->and($visit->assistantNames())->toBe('Asisten Pendamping Uji, Asisten Kedua Uji');

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('assistant2Id', $this->asisten->id)
        ->call('saveDraft')
        ->assertHasErrors('assistant2Id');

    Livewire::test(Exam::class, ['visit' => $visit])
        ->set('assistantId', null)
        ->assertSet('assistantId', $kedua->id)
        ->assertSet('assistant2Id', null)
        ->call('saveDraft')
        ->assertHasNoErrors();
    $visit = $visit->fresh();
    expect([$visit->assistant_id, $visit->assistant2_id])->toBe([$kedua->id, null]);
});

it('combobox multi-pilih: centang maksimal 2 asisten, centang lagi untuk melepas', function () {
    $kedua = Employee::create(['clinic_id' => $this->batu->clinic_id, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Kedua Uji', 'position' => 'Asisten dokter', 'base_salary' => 0]);
    $ketiga = Employee::create(['clinic_id' => $this->batu->clinic_id, 'primary_branch_id' => $this->batu->id, 'name' => 'Asisten Ketiga Uji', 'position' => 'Asisten dokter', 'base_salary' => 0]);
    masukDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->call('toggleAssistant', $this->asisten->id)
        ->call('toggleAssistant', $kedua->id)
        ->assertSet('assistantId', $this->asisten->id)->assertSet('assistant2Id', $kedua->id)
        ->call('toggleAssistant', $ketiga->id)
        ->assertHasErrors('assistant2Id')->assertSet('assistant2Id', $kedua->id)
        ->call('toggleAssistant', $this->asisten->id)
        ->assertSet('assistantId', $kedua->id)->assertSet('assistant2Id', null)
        ->call('toggleAssistant', $this->adminStaff->id)
        ->assertNotFound();
});

it('karyawan yang bukan asisten dokter ditolak', function () {
    masukDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->set('assistantId', $this->adminStaff->id)
        ->call('saveDraft')
        ->assertHasErrors('assistantId');

    expect($this->visit->fresh()->assistant_id)->toBeNull();
});

it('hanya asisten cabang kunjungan yang muncul (cabang utama atau berjadwal di cabang itu)', function () {
    $malang = Branch::where('id', '!=', $this->batu->id)->firstOrFail();
    $asistenMalang = Employee::create(['clinic_id' => $this->batu->clinic_id, 'primary_branch_id' => $malang->id, 'name' => 'Asisten Malang', 'position' => 'Asisten dokter', 'base_salary' => 0]);
    $asistenBantu = Employee::create(['clinic_id' => $this->batu->clinic_id, 'primary_branch_id' => $malang->id, 'name' => 'Asisten Bantu Batu', 'position' => 'Asisten dokter', 'base_salary' => 0]);
    \App\Models\EmployeeSchedule::create(['employee_id' => $asistenBantu->id, 'branch_id' => $this->batu->id, 'day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '13:00']);
    masukDokterKunjungan($this->visit);

    $exam = Livewire::test(Exam::class, ['visit' => $this->visit]);
    $ids = $exam->instance()->assistantOptions->keys()->all();
    expect($ids)->toContain($this->asisten->id, $asistenBantu->id)->not->toContain($asistenMalang->id);

    $exam->set('assistantId', $asistenMalang->id)->call('saveDraft')->assertHasErrors('assistantId');
    expect($this->visit->fresh()->assistant_id)->toBeNull();
});

it('kirim tanpa asisten meminta konfirmasi, lalu tetap bisa dikirim', function () {
    masukDokterKunjungan($this->visit);

    $exam = Livewire::test(Exam::class, ['visit' => $this->visit])
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertSet('showSendReview', true)
        ->assertSee('Belum ada asisten pendamping');
    expect($this->visit->fresh()->sent_to_cashier_at)->toBeNull();

    $exam->call('confirmChecksAndSend')->assertHasNoErrors();
    expect($this->visit->fresh()->sent_to_cashier_at)->not->toBeNull()
        ->and($this->visit->fresh()->assistant_id)->toBeNull();
});

it('asisten ikut terkirim ke kasir dan kasir hanya menampilkan namanya', function () {
    masukDokterKunjungan($this->visit);

    Livewire::test(Exam::class, ['visit' => $this->visit])
        ->set('assistantId', $this->asisten->id)
        ->set('allergyConfirmed', true)
        ->call('confirmSend')
        ->assertHasNoErrors();

    $visit = $this->visit->fresh();
    expect($visit->assistant_id)->toBe($this->asisten->id)
        ->and(method_exists(Kasir::class, 'assignAssistant'))->toBeFalse();

    test()->actingAs(User::where('username', 'admin')->firstOrFail());
    session([BranchContext::SESSION_ROLE => 'admin', BranchContext::SESSION_BRANCH => $visit->branch_id]);

    Livewire::test(Kasir::class)->call('open', $visit->invoice->id)
        ->assertSee('Asisten · Asisten Pendamping Uji')
        ->assertDontSee('— tanpa asisten —');
});
