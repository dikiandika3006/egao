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

// SATUSEHAT: kirim kunjungan yang tertunda tiap 5 menit (tidak melakukan apa pun bila integrasi nonaktif).
Schedule::command('egao:satusehat-kirim')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Google Calendar: kirim janji temu baru/berubah ke kalender dokter & Owner tiap menit (diam bila nonaktif).
Schedule::command('egao:gcal-sync')
    ->everyMinute()
    // Kunci kedaluwarsa 10 menit: bila satu putaran mati di tengah jalan, sinkron tidak macet seharian.
    ->withoutOverlapping(10);
