# Egao Dental Care

Sistem klinik gigi Egao (cabang Batu & Malang). Acuan: `SRS-Egao-Dental-Care-v2.docx` dan `Design/Design.svg`.

**Stack:** PHP 8.3 · Laravel 12 · Livewire 3 + Alpine.js · Tailwind CSS 4 · MySQL 8 · Dompdf · Laravel Excel · Pest · PWA.

## Menjalankan lokal (Laragon)

```bash
composer install
npm install && npm run build      # atau: npm run dev
cp .env.example .env && php artisan key:generate
php artisan migrate --seed        # database KOSONG pertama kali: egao (berisi data contoh)
```

Buka `http://egao.test` (reload Laragon agar virtual host terbentuk) atau `php artisan serve`.

> **Perlindungan data:** `migrate:fresh`, `migrate:refresh`, `migrate:reset`, dan `db:wipe` ditolak di luar
> lingkungan testing (lihat `AppServiceProvider`). Untuk perubahan skema cukup `php artisan migrate`.
> Bila benar-benar perlu mengosongkan DB lokal: backup dulu (`php artisan egao:backup`), lalu set
> `ALLOW_DESTRUCTIVE_DB=true` sementara di `.env`.

## Akun ilustrasi (kata sandi: `password`, hanya untuk lokal)

| Username       | Peran          | Cabang        |
| -------------- | -------------- | ------------- |
| `owner`        | Owner + Admin  | Semua cabang  |
| `admin`        | Admin          | Batu          |
| `admin.malang` | Admin          | Malang        |
| `nadia`, `arya`, `sinta` | Dokter | Batu & Malang |

## Pengujian

```bash
vendor/bin/pest   # memakai database egao_testing (bukan egao)
```

## Backup database

```bash
php artisan egao:backup            # manual; hasil: storage/app/backups/egao-YYYY-MM-DD_HHMMSS.sql.gz
```

- Terjadwal harian jam `BACKUP_DAILY_AT` (default 02:00), backup lebih lama dari `BACKUP_KEEP_DAYS` (default 30) dihapus.
- Set `BACKUP_PATH` ke disk lain / folder yang disinkron ke cloud agar backup selamat bila disk server rusak.
- Jadwal hanya jalan bila scheduler aktif:
  - **Linux:** cron `* * * * * cd /path/egao && php artisan schedule:run >> /dev/null 2>&1`
  - **Windows:** `schtasks /Create /SC MINUTE /MO 1 /TN "Egao Scheduler" /TR "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\egao\artisan schedule:run"`
- **Memulihkan** (menimpa isi DB, lakukan dengan hati-hati):
  `gzip -dc egao-....sql.gz | mysql -u root egao`

## Pemasangan di server asli (production)

1. `cp .env.production.example .env`, isi domain (HTTPS), user DB khusus, `BACKUP_PATH`; lalu `php artisan key:generate`.
   `APP_DEBUG` wajib `false`. Pasang di domain/subdomain sendiri dengan document root ke folder `public/`.
2. `composer install --no-dev --optimize-autoloader` dan `npm ci && npm run build`.
3. `php artisan migrate --force`
4. `php artisan db:seed --class=ProductionSeeder --force` (klinik, cabang, master klinis, tarif awal — **tanpa data contoh**).
   `DatabaseSeeder` (data contoh) otomatis menolak berjalan di production.
5. `php artisan egao:owner` → membuat akun Owner pertama dengan kata sandi acak (tampil sekali, catat).
   Ganti kata sandi akun apa pun: `php artisan egao:owner <username> --reset`.
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. Aktifkan scheduler (lihat bagian Backup) dan uji `php artisan egao:backup`.

## Status modul

| Modul | Status |
| --- | --- |
| Autentikasi, pilih peran & cabang, layout desktop/ponsel, PWA | Selesai (default) |
| 01 Dashboard | Selesai (default) |
| 02 Antrean, 03 Data pasien, 05 Kasir + kwitansi PDF, 06 Jadwal dokter | Selesai (versi awal) |
| 04 Rekam medis, 07 Data dokter | Selesai (sesi terpisah) |
| Laporan (+ ekspor Excel), Pengeluaran, Gaji & bagi hasil, Pengaturan | Selesai (versi awal) |

Menu, hak akses peran, dan route diatur di `app/Support/Navigation.php`. Saat modul dibangun,
ganti route `ModulePlaceholder` dengan komponen Livewire modul tersebut.
