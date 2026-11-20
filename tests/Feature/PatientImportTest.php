<?php

use App\Livewire\Modules\Pasien;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Support\BranchContext;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->seed();
    $this->batu = Branch::where('code', 'BTU')->first();
    $this->clinicId = $this->batu->clinic_id;
});

function importLogin(string $username, string $role, string|int $branch): void
{
    test()->actingAs(User::where('username', $username)->firstOrFail());
    session([BranchContext::SESSION_ROLE => $role, BranchContext::SESSION_BRANCH => $branch]);
}

/** Buat file xlsx tiruan ekspor Assist.id. */
function assistXlsx(array $rows): string
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(['Nama', 'Nomor Rekam Medis', 'Nomor Identitas (NIK)', 'Tempat Tanggal Lahir', 'Jenis Kelamin', 'Alamat', 'Nomor Telepon'], null, 'A1');
    foreach ($rows as $i => $row) {
        foreach (array_values($row) as $c => $value) {
            // Semua sebagai teks, seperti file Assist.id (NIK/telepon tidak kehilangan angka 0).
            $sheet->setCellValueExplicit([$c + 1, $i + 2], $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'assist').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

it('merapikan tulisan nomor rekam medis', function (string $raw, string $expected) {
    expect(Patient::normalizeRm($raw))->toBe($expected);
})->with([
    ['a-005', 'A-005'], ['A- 021', 'A-021'], ['Q_003', 'Q-003'], ['E -64', 'E-064'],
    ['J.012', 'J-012'], ['M-002 ', 'M-002'], ['A 7', 'A-007'], ['A-1203', 'A-1203'], ['MR000003', 'MR000003'],
]);

it('memberi nomor RM otomatis per huruf awal nama dan mengabaikan gelar', function () {
    Patient::where('clinic_id', $this->clinicId)->first()->update(['medical_record_number' => 'B-042']);

    expect(Patient::nextMedicalRecordNumber($this->clinicId, 'Budi Santoso'))->toBe('B-043')
        ->and(Patient::nextMedicalRecordNumber($this->clinicId, 'Zaki'))->toBe('Z-001')
        ->and(Patient::rmLetter('dr.Atika Lailan'))->toBe('A')
        ->and(Patient::rmLetter('(dr) Limanto'))->toBe('L')
        ->and(Patient::rmLetter('drg. Sinta'))->toBe('S')
        ->and(Patient::rmLetter('Drajat'))->toBe('D')
        ->and(Patient::rmLetter('`Natalia'))->toBe('N')
        ->and(Patient::rmLetter('123'))->toBe('X');
});

it('mendaftarkan pasien tanpa tanggal lahir dan telepon, dengan RM manual unik', function () {
    importLogin('admin', 'admin', $this->batu->id);

    Livewire::test(Pasien::class)
        ->call('create')
        ->set('patient.name', 'Kirana Putri')
        ->set('patient.medical_record_number', 'k- 77')
        ->set('patient.birth_place', 'Batu')
        ->call('save')
        ->assertHasNoErrors();

    $p = Patient::where('name', 'Kirana Putri')->first();
    expect($p->medical_record_number)->toBe('K-077')
        ->and($p->rmCode())->toBe('K-077')
        ->and($p->birth_date)->toBeNull()
        ->and($p->phone)->toBeNull()
        ->and($p->ageLabel())->toBe('umur –')
        ->and($p->birthLabel())->toBe('Batu')
        ->and($p->maskedPhone())->toBe('—');

    Livewire::test(Pasien::class)
        ->call('create')
        ->set('patient.name', 'Kevin')
        ->set('patient.medical_record_number', 'K-077')
        ->call('save')
        ->assertHasErrors('patient.medical_record_number');

    // Tanpa RM manual → otomatis huruf awal nama.
    Livewire::test(Pasien::class)
        ->call('create')
        ->set('patient.name', 'Kevin')
        ->call('save')
        ->assertHasNoErrors();
    expect(Patient::where('name', 'Kevin')->value('medical_record_number'))->toBe('K-078');

    // Halaman yang menampilkan umur tetap terbuka untuk pasien tanpa tanggal lahir.
    $this->get(route('pasien', ['id' => $p->id]))->assertOk()->assertSee('umur –')->assertSee('K-077');
});

it('dokter tidak bisa mendaftarkan atau mengubah data pasien', function () {
    importLogin('nadia', 'dokter', $this->batu->id);
    $p = Patient::first();

    Livewire::test(Pasien::class)->call('edit', $p->id)->assertForbidden();
    Livewire::test(Pasien::class)->call('create')->assertForbidden();
    $this->get(route('pasien'))->assertOk()->assertDontSee('Ubah data');
});

it('mencari pasien dengan nomor RM huruf dan nomor lama', function () {
    $p = Patient::where('clinic_id', $this->clinicId)->first();
    $p->update(['medical_record_number' => 'A-021']);
    $legacy = Patient::where('medical_record_number', '128')->first();

    expect(Patient::search('a21')->pluck('id'))->toContain($p->id)
        ->and(Patient::search('A-021')->pluck('id'))->toContain($p->id)
        ->and(Patient::search('RM-00128')->pluck('id'))->toContain($legacy->id)
        ->and($legacy->rmCode())->toBe('RM-00128');
});

it('mengimpor pasien Assist.id dengan normalisasi dan aman dijalankan ulang', function () {
    $file = assistXlsx([
        ['Melinda Febriyanti', 'M-002', '3.579.035.411.010.001.', 'Malang, 18-09-2003', 'Perempuan', 'Jl. Dewi Sartika 3', '0812-3456-7890'],
        ['Makna Alam Pratama', 'M-002 ', '3579035411010001', '-, 31-12-1999', 'Laki-laki', '-', ''],
        ['Ani', 'a- 5', '12345', '-, 31-12-1999', 'Perempuan', '', ''],
        ['Budi', 'MR000003', '', 'Batu, 01-01-2000', 'Laki-laki', 'Batu', '085600001111'],
        ['', '', '', '', '', '', ''],
    ]);

    $this->artisan('egao:import-pasien', ['file' => $file, '--dry-run' => true])->assertSuccessful();
    expect(Patient::where('name', 'Ani')->exists())->toBeFalse();

    $this->artisan('egao:import-pasien', ['file' => $file])->assertSuccessful();

    $melinda = Patient::where('name', 'Melinda Febriyanti')->first();
    expect($melinda->medical_record_number)->toBe('M-002')
        ->and($melinda->nik)->toBe('3579035411010001')
        ->and($melinda->birth_place)->toBe('Malang')
        ->and($melinda->birth_date->toDateString())->toBe('2003-09-18')
        ->and($melinda->gender)->toBe('P')
        ->and($melinda->phone)->toBe('081234567890');

    $makna = Patient::where('name', 'Makna Alam Pratama')->first();
    expect($makna->medical_record_number)->toBe('M-003') // RM ganda → nomor berikutnya
        ->and($makna->nik)->toBeNull()                  // NIK sudah dipakai Melinda
        ->and($makna->birth_date)->toBeNull()           // 31-12-1999 = tidak diketahui
        ->and($makna->birth_place)->toBeNull()
        ->and($makna->address)->toBeNull()
        ->and($makna->gender)->toBe('L');

    $ani = Patient::where('name', 'Ani')->first();
    expect($ani->medical_record_number)->toBe('A-005')
        ->and($ani->nik)->toBeNull();

    expect(Patient::where('name', 'Budi')->where('medical_record_number', 'MR000003')->first()->birth_date->toDateString())->toBe('2000-01-01')
        ->and(AuditLog::where('action', 'patient.imported')->count())->toBe(1);

    $total = Patient::count();
    $this->artisan('egao:import-pasien', ['file' => $file])->assertSuccessful();
    expect(Patient::count())->toBe($total);

    @unlink($file);
});
