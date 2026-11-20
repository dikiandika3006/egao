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
php artisan egao:backup            # manual; hasil: storage/app/private/backups/backup_YYYYMMDD_HHMMSS.sql.gz
```

- **Panel Backup & Restore** di aplikasi: *Bantuan & aturan › Tentang & aturan* (khusus Owner, dibuka dengan PIN
  `BACKUP_PIN` di `.env`; kosong = panel nonaktif). Bisa buat & unduh backup, lihat riwayat, hapus, dan restore dari
  riwayat atau upload `.sql`/`.sql.gz` (konfirmasi ketik `RESTORE`; otomatis membuat backup `_pre-restore` dulu,
  aplikasi masuk mode perawatan selama proses, lalu semua pengguna login ulang). PIN salah dibatasi 5×/10 menit.
- Mesin backup PHP murni (PDO) — tidak butuh `mysqldump`, aman di shared hosting. Upload restore lewat panel maks ±12 MB
  (batas upload Livewire); file lebih besar: taruh di folder backup lalu pilih dari riwayat.
- Terjadwal harian jam `BACKUP_DAILY_AT` (default 02:00), backup lebih lama dari `BACKUP_KEEP_DAYS` (default 30) dihapus
  (kecuali backup `_pre-restore`).
- Set `BACKUP_PATH` ke disk lain / folder yang disinkron ke cloud agar backup selamat bila disk server rusak.
- Jadwal hanya jalan bila scheduler aktif:
  - **Linux:** cron `* * * * * cd /path/egao && php artisan schedule:run >> /dev/null 2>&1`
  - **Windows** (tanpa jendela konsol, lewat `scripts/egao-scheduler.vbs`):
    `schtasks /Create /SC MINUTE /MO 1 /TN "Egao Scheduler" /TR "wscript.exe C:\laragon\www\egao\scripts\egao-scheduler.vbs C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"`
- **Memulihkan** tanpa aplikasi (menimpa isi DB, lakukan dengan hati-hati):
  `gzip -dc backup_....sql.gz | mysql -u root egao`

## SATUSEHAT (Kemenkes)

- Atur di aplikasi: *Pengaturan › SATUSEHAT* (khusus Owner) — saklar aktif, Sandbox/Production, kredensial per cabang
  (Organization ID, Client ID, Client Secret dari satusehat.kemkes.go.id/platform), tes koneksi.
- Kunjungan yang dikirim ke kasir masuk tabel `satusehat_submissions`, lalu dikirim (Encounter + Condition ICD-10, satu
  Bundle) oleh `php artisan egao:satusehat-kirim` tiap 5 menit — **butuh scheduler aktif** (lihat bagian Backup).
- Pasien & dokter dicocokkan lewat NIK (ID IHS disimpan setelah ditemukan). Location ruang praktik dibuat otomatis per cabang.
- Client Secret dienkripsi dengan `APP_KEY`; bila `APP_KEY` diganti, isi ulang secret di Pengaturan.
- URL API bisa diganti lewat `.env` (`SATUSEHAT_SANDBOX_*`, `SATUSEHAT_PRODUCTION_*`); lihat `config/satusehat.php`.

## Google Calendar (janji temu)

- Google Cloud Console → buat project → aktifkan **Google Calendar API** → *IAM & Admin › Service Accounts* → buat
  service account → *Keys › Add key › JSON*.
- Simpan file JSON di server: `storage/app/google/calendar.json` (atau path lain lewat `GOOGLE_CALENDAR_CREDENTIALS`).
  Folder ini di-ignore git — **jangan di-commit**.
- Service account membuat kalender sendiri (`google_calendars`: "Egao – nama dokter" per dokter + "Egao – Semua janji temu"
  untuk Owner) dan membagikannya lewat ACL `reader` + email undangan (`google_calendar_shares`). Penerima tidak perlu
  mengatur apa pun selain membuka undangan. Email dokter diisi di *Data Dokter*, email Owner & saklar aktif di
  *Pengaturan › Google Calendar*.
- Janji ditandai `calendar_dirty`, lalu dikirim oleh `php artisan egao:gcal-sync` tiap menit — **butuh scheduler aktif**.
  Tanpa paket tambahan (JWT service account ditandatangani `openssl`). Konfigurasi: `config/google_calendar.php`.

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

### Hostinger shared hosting (deploy otomatis dari GitHub, tanpa SSH)

- hPanel → **Advanced → Git**: repo `git@github.com:dikiandika3006/egao.git`, branch `main`, folder kosong (= `public_html`
  yang harus kosong). Kunci SSH dari hPanel dipasang di GitHub → Settings → Deploy keys. Aktifkan **Auto Deployment** dan
  pasang Webhook URL-nya di GitHub → Settings → Webhooks.
- Seluruh repo berada di `public_html`; `.htaccess` di root repo mengarahkan semua request ke `public/`
  (`.env`, `.git`, `vendor`, `storage` tidak bisa dibuka dari browser — cek `/.env` harus 404).
- File yang tidak ada di git dipasang sekali: `.env`, `vendor/` (`composer install --no-dev`), `storage/app/google/calendar.json`.
- `public/build` **ikut di-commit** (shared hosting tanpa Node): jalankan `npm run build` sebelum commit.
- Cron hPanel tiap menit: `/usr/bin/php /home/<user>/domains/<domain>/public_html/artisan schedule:run`.
  Scheduler menjalankan `egao:after-deploy`: bila commit berubah → backup (bila ada migrasi) → `migrate --force` →
  bersihkan cache → `config:cache`. `route:cache` sengaja tidak dipakai di server.
- Paket Composer baru tetap perlu `composer install --no-dev` manual.

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
