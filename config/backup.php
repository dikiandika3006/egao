<?php

return [

    /*
    | Folder file backup (tidak bisa diakses publik). Untuk server asli sebaiknya di disk lain atau folder
    | yang disinkron ke cloud (Google Drive / OneDrive) agar backup tetap ada bila disk server rusak.
    */
    'path' => env('BACKUP_PATH') ?: storage_path('app/private/backups'),

    // Berapa hari backup otomatis disimpan sebelum dihapus (backup "_pre-restore" tidak dihapus otomatis).
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    // Jam backup harian (format 24 jam).
    'daily_at' => env('BACKUP_DAILY_AT', '02:00'),

    /*
    | PIN panel Backup & Restore (Bantuan & aturan › Tentang & aturan, khusus Owner).
    | Kosong = panel nonaktif. Simpan hanya di .env server, jangan di-commit.
    */
    'pin' => env('BACKUP_PIN'),

    // Lama panel tetap terbuka setelah PIN benar (menit).
    'unlock_minutes' => (int) env('BACKUP_UNLOCK_MINUTES', 15),

    // Darurat saja: izinkan migrate:fresh / db:wipe di database non-testing.
    'allow_destructive_db' => (bool) env('ALLOW_DESTRUCTIVE_DB', false),

];
