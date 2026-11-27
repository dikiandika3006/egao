<?php

use App\Enums\Role;
use App\Livewire\Modules\Tentang;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Manual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->branch = Branch::first();
});

function sebagaiPeran(string $role): void
{
    $user = User::whereJsonContains('roles', $role)->firstOrFail();
    test()->actingAs($user)->withSession([
        BranchContext::SESSION_ROLE => $role,
        BranchContext::SESSION_BRANCH => test()->branch->id,
    ]);
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => test()->branch->id]);
}

it('Admin mengunduh manual perannya dari file bawaan', function () {
    sebagaiPeran('admin');

    $this->get(route('manual.unduh', 'admin'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('Admin tidak boleh mengunduh manual Owner', function () {
    sebagaiPeran('admin');

    $this->get(route('manual.unduh', 'owner'))->assertForbidden();
});

it('Owner boleh mengunduh manual peran lain dan 404 bila belum ada', function () {
    sebagaiPeran('owner');

    $this->get(route('manual.unduh', 'admin'))->assertOk();
    if (! Manual::path(Role::Dokter)) {
        $this->get(route('manual.unduh', 'dokter'))->assertNotFound();
    }
    $this->get('/panduan/manual/tidak-ada')->assertNotFound();
});

it('tab panduan menampilkan manual sesuai peran', function () {
    sebagaiPeran('admin');
    Livewire::test(Tentang::class, ['tab' => 'panduan'])
        ->set('tab', 'panduan')
        ->assertSee('Buku Manual Admin / Asisten')
        ->assertDontSee('Buku Manual Owner / Administrator')
        ->assertDontSeeHtml('saveManual');

    sebagaiPeran('owner');
    Livewire::test(Tentang::class)
        ->set('tab', 'panduan')
        ->assertSee('Buku Manual Owner / Administrator')
        ->assertSee('Buku Manual Dokter');
});

it('Owner mengunggah manual baru yang langsung dipakai saat unduh', function () {
    sebagaiPeran('owner');

    Livewire::test(Tentang::class)
        ->set('manualRole', 'dokter')
        ->set('manualFile', UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'))
        ->call('saveManual')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists('manuals/dokter.pdf');
    expect(Manual::info(Role::Dokter)['uploaded'])->toBeTrue();
    expect(AuditLog::where('action', 'manual.uploaded')->exists())->toBeTrue();
    $this->get(route('manual.unduh', 'dokter'))->assertOk();
});

it('menolak unggahan bukan PDF', function () {
    sebagaiPeran('owner');

    Livewire::test(Tentang::class)
        ->set('manualRole', 'admin')
        ->set('manualFile', UploadedFile::fake()->create('manual.docx', 100))
        ->call('saveManual')
        ->assertHasErrors(['manualFile']);
});

it('peran selain Owner tidak boleh mengunggah manual', function () {
    sebagaiPeran('admin');

    Livewire::test(Tentang::class)
        ->set('manualRole', 'admin')
        ->set('manualFile', UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'))
        ->call('saveManual')
        ->assertForbidden();

    Storage::disk('local')->assertMissing('manuals/admin.pdf');
});
