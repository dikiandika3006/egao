<?php

/**
 * Integrasi Google Calendar (janji temu → kalender dokter & Owner) memakai Service Account.
 * File kunci JSON dari Google Cloud Console disimpan di server (jangan di-commit).
 * Saklar aktif, jam default, dan email Owner diatur di Pengaturan → Google Calendar.
 */
return [
    'credentials' => env('GOOGLE_CALENDAR_CREDENTIALS') ?: storage_path('app/google/calendar.json'),

    'timezone' => 'Asia/Jakarta',

    // Lama event bila jam janji tidak diisi / durasi default.
    'duration_minutes' => (int) env('GOOGLE_CALENDAR_DURATION', 60),

    // Detik tunggu respons Google.
    'timeout' => (int) env('GOOGLE_CALENDAR_TIMEOUT', 20),

    // Batas percobaan otomatis per janji; setelah itu hanya lewat tombol "Kirim ulang".
    'max_attempts' => (int) env('GOOGLE_CALENDAR_MAX_ATTEMPTS', 5),

    // Jumlah janji per putaran jadwal (tiap menit).
    'batch_size' => (int) env('GOOGLE_CALENDAR_BATCH_SIZE', 30),
];
