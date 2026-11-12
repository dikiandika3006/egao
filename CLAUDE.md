# Egao Dental Care — Aturan Kerja Wajib

Aplikasi manajemen klinik gigi **Egao Dental Care** (2 cabang: Batu & Malang). Satu data pasien untuk semua cabang.
Modul: antrean, data pasien, rekam medis (odontogram, ICD-10, resep), kasir & kwitansi, janji temu, jadwal dokter,
data dokter, stok obat & bahan, order lab gigi, laporan, pengeluaran, gaji & bagi hasil, pengaturan, bantuan/FAQ.

**Stack:** PHP 8.3 · Laravel 12 · Livewire 3 + Alpine.js · Tailwind CSS 4 (Vite) · MySQL 8 · Dompdf · Laravel Excel · Pest · PWA.
Tidak ada API/aplikasi mobile terpisah; tampilan ponsel = layout responsif yang sama.

## Peta project (ikuti pola yang ada)

| Hal | Lokasi |
| --- | --- |
| Menu, label, path, hak akses peran per modul | `app/Support/Navigation.php` (satu sumber untuk sidebar, bottom bar, route) |
| Route modul | `routes/web.php` → map `$modules` (key Navigation → komponen) + `Navigation::roleMiddleware()` |
| Halaman modul | `app/Livewire/Modules/<Nama>.php` + `resources/views/livewire/modules/<nama>.blade.php`, pakai trait `ModulePage` (`page()`, `toast()`, `requireBranch()`, `clinicId()`) |
| Rekam medis | `app/Livewire/MedicalRecords/{Index,Exam}.php`, logika di `app/Services/ExamService.php` |
| Cabang & peran aktif | `app/Support/BranchContext.php` (session), "Semua cabang" khusus Owner |
| Ikon menu | `resources/views/components/nav-icon.blade.php` — **setiap menu baru wajib diberi ikon + warna** |
| Komponen UI | `x-page-header`, `x-modal`, `x-error`, `x-logo`, `x-nav-icon`; class utilitas di `resources/css/app.css` (`card`, `btn-primary`, `btn-soft`, `btn-ghost`, `input`, `label`, `pill`, `badge`, `eyebrow`) |
| Panduan/FAQ di aplikasi | `app/Support/Faq.php` — **tambah/ubah entri setiap ada fitur baru atau perubahan alur** |
| Aturan sistem & riwayat perubahan | `app/Support/About.php` (`RULES`, `CHANGELOG`) |
| Setting aplikasi (key-value) | `app/Support/Settings.php`, tabel `settings` |
| Perhitungan laporan & gaji | `app/Support/Report.php`, `app/Support/Payroll.php` |
| Jejak audit | `AuditLog::record()` untuk setiap aksi tulis penting |

Bahasa UI, pesan validasi, komentar, dan nama test: **Bahasa Indonesia**. Login memakai **username** (bukan email).

## Aturan keras (jangan dilanggar)

1. **Jangan pernah reset database `egao`** (database yang dipakai user untuk uji manual / data asli).
   `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, dan `db:seed` ke `egao` yang sudah berisi data dilarang.
   Perubahan skema cukup **`php artisan migrate`** dengan migrasi baru. `migrate:fresh` dkk. juga diblok di kode
   (`DB::prohibitDestructiveCommands` di `AppServiceProvider`) kecuali `APP_ENV=testing`.
2. **Jangan ubah migrasi yang sudah dijalankan** — buat migrasi baru. Migrasi tidak boleh menghapus data tanpa persetujuan eksplisit user.
3. **Test hanya di database testing** (`egao_testing` lewat `phpunit.xml`). Bila beberapa sesi bekerja bersamaan, tiap sesi
   memakai database testing sendiri (salinan phpunit sementara) agar tidak saling bentrok.
4. **Rekam medis tidak dihapus**; koreksi = versi baru (`MedicalRecordVersion`). Pasien/dokter dinonaktifkan, bukan dihapus.
5. **Nama method Livewire** tidak boleh bentrok dengan alias JS Livewire: `call`, `set`, `get`, `on`, `el`, `id`, `js`, `hook`,
   `commit`, `watch`, `entangle`, `dispatch*`, `upload*`, `removeUpload`, `cancelUpload`, `refresh`.
6. **Otorisasi dicek di server** (method komponen), bukan hanya disembunyikan di view. Query selalu dibatasi `clinic_id`
   dan cabang yang boleh diakses (`BranchContext`).
7. Data sensitif (NIK, telepon) tetap disamarkan di tampilan; membuka data lengkap dicatat di audit.
8. Jangan commit `.env`, kredensial, atau file backup (`storage/app/backups`).

## Kerja bersama sesi Claude lain

Project ini sering dikerjakan beberapa sesi sekaligus. Sebelum mengedit file milik/yang sedang dipegang sesi lain,
**umumkan dulu** (SendMessage) daftar file yang akan disentuh dan tunggu konfirmasi; kabari lagi saat selesai.
Bila file berubah di disk saat bekerja, anggap itu perubahan sah sesi lain — gabungkan, jangan menimpa.

## Kapan workflow 14 tahap WAJIB dipakai

Wajib untuk setiap **perubahan kode**: fitur baru, bugfix, refactor, migrasi/skema, route, konfigurasi yang mengubah perilaku.
Boleh dilewati untuk request **read-only/eksplorasi** (menjelaskan kode, mencari lokasi bug tanpa memperbaiki, pertanyaan konsep).
Jika ragu, anggap perubahan kode. User dapat menangguhkan workflow untuk satu sesi secara eksplisit.

00. **Request intake** — tulis ulang tujuan & definisi selesai; tentukan scope (termasuk / tidak termasuk). Tanyakan ambiguitas material.
01. **Project recon** — baca modul terkait dan pola yang sudah ada (lihat "Peta project"). Jangan bikin gaya baru tanpa alasan kuat.
02. **Code flow** — telusuri Navigation → route → komponen Livewire → model/service → view.
03. **Dependency** — cari semua pemakai kode yang diubah (komponen lain, `Report`, `Payroll`, `ExamService`, PDF, ekspor Excel, test).
04. **Impact** — kasir, tagihan, pembayaran, bagi hasil, gaji, stok, dan rekam medis = **risiko tinggi** secara default.
05. **Database** — cek skema & relasi; migrasi baru harus aman untuk data yang ada (nullable/default/backfill).
06. **Bug/regression** — cek riwayat & edge case: "Semua cabang", nilai nol/kosong, lintas bulan, pasien beralergi, tagihan sebagian dibayar, penjualan langsung tanpa kunjungan (`$invoice->payerName()`), race condition stok/antrean.
07. **Implementation plan** — daftar file + perubahan + urutan; proporsional dengan scope.
08. **Human approval (hard stop)** — sajikan rencana lewat Plan Mode; jangan implementasi sebelum disetujui.
09. **Implementation** — sesuai rencana, tanpa scope creep.
10. **Verification** — test Pest (`php artisan test`, tambahkan test baru di `tests/Feature`), `npm run build` bila ada class Tailwind baru, render halaman terkait.
11. **Regression** — jalankan seluruh test suite; uji ulang modul terdampak dari tahap 03/04.
12. **Final review** — tanpa kode debug, tanpa secret, konvensi project terpenuhi, otorisasi & validasi input aman. Perbarui `Faq.php`/`About.php` bila perilaku berubah.
13. **Change report** — ringkas ke user: apa yang berubah, file, hasil verifikasi, risiko tersisa. Tanpa file laporan terpisah kecuali diminta.
