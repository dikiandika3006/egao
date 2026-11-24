<?php

namespace App\Support;

/**
 * Konten halaman Tentang. Diedit manual setiap ada perubahan aplikasi (pola sama dengan Cattleya):
 * RULES = aturan bisnis yang berlaku, CHANGELOG = riwayat perubahan, terbaru di atas.
 */
class About
{
    public const VERSION = '1.0.0';

    public const RULES = [
        [
            'title' => 'Pasien & cabang',
            'rules' => [
                'Satu data pasien berlaku untuk semua cabang. Pasien tidak perlu mendaftar ulang saat pindah dari Batu ke Malang atau sebaliknya.',
                'No. RM = huruf awal nama + nomor urut per huruf (mis. B-043), dibuat otomatis atau diisi manual; tidak boleh dobel. NIK tidak boleh dobel; bila sudah terdaftar, cari pasien tersebut.',
                'Tanggal lahir, tempat lahir, dan telepon pasien boleh kosong (data lama); umur tampil "umur –".',
                'Telepon dan NIK selalu disamarkan. Membuka data lengkap tercatat di jejak audit.',
                'Pengguna hanya melihat data cabang yang menjadi aksesnya. Pilihan "Semua cabang" hanya untuk Owner.',
                'Peran: Owner, Admin, Asisten, Dokter. Menu yang boleh dibuka, diubah, dan angka keuangannya diatur Owner per peran di Pengaturan › Hak akses peran; Owner selalu akses penuh.',
            ],
        ],
        [
            'title' => 'Antrean',
            'rules' => [
                'Nomor antrean berurutan per dokter, per cabang, per hari.',
                'Pasien yang masih aktif di antrean hari ini tidak bisa didaftarkan dua kali.',
                'Antrean: Menunggu → Selesai tindakan (otomatis setelah dokter menyimpan pemeriksaan; keterangan Belum bayar/Lunas) atau Tidak hadir. Tanpa langkah Panggil.',
            ],
        ],
        [
            'title' => 'Rekam medis',
            'rules' => [
                'Pemeriksaan diisi dokter kunjungan, atau Admin/Asisten/Owner (yang punya izin Edit Rekam medis) atas nama dokter tersebut di cabang aksesnya (penginput tercatat). Dokter lain hanya membaca.',
                'Tindakan yang dicatat dokter otomatis menjadi rincian tagihan di kasir, dengan harga sesuai tarif cabang saat itu.',
                'Pasien beralergi wajib dikonfirmasi alerginya sebelum dikirim ke kasir.',
                'Rekam medis tidak dihapus. Koreksi menjadi versi baru, versi lama tetap tersimpan.',
            ],
        ],
        [
            'title' => 'Kasir & kwitansi',
            'rules' => [
                'Diskon wajib diberi alasan dan hanya bisa sebelum ada pembayaran. Admin dibatasi persentase diskon maksimal; Owner tidak dibatasi.',
                'Pembayaran boleh sebagian. Kunjungan baru Selesai setelah tagihan lunas.',
                'Kwitansi terbit saat lunas, bernomor urut per cabang mulai dari "nomor awal kwitansi" di pengaturan cabang.',
                'Metode bayar yang tampil di kasir mengikuti pengaturan Owner.',
            ],
        ],
        [
            'title' => 'Jadwal dokter',
            'rules' => [
                'Satu dokter hanya boleh berada di satu lokasi pada jam yang sama; jadwal bentrok ditolak.',
                'Pindah cabang sementara dan libur berlaku per tanggal dan tidak mengubah jadwal rutin.',
                'Hanya Owner dan Admin yang bisa mengubah jadwal.',
            ],
        ],
        [
            'title' => 'Keuangan',
            'rules' => [
                'Pemasukan dihitung dari pembayaran yang diterima; pengeluaran dari catatan pengeluaran per cabang.',
                'Fee dokter dihitung dari tindakan (sebelum diskon) pada tagihan lunas, setelah dikurangi biaya modal tindakan (mis. alat behel, x-ray). Biaya order lab dokter di bulan itu ikut dipotong dari fee; bila ada tindakan bertanda "Potong biaya lab", lab pasien dipotong saat tindakan itu lunas. Tindakan bertahap (gigi palsu, behel) dapat memakai pembayaran tahap awal (cetak/DP) pasien yang sama sebagai dasar fee. Prioritas tarif: khusus dokter × tindakan → tindakan → dokter → global.',
                'Dokter: gaji pokok + uang duduk per jam + fee. Asisten: gaji pokok + uang duduk per jam + fee % dari tindakan lunas yang didampingi (dipilih di Kasir) + lembur per jam. Admin & staf lain: gaji pokok + lembur per jam. THR dan bonus akhir tahun diisi per orang tiap bulan.',
                'Fee dibebankan ke cabang tempat tindakan; komponen lain ke cabang utama.',
                'Mencatat gaji ke pengeluaran bisa diulang; catatan bulan yang sama diganti, tidak dobel.',
                'Pengeluaran kategori "Gaji dan bagi hasil" hanya diisi otomatis dari menu Gaji.',
            ],
        ],
        [
            'title' => 'Akun & keamanan',
            'rules' => [
                'Masuk memakai username dan kata sandi. Hak akses mengikuti peran yang aktif, bukan semua peran akun.',
                'Sesi otomatis keluar bila tidak ada aktivitas melewati batas jam di pengaturan.',
                'Perubahan penting (data pasien, tagihan, jadwal, pengaturan) tercatat di jejak audit.',
            ],
        ],
        [
            'title' => 'SATUSEHAT',
            'rules' => [
                'Bila diaktifkan Owner, setiap kunjungan yang dikirim dokter ke kasir otomatis dilaporkan ke SATUSEHAT (kunjungan + diagnosis ICD-10) dalam ±5 menit.',
                'Pasien dan dokter dicocokkan lewat NIK. Kunjungan tanpa NIK atau tanpa diagnosis ICD-10 ditandai Gagal beserta alasannya, lalu bisa dikirim ulang setelah dilengkapi.',
                'Kredensial diisi per cabang. Client Secret disimpan terenkripsi dan tidak pernah ditampilkan lagi.',
                'Gagal kirim tidak mengganggu kasir; dicoba otomatis hingga 5 kali, selanjutnya lewat tombol Kirim ulang.',
                'Koreksi rekam medis setelah terkirim belum ikut dikirim ulang ke SATUSEHAT.',
            ],
        ],
        [
            'title' => 'Google Calendar',
            'rules' => [
                'Aplikasi membuat kalender "Egao – nama dokter" per dokter dan "Egao – Semua janji temu" untuk Owner, lalu mengirim undangan ke email masing-masing (hak lihat). Cukup diterima sekali.',
                'Bila diaktifkan Owner, setiap janji temu baru/berubah masuk ke kalender dokter yang dituju (atau dokter bertugas bila dokter kosong) dan kalender Owner dalam ±1 menit.',
                'Email diganti → undangan baru dikirim dan akses email lama dicabut. Dokter nonaktif → akses dicabut, kalender tidak dihapus.',
                'Janji tanpa jam dipasang pada jam buka cabang hari itu + 1 jam, selama 1 jam. Isi acara tanpa nomor HP/NIK.',
                'Janji Batal / Tidak datang dihapus dari kalender. Ubah janji dari aplikasi, bukan dari Google Calendar.',
                'Gagal kirim tidak mengganggu penyimpanan janji; dicoba otomatis hingga 5 kali, selanjutnya lewat tombol Kirim ulang.',
                'Perubahan jadwal dokter tidak otomatis memindahkan acara; tekan "Sinkron ulang janji mendatang".',
            ],
        ],
        [
            'title' => 'Harga tindakan & jam kerja',
            'rules' => [
                'Harga tindakan hanya boleh diubah Dokter dan Owner saat pemeriksaan, dan tidak boleh di bawah harga standar cabang. Potongan harga hanya lewat diskon Kasir.',
                'Bagi hasil dokter dihitung dari harga tindakan yang tercatat di tagihan (termasuk bila dinaikkan). Kenaikan harga tercatat di jejak audit.',
                'Jam kerja diatur per cabang per hari (bisa dua sesi dengan istirahat). Janji di luar jam kerja hanya diberi peringatan, tidak ditolak.',
            ],
        ],
    ];

