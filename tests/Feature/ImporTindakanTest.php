<?php

use App\Livewire\Modules\Pengaturan;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Procedure;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->malang = Branch::where('code', '!=', 'BTU')->first();
});

function tindakanLogin(string $username, string $role, string|int $branch): void
{
    test()->actingAs(User::where('username', $username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

/** File xlsx tiruan "Data Prosedur.xlsx" Assist.id. */
function prosedurXlsx(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['Nama Prosedur', 'Total Harga', 'Catatan'], ...$rows], null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'prosedur').'.xlsx';
    (new Xlsx($book))->save($path);

    return UploadedFile::fake()->createWithContent('Data Prosedur.xlsx', file_get_contents($path));
}

it('mengimpor tindakan baru ke semua cabang dan memperbarui tarif tindakan lama', function () {
    tindakanLogin('owner', 'owner', $this->batu->id);
    $file = prosedurXlsx([
        ['konsultasi', 30000, ''],
        ['Insisi & Drainase Abses', 100000, ''],
        ['Insisi Dan Dentin Akar', 'Rp 120.000', 'per gigi'],
        ['Tanpa Harga', null, ''],
        ['', 5000, ''],
    ]);

    Livewire::test(Pengaturan::class)
        ->call('openProcedureImport')
        ->set('procedureFile', $file)
        ->call('importProcedures')
        ->assertHasNoErrors()
        ->assertSet('importResult.stats', ['baru' => 2, 'diperbarui' => 1, 'dilewati' => 1]);

    $konsul = Procedure::where('code', 'KONS')->with('prices')->first();
    expect($konsul->name)->toBe('Konsultasi')
        ->and($konsul->priceFor($this->batu->id))->toBe(30000)
        ->and($konsul->priceFor($this->malang->id))->toBe(30000);

    $ida = Procedure::where('name', 'Insisi & Drainase Abses')->with('prices')->first();
    $idda = Procedure::where('name', 'Insisi Dan Dentin Akar')->with('prices')->first();
    expect($ida->code)->toBe('IDA')->and($ida->is_active)->toBeTrue()
        ->and($idda->code)->toBe('IDDA')
        ->and($idda->priceFor($this->malang->id))->toBe(120000)
        ->and(AuditLog::where('action', 'procedure.imported')->exists())->toBeTrue();
});

it('hanya mengisi tarif cabang yang dipilih dan kode bentrok diberi angka', function () {
    tindakanLogin('owner', 'owner', $this->batu->id);
    Procedure::create(['clinic_id' => $this->batu->clinic_id, 'code' => 'TA', 'name' => 'Lain', 'is_active' => true]);
    $before = Procedure::where('code', 'KONS')->with('prices')->first()->priceFor($this->malang->id);

    Livewire::test(Pengaturan::class)
        ->call('openProcedureImport')
        ->set('importBranch', (string) $this->batu->id)
        ->set('procedureFile', prosedurXlsx([['Konsultasi', 45000, ''], ['Tambal Anak', 150000, '']]))
        ->call('importProcedures')
        ->assertHasNoErrors();

    $konsul = Procedure::where('code', 'KONS')->with('prices')->first();
    $ta = Procedure::where('name', 'Tambal Anak')->with('prices')->first();
    expect($konsul->priceFor($this->batu->id))->toBe(45000)
        ->and($konsul->priceFor($this->malang->id))->toBe($before)
        ->and($ta->code)->toBe('TA2')
        ->and($ta->priceFor($this->batu->id))->toBe(150000)
        ->and($ta->priceFor($this->malang->id))->toBeNull();
});

it('menolak file tanpa kolom harga dan cabang di luar klinik', function () {
    tindakanLogin('owner', 'owner', $this->batu->id);
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['Nama Prosedur'], ['Scaling']], null, 'A1');
    $path = tempnam(sys_get_temp_dir(), 'prosedur').'.xlsx';
    (new Xlsx($book))->save($path);

    Livewire::test(Pengaturan::class)
        ->set('procedureFile', UploadedFile::fake()->createWithContent('x.xlsx', file_get_contents($path)))
        ->call('importProcedures')
        ->assertHasErrors('procedureFile')
        ->set('importBranch', '9999')
        ->call('importProcedures')
        ->assertHasErrors('importBranch');
});

it('dokter tidak bisa mengimpor tindakan', function () {
    tindakanLogin('nadia', 'dokter', $this->batu->id);
    Livewire::test(Pengaturan::class)->call('openProcedureImport')->assertForbidden();
});
