<?php

use App\Livewire\BackupPanel;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    $this->dir = storage_path('framework/testing/backups-'.uniqid());
    config(['backup.path' => $this->dir, 'backup.pin' => '2468']);
    File::ensureDirectoryExists($this->dir);
    RateLimiter::clear('backup-pin:'.User::where('username', 'owner')->value('id').'|127.0.0.1');
});

afterEach(fn () => File::deleteDirectory($this->dir));

function backupAs(string $username, string $role, string|int $branch = 'all')
{
    return test()->actingAs(User::where('username', $username)->firstOrFail())->withSession([
        BranchContext::SESSION_ROLE => $role,
        BranchContext::SESSION_BRANCH => $branch,
    ]);
}

function fakeBackup(string $dir, string $name = 'backup_20261101_020000.sql.gz'): string
{
    $gz = gzopen($dir.DIRECTORY_SEPARATOR.$name, 'wb');
    gzwrite($gz, "CREATE TABLE `users` (id int);\nCREATE TABLE `migrations` (id int);\nCREATE TABLE `patients` (id int);\n");
    gzclose($gz);

    return $name;
}

it('panel backup hanya untuk owner', function () {
    $batu = Branch::where('code', 'BTU')->first();
    backupAs('admin', 'admin', $batu->id);

    Livewire::test(BackupPanel::class)->assertForbidden();
});

it('panel tampil di halaman tentang untuk owner saja', function () {
    backupAs('owner', 'owner')->get(route('tentang'))->assertSee('Backup &amp; restore database', false);

    $batu = Branch::where('code', 'BTU')->first();
    backupAs('admin', 'admin', $batu->id)->get(route('tentang'))->assertDontSee('Backup &amp; restore database', false);
});

it('nonaktif bila PIN belum diisi', function () {
    config(['backup.pin' => '']);
    backupAs('owner', 'owner');

    Livewire::test(BackupPanel::class)->assertSee('Fitur belum aktif');
});

it('membuka panel dengan PIN benar dan menolak PIN salah', function () {
    backupAs('owner', 'owner');
    $name = fakeBackup($this->dir);

    Livewire::test(BackupPanel::class)
        ->assertDontSee($name)
        ->set('pin', '0000')->call('unlock')
        ->assertHasErrors('pin')
        ->assertDontSee($name)
        ->set('pin', '2468')->call('unlock')
        ->assertHasNoErrors()
        ->assertSee($name)
        ->call('lock')
        ->assertDontSee($name);
});

it('membatasi percobaan PIN', function () {
    backupAs('owner', 'owner');
    $test = Livewire::test(BackupPanel::class);

    foreach (range(1, 5) as $i) {
        $test->set('pin', '1111')->call('unlock');
    }

    $test->set('pin', '2468')->call('unlock')->assertHasErrors('pin')->assertSee('Terlalu banyak percobaan');
});

it('aksi backup ditolak tanpa PIN', function () {
    backupAs('owner', 'owner');
    $name = fakeBackup($this->dir);

    Livewire::test(BackupPanel::class)->call('download', $name)->assertForbidden();
    Livewire::test(BackupPanel::class)->call('createBackup')->assertForbidden();
    Livewire::test(BackupPanel::class)->call('restore')->assertForbidden();
});

it('mengunduh dan menghapus file backup', function () {
    backupAs('owner', 'owner');
    $name = fakeBackup($this->dir);

    Livewire::test(BackupPanel::class)
        ->set('pin', '2468')->call('unlock')
        ->call('download', $name)->assertFileDownloaded($name)
        ->call('delete', $name);

    expect(is_file($this->dir.DIRECTORY_SEPARATOR.$name))->toBeFalse();
});

it('menolak nama file di luar pola (anti path traversal)', function () {
    backupAs('owner', 'owner');

    Livewire::test(BackupPanel::class)
        ->set('pin', '2468')->call('unlock')
        ->call('download', '../../.env')->assertNotFound();
});

it('restore butuh konfirmasi RESTORE dan file yang valid', function () {
    backupAs('owner', 'owner');
    $name = fakeBackup($this->dir);
    $bad = 'backup_20261101_030000.sql.gz';
    $gz = gzopen($this->dir.DIRECTORY_SEPARATOR.$bad, 'wb');
    gzwrite($gz, "CREATE TABLE `lain` (id int);\n");
    gzclose($gz);

    Livewire::test(BackupPanel::class)
        ->set('pin', '2468')->call('unlock')
        ->call('pickRestore', $name)
        ->set('confirm', 'restore')->call('restore')
        ->assertHasErrors('confirm')
        ->call('pickRestore', $bad)
        ->set('confirm', 'RESTORE')->call('restore')
        ->assertHasErrors('restore')
        ->assertSee('bukan backup database Egao');
});