    public const CHANGELOG = [
        [
            'date' => '24 November 2026',
            'changes' => [
                'Rekam medis › Pemeriksaan dirapikan: konfirmasi alergi dan tindakan Rp0 kini satu jendela "Periksa sebelum kirim"; bar bawah menampilkan kelengkapan (keluhan, diagnosis, tindakan, alergi, resep) dan tanda "belum disimpan", dengan konfirmasi bila meninggalkan halaman; tekanan darah divalidasi (mis. 120/80); info alergi dan daftar temuan tidak lagi tampil dobel; obat di kartu tagihan diringkas satu baris. Diperbaiki: kolom gigi di "+ Tambah tindakan" kini terisi gigi yang dipilih di peta gigi.',
            ],
        ],
        [
            'date' => '23 November 2026',
            'changes' => [
                'Jadwal dokter › Libur / izin dengan dokter pengganti: tombol Simpan tidak lagi gagal diam-diam karena sisa isian cabang/jam yang tersembunyi; bila masih ada kesalahan, pesannya tampil di dalam jendela.',
                'Jadwal dokter dengan dua sesi sehari (mis. Jumat 10.00–13.00 dan 17.00–20.00) kini tampil lengkap di Jadwal dokter, Dashboard, Antrean, dan Janji temu; sebelumnya hanya sesi pertama.',
                'Rekam medis › Pemeriksaan: tagihan dikelompokkan Tindakan / Obat dan item baru disorot; Resep jadi kartu sendiri (cari obat dengan mengetik, label stok, tombol aturan pakai 3×1/2×1/1×1 & sesudah/sebelum makan, jumlah dihitung dari lama hari); saran tindakan dari diagnosis ICD-10 (sekali klik); konfirmasi bila ada tindakan bertarif Rp0. Diperbaiki: obat di tagihan tidak lagi muncul sebagai "Tindakan Rp0" saat kunjungan dibuka lagi.',
                'Rekam medis › Tindakan & tagihan: pilih tindakan dengan mengetik nama atau kode (daftar tersaring otomatis, bisa pakai ↑ ↓ Enter), tidak perlu menggulir daftar panjang.',
                'Data dokter: tombol Hapus untuk dokter yang belum pernah punya transaksi (kunjungan, rekam medis, tagihan, janji temu, order lab, gaji); dokter yang sudah punya data tetap hanya bisa dinonaktifkan. Dokter nonaktif ditolak di server saat dijadwalkan atau dipilih sebagai pengganti.',
                'Jadwal dokter: kisi mingguan dikelompokkan per cabang — Batu di atas, dokter yang praktik di dua cabang di tengah, Malang di bawah. Dokter tanpa jadwal tetap di blok cabang utamanya, di bagian bawah.',
                'Peran baru Asisten dan Pengaturan › Hak akses peran (Owner): atur per menu untuk Admin, Asisten, dan Dokter apakah boleh Lihat, Edit, dan melihat angka Keuangan (bila tidak, rupiah tampil Rp•••). Bawaan sama dengan sebelumnya; Owner selalu akses penuh. Izin dicek di server dan perubahan tercatat di jejak audit.',
                'Master data › Tindakan & tarif kini punya kolom cari (kode, nama, kategori) dan halaman (25 tindakan per halaman).',
                'Logo baru Egao Dental Care 2024 dipasang di sidebar, halaman masuk, ikon aplikasi (PWA/favicon), dan logo bawaan kwitansi. Warna aksen hijau & kuning disesuaikan dengan logo.',
                'Pengaturan kini hanya berisi pengaturan program (Klinik & cabang, Aplikasi, SATUSEHAT, Google Calendar, Pengguna & peran, Jejak audit). Tindakan & tarif, Kategori pengeluaran, dan Tarif gaji & bagi hasil (baru, khusus Owner) dibuka dari menu Master data. Menu Pengaturan tidak lagi tampil untuk Admin.',
                'Fee bertahap gigi palsu & behel (Owner, di Tindakan & tarif): dasar fee bisa ikut pembayaran tindakan tahap awal pasien (mis. Insersi ← Cetak, Pasang behel ← Cetak behel) dan biaya lab pasien dipotong saat tindakan akhir lunas. Rincian gaji & slip menampilkan "+ tahap awal (cetak/DP)".',
            ],
        ],
        [
            'date' => '21 November 2026',
            'changes' => [
                'Menu baru "Master data" di sidebar (bisa dibuka/tutup): Data pasien, Data dokter, Master barang, Tindakan & tarif, dan Kategori pengeluaran dikumpulkan dalam satu grup.',
                'Pengaturan › Tindakan & tarif: tombol "Impor Excel" (Owner & Admin) untuk memasukkan daftar tindakan dari Excel (mis. Data Prosedur.xlsx Assist.id) ke semua cabang atau satu cabang. Nama yang sudah ada hanya diperbarui tarifnya; yang baru dibuat dengan kode otomatis.',
                'Reset darurat kata sandi di halaman login (khusus admin): tekan Alt+F7 untuk menyingkap kolom PIN; PIN benar mereset kata sandi akun (sesuai username yang diketik) menjadi "password". Fitur mati bila LOGIN_RESET_PIN di .env kosong; setiap percobaan dicatat di jejak audit.',
            ],
        ],
        [
            'date' => '20 November 2026',
            'changes' => [
                'Integrasi Google Calendar: aplikasi membuat kalender Egao per dokter + kalender Owner dan mengirim undangan otomatis; janji temu masuk ke sana, ikut berubah/terhapus saat janji diubah/dibatalkan (Pengaturan › Google Calendar).',
                'Data dokter: kolom Email Google Calendar.',
                'Rekam medis: Dokter & Owner bisa menaikkan harga tindakan (minimal harga standar); bagi hasil ikut harga baru.',
                'Jam kerja per cabang (Pengaturan › Klinik & cabang) dengan peringatan di Janji Temu bila janji di luar jam kerja.',
                'Data pasien: No. RM berformat huruf awal nama + nomor (A-021), kolom tempat lahir, tanggal lahir & telepon boleh kosong.',
                'Impor 1.347 pasien dari Assist.id (perintah egao:import-pasien, aman dijalankan ulang).',
                'Deploy otomatis Hostinger: push ke GitHub → server menarik kode, lalu backup, migrasi, dan cache diperbarui sendiri (egao:after-deploy).',
            ],
        ],
        [
            'date' => '16 November 2026',
            'changes' => [
                'Integrasi SATUSEHAT tahap 1: Pengaturan › SATUSEHAT (kredensial per cabang, Sandbox/Production, tes koneksi), pengiriman otomatis kunjungan + diagnosis ICD-10, daftar status & kirim ulang.',
                'Data dokter: kolom NIK (untuk SATUSEHAT).',
            ],
        ],
        [
            'date' => '12 November 2026',
            'changes' => [
                'Modul Antrean, Data pasien, Kasir + kwitansi PDF, Jadwal dokter, Laporan + ekspor Excel, Pengeluaran, Gaji & bagi hasil, dan Pengaturan.',
                'Pengaturan Aplikasi untuk Owner: identitas & logo, kwitansi, metode bayar, batas diskon Admin, batas idle sesi, pemeliharaan database.',
                'Halaman Tentang aplikasi.',
                'Masuk dengan username; modul Rekam medis dan Data dokter.',
                'Antrean disederhanakan menjadi 3 tab: Menunggu, Selesai tindakan, Tidak hadir (tanpa langkah Panggil); Admin & Owner bisa mengisi pemeriksaan.',
                'Gaji per peran (Dokter, Asisten, Admin): uang duduk per jam, fee asisten dari tindakan yang didampingi, lembur per jam, biaya modal tindakan & potongan lab sebelum fee dokter, THR & bonus akhir tahun, info target omzet tahunan.',
                'Catatan pasien rujukan dokter gigi luar ("Dirujuk oleh") beserta filternya di Data pasien.',
            ],
        ],
    ];
}
