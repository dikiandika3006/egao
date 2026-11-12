<?php

return [

    /*
    | Folder tujuan backup database. Sebaiknya di disk/drive lain (atau folder yang disinkron ke cloud,
    | mis. Google Drive / OneDrive) agar backup tetap ada bila disk server rusak.
    */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Berapa hari backup disimpan sebelum dihapus otomatis.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    // Jam backup harian (format 24 jam).
    'daily_at' => env('BACKUP_DAILY_AT', '02:00'),

    // Lokasi mysqldump. Kosong = cari otomatis (PATH, lalu folder MySQL Laragon).
    'mysqldump' => env('BACKUP_MYSQLDUMP', ''),

    // Darurat saja: izinkan migrate:fresh / db:wipe di database non-testing.
    'allow_destructive_db' => (bool) env('ALLOW_DESTRUCTIVE_DB', false),

];
