<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup database harian. Scheduler harus dijalankan tiap menit oleh server:
// Linux: cron  "* * * * * php /path/artisan schedule:run"
// Windows: Task Scheduler menjalankan "php artisan schedule:run" tiap 1 menit (lihat README).
Schedule::command('egao:backup')
    ->dailyAt(config('backup.daily_at'))
    ->withoutOverlapping();
