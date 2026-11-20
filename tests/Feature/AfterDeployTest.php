<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->git = sys_get_temp_dir().'/egao-git-'.uniqid();
    File::ensureDirectoryExists($this->git.'/refs/heads');
    File::put($this->git.'/HEAD', "ref: refs/heads/main\n");
    config(['app.git_dir' => $this->git]);

    $this->state = storage_path('app/private/deployed-commit');
    $this->stateBackup = is_file($this->state) ? file_get_contents($this->state) : null;
    @unlink($this->state);
});

afterEach(function () {
    File::deleteDirectory($this->git);
    $this->stateBackup === null ? @unlink($this->state) : file_put_contents($this->state, $this->stateBackup);
});

it('dilewati di luar production tanpa --force', function () {
    $this->artisan('egao:after-deploy')->expectsOutputToContain('hanya berjalan di server production')->assertSuccessful();
    expect(is_file($this->state))->toBeFalse();
});

it('menyimpan commit baru lalu diam bila commit sama', function () {
    $sha = str_repeat('a', 40);
    File::put($this->git.'/refs/heads/main', $sha."\n");

    $this->artisan('egao:after-deploy', ['--force' => true])->expectsOutputToContain('Selesai untuk commit aaaaaaa')->assertSuccessful();
    expect(trim(file_get_contents($this->state)))->toBe($sha);

    $this->artisan('egao:after-deploy', ['--force' => true])->expectsOutputToContain('Tidak ada perubahan')->assertSuccessful();
});

it('membaca commit dari packed-refs dan mendeteksi perubahan', function () {
    file_put_contents($this->state, str_repeat('a', 40));
    $sha = str_repeat('b', 40);
    File::put($this->git.'/packed-refs', "# pack-refs with: peeled\n{$sha} refs/heads/main\n");

    $this->artisan('egao:after-deploy', ['--force' => true])->expectsOutputToContain('Selesai untuk commit bbbbbbb')->assertSuccessful();
    expect(trim(file_get_contents($this->state)))->toBe($sha);
});

it('diam bila folder .git tidak ada', function () {
    config(['app.git_dir' => $this->git.'/tidak-ada']);

    $this->artisan('egao:after-deploy', ['--force' => true])->expectsOutputToContain('.git tidak ditemukan')->assertSuccessful();
    expect(is_file($this->state))->toBeFalse();
});

it('terjadwal tiap menit', function () {
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('egao:after-deploy');
});
