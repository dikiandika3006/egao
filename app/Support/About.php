<?php

namespace App\Support;

/**
 * Konten halaman Tentang. Diedit manual setiap ada perubahan aplikasi (pola sama dengan Cattleya):
 * RULES = aturan bisnis yang berlaku, CHANGELOG = riwayat perubahan, terbaru di atas.
 */
class About
{
    public const VERSION = '1.0.0';

    /**
     * Waktu kode terakhir diperbarui: waktu ref cabang aktif di .git berubah (commit lokal / git pull deploy server).
     * Null bila .git tidak terbaca.
     */
    public static function updatedAt(): ?\Illuminate\Support\Carbon
    {
        $git = (string) (config('app.git_dir') ?: base_path('.git'));
        $head = @file_get_contents($git.'/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        $files = str_starts_with($head, 'ref:') ? [$git.'/'.trim(substr($head, 4)), $git.'/packed-refs'] : [$git.'/HEAD'];

        foreach ($files as $file) {
            if (is_file($file) && ($time = @filemtime($file))) {
                return \Illuminate\Support\Carbon::createFromTimestamp($time, config('app.timezone'));
            }
        }

        return null;
    }

    public const RULES = [
        [
            'title' => 'Pasien & cabang',
            'rules' => [
                'Satu data pasien berlaku untuk semua cabang. Pasien tidak perlu mendaftar ulang saat pindah dari Batu ke Malang atau sebaliknya.',
                'No. RM = huruf awal nama + nomor urut per huruf (mis. B-043), dibuat otomatis atau diisi manual; tidak boleh dobel. NIK tidak boleh dobel; bila sudah terdaftar, cari pasien tersebut.',
                'Tanggal lahir, tempat lahir, dan telepon pasien boleh kosong (data lama); umur tampil "umur –".',
                'Telepon dan NIK selalu disamarkan. Membuka data lengkap tercatat di jejak audit.',
                'Pengguna hanya melihat data cabang yang menjadi aksesnya. Pilihan "Semua cabang" hanya untuk Owner.',
                'Peran: Owner / Administrator (semua menu), Admin / Asisten (menu operasional, absensi diri sendiri; stok hanya melihat posisi & kartu stok tanpa harga), Dokter (menu klinis, absensi diri sendiri). Menu yang boleh dibuka, diubah, dan angka keuangannya diatur Owner per peran di Pengaturan › Hak akses peran; Owner selalu akses penuh.',
            ],
        ],
        [
            'title' => 'Antrean',
            'rules' => [
                'Nomor antrean berurutan per dokter, per cabang, per hari.',
                'Pasien yang masih aktif di antrean hari ini tidak bisa didaftarkan dua kali.',
                'Antrean: Menunggu → Selesai tindakan (otomatis setelah dokter menyimpan pemeriksaan; keterangan Belum bayar/Lunas) atau Tidak hadir. Tanpa langkah Panggil.',
                'Pasien tanpa janji tetap dilayani. Bila perlu, pasien yang menunggu dialihkan ke cabang lain atau hari lain (wajib alasan): antrean hari ini dibatalkan dan diganti janji temu di tujuan.',
                'Jadwal ulang janji temu boleh ke cabang mana pun milik klinik; alasan wajib bila tanggal, jam, cabang, atau dokter berubah, dan riwayatnya tersimpan.',
            ],
        ],
        [
            'title' => 'Rekam medis',
            'rules' => [
                'Pemeriksaan diisi dokter kunjungan (Owner boleh mengisi/mengoreksi atas nama dokter, penginput tercatat). Admin/Asisten dan dokter lain hanya membaca rekam medis.',
                'Tindakan yang dicatat dokter otomatis menjadi rincian tagihan di kasir, dengan harga sesuai tarif cabang saat itu.',
                'Pasien beralergi wajib dikonfirmasi alerginya sebelum dikirim ke kasir.',
                'Rekam medis tidak dihapus. Koreksi menjadi versi baru, versi lama tetap tersimpan.',
            ],
        ],
        [
            'title' => 'Kasir & kwitansi',
            'rules' => [
                'Diskon diberikan dokter penanggung jawab saat pemeriksaan, wajib beralasan, dan dibatasi persentase di Pengaturan. Admin/Asisten tidak bisa memberi diskon; mereka mencatat pembayaran dan mencetak kwitansi. Owner boleh memberi diskon di Kasir tanpa batas sebelum ada pembayaran.',
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
                'Tukar jadwal dua dokter (seret sel) hanya untuk satu tanggal, hari ini atau ke depan; janji temu boleh ikut ditukar.',
                'Absensi lokasi: satu tombol Check in → Clock out; cabang = cabang terdekat dalam radius (bawaan 25 m), shift dari jam kerja cabang, akurasi GPS ≤ batas setting (bawaan 50 m). Mode uji melewati cek lokasi & jam dan ditandai "Uji". Absen dokter = tanda hadir shift (uang duduk); absen asisten = upah shift & lembur per jam penuh. Koreksi hanya oleh Owner dan tercatat di audit.',
                'Hanya Owner dan Admin yang bisa mengubah jadwal.',
            ],
        ],
        [
            'title' => 'Keuangan',
            'rules' => [
                'Pemasukan dihitung dari pembayaran yang diterima; pengeluaran dari catatan pengeluaran per cabang.',
                'Fee dokter dihitung dari tindakan (sebelum diskon) pada tagihan lunas, setelah dikurangi biaya modal tindakan (mis. alat behel, x-ray). Biaya order lab dokter di bulan itu ikut dipotong dari fee; bila ada tindakan bertanda "Potong biaya lab", lab pasien dipotong saat tindakan itu lunas. Tindakan bertahap (gigi palsu, behel) dapat memakai pembayaran tahap awal (cetak/DP) pasien yang sama sebagai dasar fee. Prioritas tarif: khusus dokter × tindakan → tindakan → dokter → global.',
                'Dokter: gaji pokok + uang duduk per jam + fee. Asisten: gaji pokok + uang duduk per jam + fee % dari tindakan yang didampingi (dipilih dokter saat tindakan, maks 2 asisten per kunjungan, masing-masing fee penuh) + lembur per jam. Admin & staf lain: gaji pokok + lembur per jam. THR dan bonus akhir tahun diisi per orang tiap bulan.',
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
                'Tarif tindakan bisa berupa range (minimal–maksimal) atau satu harga. Harga tindakan bertarif range wajib diisi manual saat tindakan (tidak terisi otomatis); kirim ke kasir ditolak bila belum diisi. Harga tindakan hanya boleh diubah Dokter dan Owner saat pemeriksaan (atau lewat Mode edit selama tagihan belum dibayar): di dalam range bebas, di atas maksimal boleh (tercatat di audit), di bawah minimal hanya bila dicentang "Tidak selesai" (atau tindakan bertanda "harga boleh di bawah standar") dengan alasan wajib. Tindakan bertarif terkunci tidak bisa diubah. Potongan harga lain hanya lewat diskon Kasir.',
                'Biaya admin ditagih di setiap tagihan pemeriksaan yang ada tindakannya (bukan penjualan langsung, tindakan Rp0/garansi, atau cicilan); pasien baru ditambah biaya pendaftaran & kartu sekali. Keduanya tidak masuk bagi hasil.',
                'Bagi hasil dokter dihitung saat tindakan dikirim ke kasir (tidak menunggu lunas), dari harga setelah diskon dikurangi biaya modal, bahan resep, dan lab. Kenaikan/penurunan harga tercatat di jejak audit.',
                'Uang duduk dokter per shift hadir (ditandai Owner di Jadwal): kotor tindakan shift di bawah ambang → uang duduk dan fee shift itu 0; sama/lebih → fee %.',
                'Bahan resep tindakan memotong stok tiap genap 1 satuan; pecahan disimpan sebagai saldo pemakaian.',
                'Jam kerja diatur per cabang per hari (bisa dua sesi dengan istirahat). Janji di luar jam kerja hanya diberi peringatan, tidak ditolak.',
            ],
        ],
    ];

    public const CHANGELOG = [
        [
            'date' => '28 November 2026',
            'changes' => [
                'Nomor WhatsApp kini per cabang (Pengaturan › Klinik & cabang › Ubah) dan tampil di kwitansi cabang tersebut. WA di tab Aplikasi hanya cadangan bila WA cabang kosong.',
            ],
        ],
        [
            'date' => '27 November 2026',
            'changes' => [
                'Asisten pendamping boleh 2 per kunjungan (satu kotak pilihan dengan centang, maksimal 2); masing-masing asisten mendapat fee asisten penuh.',
                'Asisten pendamping kini dipilih saat tindakan (Pemeriksaan › Tindakan & tagihan) oleh dokter pemeriksa/Owner; pilihan hanya asisten cabang kunjungan (cabang utama atau berjadwal di cabang itu). Bisa diubah selama tagihan belum dibayar. Kasir hanya menampilkan nama asisten.',
                'Biaya admin Rp5.000 kini ditagih di setiap transaksi tindakan (sebelumnya sekali per pasien). Pasien baru ditambah biaya pendaftaran & kartu Rp5.000 sekali (kunjungan pertama total Rp10.000). Semua pasien yang sudah ada, termasuk impor Assist.id, dianggap pasien lama. Penjualan langsung, tindakan Rp0 (garansi), dan cicilan tidak kena biaya admin. Nominal keduanya diatur di Tarif gaji & bagi hasil.',
                'Tampilan tablet (lebar 768–1279 px): ukuran huruf dasar diperkecil agar tabel dan form tidak terpotong.',
                'Buku manual PDF per peran: unduh di Tentang aplikasi › Panduan & FAQ (Owner melihat semua manual dan bisa mengunggah/mengganti filenya; peran lain hanya manual perannya).',
                'Ubah kata sandi sendiri: menu profil kanan atas (atau menu ☰ di ponsel) › "Ubah kata sandi", wajib memasukkan kata sandi lama; tercatat di audit.',
                'Master user: daftar akun login (username, kata sandi, peran, cabang) pindah dari tab Pengaturan menjadi menu sendiri di grup Master data, khusus Owner.',
            ],
        ],
        [
            'date' => '26 November 2026',
            'changes' => [
                'Mode lihat / Mode edit tagihan: setelah dikirim ke kasir dan selama belum ada pembayaran, Dokter pemeriksa/Owner bisa menekan "✎ Edit" untuk mengubah tindakan, harga, barang, dan diskon langsung (tagihan tetap di Kasir, tercatat di audit).',
                'Tindakan & tagihan di Pemeriksaan kini seperti nota penjualan: gigi, jumlah, dan harga diketik langsung di tiap baris; "+ Tambah baris" untuk menambah tindakan.',
                'Tindakan bertarif range kini wajib diisi harganya manual oleh Dokter/Owner saat Pemeriksaan (tidak terisi otomatis). Draf boleh disimpan dulu; kirim ke kasir ditolak selama ada harga yang belum diisi.',
                'Pricelist 2024 diterapkan: Konsultasi Rp35.000, Cabut anak Rp200.000, Tambal anak Rp150.000–200.000, Scaling Rp200.000, Tambal gigi Rp250.000–500.000, PSA tunggal Rp250.000, PSA ganda (2-3 akar) Rp350.000; tindakan baru: Tambal + Cal LC, PSA 4-5 akar, PSA >5 akar, dan Pulpektomi/Pulpotomi anak (devitalisasi, preparasi, sterilisasi, pengisian).',
                'Kartu baru "Lain-lain" di halaman Periksa (di bawah Resep) untuk menjual barang selain obat, mis. odol atau sikat gigi. Barang diambil dari stok cabang, ikut ditagihkan ke kasir, stok berkurang saat lunas, tidak tercatat di rekam medis, dan tidak dihitung dalam bagi hasil. Kategori stok baru: "Barang jual".',
                'Tombol hamburger (☰) di kiri atas: di layar lebar menyembunyikan/menampilkan sidebar agar konten lebih lega (pilihan diingat di browser); di layar sempit membuka menu yang sama dengan sidebar. Bar bawah dan tombol "Lainnya" dihapus.',
                'Setelah masuk, halaman terakhir yang teringat hanya dibuka bila peran aktif boleh membukanya; selain itu langsung ke Dashboard (tidak lagi muncul 403).',
            ],
        ],
        [
            'date' => '25 November 2026',
            'changes' => [
                'Absensi satu tombol: Check in lalu Clock out tanpa memilih nama, tanggal, shift, atau cabang; tombolnya ada di header atas semua halaman. Cabang dideteksi dari lokasi terdekat, shift dari jam.',
                'Peran dirapikan menjadi 3: Owner / Administrator, Admin / Asisten (Admin dan Asisten digabung), dan Dokter. Admin/Asisten kini hanya membuka menu operasional (tanpa Laporan, Pengeluaran, Gaji, Master data); rekap & koreksi Absensi khusus Owner. Admin/Asisten tidak memeriksa pasien (rekam medis hanya baca) tetapi melihat omzet hari ini di Dashboard.',
                'Tab baru Pengaturan › Absensi: radius lokasi (bawaan 25 m), batas akurasi GPS, dan Mode uji (tanpa cek lokasi & jam). Radius tidak lagi diisi per cabang; koordinat tetap di Master › Cabang.',
                'Stok jadi grup menu sendiri "Manajemen stok": Posisi stok (baru), Kartu stok (gabungan Mutasi stok: semua mutasi, atau saldo berjalan bila satu barang dipilih), Pembelian & opname, dan Laporan jual-beli. Admin/Asisten hanya melihat posisi & kartu stok tanpa harga; pembelian, opname, dan laporan jual-beli khusus Owner.',
            ],
        ],
        [
            'date' => '24 November 2026',
            'changes' => [
                'Janji temu: tombol "Ubah" menjadi "Jadwal ulang". Jadwal ulang bisa pindah cabang, wajib alasan, riwayatnya tersimpan, dan bisa dipakai pada janji Batal/Tidak datang. Batal/Tidak datang bisa diberi alasan. Form janji punya tautan "Daftarkan pasien baru".',
                'Antrean: tombol "Alihkan" mengalihkan pasien yang menunggu ke cabang/hari lain menjadi janji temu.',
                'Diskon pindah ke dokter di halaman pemeriksaan (batas % diatur di Pengaturan › Aplikasi). Admin/Asisten di Kasir tidak lagi bisa memberi diskon; Owner tetap bisa.',
                'Tarif tindakan bisa berupa range (min–maks). Di Pemeriksaan, dokter bebas memilih harga di dalam range; di bawah minimal hanya dengan centang "Tidak selesai" + alasan.',
                'Jadwal dokter: seret sel ke dokter lain untuk tukar jadwal satu hari (janji temu bisa ikut ditukar). Tab baru "Asisten": jadwal rutin asisten dan centang hadir Shift Pagi/Sore.',
                'Menu baru Absensi: absen masuk/pulang per shift berbasis lokasi cabang untuk dokter & asisten, rekap bulanan, dan koreksi oleh Owner. Titik lokasi & radius diatur di Pengaturan › Cabang.',
                'Gaji asisten: upah per shift hadir (Pagi Rp15.000, Sore Rp20.000; bisa per asisten), lembur Rp5.000/jam dari absen pulang, ditambah fee 2%/nominal tindakan.',
                'Aturan Owner bagi hasil & gaji: fee dokter dan asisten dihitung dari tindakan menurut tanggal kunjungan (tidak lagi menunggu lunas), dari harga setelah diskon, dipotong biaya modal, bahan resep, dan lab. Perhitungan ulang bulan lama bisa berbeda dari slip yang sudah dibayar.',
                'Uang duduk dokter per shift: tandai hadir Shift 1/2 di Jadwal dokter; kotor shift < Rp100.000 → uang duduk Rp40.000 (fee shift itu 0), selain itu fee %.',
                'Master data › Tindakan: resep bahan per tindakan (takaran pecahan, stok otomatis), tarif terkunci (mis. Analisis kasus), harga boleh di bawah standar dengan alasan (mis. cabut tidak selesai), dan fee asisten nominal (mis. pasang behel/gigi palsu Rp8.000).',
                'Kasir: biaya admin & kartu Rp5.000 otomatis sekali per pasien; tab Piutang untuk tagihan cicilan/belum lunas; total piutang di Laporan. Stok: margin dan saran harga jual obat/produk.',
                'Rekam medis › Pemeriksaan dirapikan: konfirmasi alergi dan tindakan Rp0 kini satu jendela "Periksa sebelum kirim"; bar bawah menampilkan kelengkapan (keluhan, diagnosis, tindakan, alergi, resep) dan tanda "belum disimpan", dengan konfirmasi bila meninggalkan halaman; tekanan darah divalidasi (mis. 120/80); info alergi dan daftar temuan tidak lagi tampil dobel; obat di kartu tagihan diringkas satu baris. Diperbaiki: kolom gigi di "+ Tambah tindakan" kini terisi gigi yang dipilih di peta gigi.',
                'Rekam medis › Pemeriksaan lebih cepat: klik kondisi di panel gigi langsung mencatat temuan; temuan menyarankan diagnosis ICD-10 (Kalkulus K03.6, Karies K02.9, Gigi hilang K08.1, Impaksi K01.1, Sisa akar K08.3, Goyang K05.3); saran tindakan memakai nomor gigi temuan bila hanya satu gigi; evaluasi ulang dipilih lewat tombol.',
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
