<?php

namespace App\Support;

/**
 * Panduan / FAQ di halaman Tentang. Bisa dicari (pertanyaan, jawaban, lokasi menu, kata kunci).
 * Tambah entri baru setiap ada fitur baru. 'where' = jalur menu yang dibuka, 'roles' = siapa yang bisa.
 */
class Faq
{
    public const ITEMS = [
        // ── Gaji & bagi hasil ─────────────────────────────────────────
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Di mana mengatur gaji pokok dokter?',
            'a' => 'Buka Pengaturan, pilih tab "Bagi hasil". Di tabel dokter, isi kolom Gaji pokok untuk masing-masing dokter lalu tekan Simpan. Nilai ini dipakai saat menghitung gaji bulanan di menu Gaji & bagi hasil.',
            'where' => 'Pengaturan › Bagi hasil',
            'roles' => 'Owner',
            'tags' => 'gaji pokok dokter salary setting upah',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Di mana mengatur persentase bagi hasil dokter?',
            'a' => 'Pengaturan › tab "Bagi hasil". Ada tiga tingkat: (1) Tarif global untuk semua dokter, (2) persentase khusus per dokter, (3) persentase per tindakan, serta (4) aturan khusus dokter + tindakan tertentu. Yang paling spesifik yang dipakai. Kosongkan kolom per dokter/tindakan agar kembali memakai tarif global.',
            'where' => 'Pengaturan › Bagi hasil',
            'roles' => 'Owner',
            'tags' => 'persen persentase komisi bagi hasil share fee dokter tindakan global',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Di mana mengatur gaji karyawan (admin, perawat)?',
            'a' => 'Buka Gaji & bagi hasil, pilih tab "Karyawan". Tekan tambah karyawan untuk mengisi nama, jabatan, cabang utama, gaji pokok, dan tanggal masuk. Untuk mengubah gaji, buka karyawan tersebut lalu ubah gaji pokoknya.',
            'where' => 'Gaji & bagi hasil › Karyawan',
            'roles' => 'Owner',
            'tags' => 'gaji pokok karyawan admin perawat staf pegawai employee',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Bagaimana menambah bonus atau potongan gaji?',
            'a' => 'Buka Gaji & bagi hasil, pilih bulan, lalu klik baris dokter atau karyawan. Pilih Bonus atau Potongan, isi nominal dan keterangan, lalu simpan. Bonus/potongan hanya berlaku untuk bulan yang dipilih.',
            'where' => 'Gaji & bagi hasil',
            'roles' => 'Owner',
            'tags' => 'bonus potongan tunjangan denda kasbon thr',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Bagaimana gaji masuk ke pengeluaran dan laporan?',
            'a' => 'Di Gaji & bagi hasil, pilih bulan lalu tekan tombol "Catat ke pengeluaran". Total gaji dan bagi hasil otomatis dicatat sebagai pengeluaran kategori "Gaji dan bagi hasil" per cabang. Pengeluaran otomatis ini tidak bisa diubah dari menu Pengeluaran; bila ada perubahan, tekan "Perbarui di pengeluaran" di menu Gaji.',
            'where' => 'Gaji & bagi hasil',
            'roles' => 'Owner',
            'tags' => 'posting gaji pengeluaran laporan otomatis',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Bagaimana mencetak slip gaji?',
            'a' => 'Owner: buka Gaji & bagi hasil, pilih bulan, lalu tekan link "PDF" di baris dokter (tab Dokter) atau karyawan (tab Karyawan). Dokter dan karyawan yang punya akun login (mis. admin) bisa membuka slip miliknya sendiri lewat tombol profil di kanan atas › "Slip gaji saya" (bulan ini dan bulan lalu); slip orang lain tidak bisa dibuka.',
            'where' => 'Gaji & bagi hasil · Profil (kanan atas)',
            'roles' => 'Owner, Dokter, Admin',
            'tags' => 'slip gaji pdf cetak print',
        ],

        // ── Dokter & jadwal ───────────────────────────────────────────
        [
            'group' => 'Dokter & jadwal',
            'q' => 'Bagaimana menambah dokter baru?',
            'a' => 'Buka Data dokter › "Tambah dokter". Isi nama, STR, SIP, cabang utama, dan tanggal bergabung. Owner juga mengisi "Akun login": pilih akun yang sudah ada atau "+ Buat akun baru" (username & kata sandi awal) agar dokter bisa login, mengisi rekam medis, dan melihat slip gajinya sendiri. Akun otomatis mendapat peran Dokter di semua cabang tanpa menghapus peran lain — misalnya Owner yang juga praktik cukup memilih akunnya sendiri. Gaji pokok & bagi hasil per dokter diatur di Pengaturan › Bagi hasil.',
            'where' => 'Data dokter',
            'roles' => 'Owner, Admin',
            'tags' => 'tambah dokter baru master dokter str sip bergabung',
        ],
        [
            'group' => 'Dokter & jadwal',
            'q' => 'Di mana melihat sejak kapan dokter bergabung?',
            'a' => 'Menu Data dokter, kolom "Bergabung sejak" beserta lama bergabung. Tanggalnya bisa diubah lewat tombol Ubah.',
            'where' => 'Data dokter',
            'roles' => 'Owner, Admin',
            'tags' => 'tanggal bergabung masuk lama kerja',
        ],
        [
            'group' => 'Dokter & jadwal',
            'q' => 'Dokter sudah tidak praktik, apakah dihapus?',
            'a' => 'Jangan dihapus agar riwayat pasien dan gaji tetap utuh. Buka Data dokter › Ubah, lalu hilangkan centang "Dokter masih aktif praktik". Dokter nonaktif bisa dilihat lewat filter Nonaktif.',
            'where' => 'Data dokter',
            'roles' => 'Owner, Admin',
            'tags' => 'nonaktif keluar resign hapus dokter',
        ],
        [
            'group' => 'Dokter & jadwal',
            'q' => 'Bagaimana mengatur jadwal praktik dokter?',
            'a' => 'Buka Jadwal dokter. Tambah jadwal rutin mingguan (hari, cabang, jam). Untuk perubahan satu hari saja (pindah cabang sementara atau libur), klik tanggalnya lalu isi pengecualian; jadwal rutin tidak berubah. Jadwal yang bentrok di jam yang sama otomatis ditolak.',
            'where' => 'Jadwal dokter',
            'roles' => 'Owner, Admin',
            'tags' => 'jadwal praktik rutin libur cuti pindah cabang shift',
        ],
        [
            'group' => 'Dokter & jadwal',
            'q' => 'Dokter jaga izin dan digantikan dokter lain, bagaimana mencatatnya?',
            'a' => 'Buka Jadwal dokter, klik tanggal dokter yang izin › pilih "Libur / izin" › isi "Digantikan oleh" dengan dokter pengganti. Sekaligus tersimpan: dokter yang izin libur, pengganti praktik di cabang & jam yang sama. Centang "Pindahkan janji temu pasien" agar janji hari itu otomatis pindah ke pengganti. Pengganti yang sedang bertugas di cabang lain pada hari itu ditolak (satu dokter, satu lokasi). Tanpa pengganti, janji temu dokter tersebut ditandai merah di menu Janji temu agar pasien dikabari.',
            'where' => 'Jadwal dokter',
            'roles' => 'Owner, Admin',
            'tags' => 'izin sakit cuti ganti pengganti dokter jaga gantikan',
        ],

        // ── Pasien, antrean & janji ──────────────────────────────────
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Bagaimana mendaftarkan pasien baru?',
            'a' => 'Buka Data pasien › tambah pasien, atau tombol "+ Daftarkan pasien" di Dashboard. Nomor RM dibuat otomatis. Data pasien berlaku di semua cabang, jadi tidak perlu daftar ulang di cabang lain.',
            'where' => 'Data pasien',
            'roles' => 'Semua peran',
            'tags' => 'pasien baru daftar registrasi nomor rm nik',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Bagaimana memasukkan pasien ke antrean hari ini?',
            'a' => 'Buka Antrean › "Daftarkan kunjungan", cari pasien, pilih dokter dan keperluan. Setelah itu pakai tombol Panggil, Mulai periksa, dan seterusnya. Pasien yang masih aktif di antrean hari ini tidak bisa didaftarkan dua kali.',
            'where' => 'Antrean',
            'roles' => 'Semua peran',
            'tags' => 'antrean kunjungan panggil nomor antri',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Bagaimana mencatat janji temu atau jadwal kontrol?',
            'a' => 'Buka Janji temu › "+ Buat janji": cari pasien, pilih tanggal, jam & dokter (opsional; bila kosong memakai dokter yang bertugas). Rencana evaluasi yang diisi dokter di rekam medis (mis. "Evaluasi 2 minggu") otomatis menjadi janji berlabel "Kontrol dokter" saat dikirim ke kasir. Pada hari-H tekan "Datang → antrean" agar pasien langsung mendapat nomor antrean; tombol ini hanya muncul di hari janjinya. Owner di "Semua cabang" memilih cabang langsung di form.',
            'where' => 'Janji temu',
            'roles' => 'Semua peran',
            'tags' => 'janji temu appointment booking kontrol reservasi',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Pasien minta dokter & jam tertentu lewat WA, bagaimana cek ketersediaannya?',
            'a' => 'Di Janji temu › "+ Buat janji", pilih tanggal: kotak "Dokter bertugas" menampilkan dokter yang praktik di cabang itu beserta jam yang sudah terisi. Klik nama dokter untuk memilihnya. Janji untuk dokter yang sama dengan jarak kurang dari 30 menit otomatis ditolak. Setelah tersimpan, tombol "Kirim WA" di daftar janji membuka WhatsApp dengan pesan konfirmasi yang sudah terisi (nama pasien, tanggal, jam, dokter) — tinggal tekan kirim.',
            'where' => 'Janji temu',
            'roles' => 'Owner, Admin',
            'tags' => 'whatsapp wa konfirmasi pengingat ketersediaan dokter jam bentrok ganda',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Kenapa nomor telepon dan NIK pasien tersamar?',
            'a' => 'Demi privasi, telepon dan NIK selalu disamarkan. Tekan tombol tampilkan di detail pasien untuk melihat data lengkap; tindakan ini tercatat di jejak audit.',
            'where' => 'Data pasien',
            'roles' => 'Semua peran',
            'tags' => 'telepon nik tersamar sensor privasi',
        ],

        // ── Rekam medis ──────────────────────────────────────────────
        [
            'group' => 'Rekam medis',
            'q' => 'Bagaimana dokter mengisi pemeriksaan?',
            'a' => 'Dari Antrean tekan "Mulai periksa", atau buka Rekam medis. Isi keluhan, odontogram, diagnosis ICD-10, tindakan, dan resep. Tindakan otomatis menjadi rincian tagihan di Kasir setelah dikirim.',
            'where' => 'Rekam medis',
            'roles' => 'Dokter, Owner',
            'tags' => 'periksa pemeriksaan odontogram diagnosis icd tindakan resep',
        ],
        [
            'group' => 'Rekam medis',
            'q' => 'Rekam medis salah, bagaimana memperbaikinya?',
            'a' => 'Rekam medis tidak bisa dihapus. Buka kunjungan tersebut dan lakukan koreksi; sistem menyimpan sebagai versi baru dan versi lama tetap tersimpan. Tagihan yang sudah dibuat tidak ikut berubah.',
            'where' => 'Rekam medis',
            'roles' => 'Dokter',
            'tags' => 'koreksi salah edit revisi versi',
        ],

        // ── Kasir ────────────────────────────────────────────────────
        [
            'group' => 'Kasir & pembayaran',
            'q' => 'Bagaimana menerima pembayaran dan mencetak kwitansi?',
            'a' => 'Buka Kasir, pilih tagihan pasien, isi pembayaran (boleh sebagian). Saat lunas, kwitansi terbit otomatis dan bisa dicetak/diunduh PDF A5.',
            'where' => 'Kasir',
            'roles' => 'Owner, Admin',
            'tags' => 'bayar pembayaran kwitansi struk cetak lunas cicil',
        ],
        [
            'group' => 'Kasir & pembayaran',
            'q' => 'Bagaimana memberi diskon?',
            'a' => 'Di Kasir, buka tagihan lalu isi diskon beserta alasannya. Diskon hanya bisa sebelum ada pembayaran. Batas diskon Admin diatur Owner di Pengaturan › Aplikasi.',
            'where' => 'Kasir · Pengaturan › Aplikasi',
            'roles' => 'Owner, Admin',
            'tags' => 'diskon potongan harga promo maksimal',
        ],
        [
            'group' => 'Kasir & pembayaran',
            'q' => 'Bagaimana menjual obat atau barang lewat kasir?',
            'a' => 'Dua cara: (1) dokter memilih obat dari stok di bagian Resep pada rekam medis — obat ikut ditagihkan; (2) di Kasir, buka tagihan yang belum dibayar › "+ Tambah obat / barang" (mis. sikat gigi ortho). Stok dicek saat ditambahkan dan baru berkurang saat tagihan lunas. Barang harus ditandai "Dijual ke pasien" dan punya harga jual di menu Stok. Penjualan obat/barang tidak dihitung dalam bagi hasil dokter.',
            'where' => 'Kasir',
            'roles' => 'Owner, Admin',
            'tags' => 'jual obat barang kasir stok',
        ],
        [
            'group' => 'Kasir & pembayaran',
            'q' => 'Bagaimana menjual barang tanpa pemeriksaan (pasien rujukan / pembeli umum)?',
            'a' => 'Di Kasir tekan "+ Penjualan langsung". Pilih pasien terdaftar atau ketik nama pembeli (mis. "drg. Rina (rujukan)"), lalu "Buat tagihan". Tambahkan barang lewat "+ Tambah obat / barang" (mis. dental floss, behel per satuan), lalu terima pembayaran seperti biasa — stok berkurang saat lunas dan kwitansi bisa dicetak. Tagihan ini tampil berlabel "Penjualan langsung" dan masuk ke Laporan › Pembayaran atas nama pembeli. Penjualan langsung yang belum berisi barang bisa dihapus. Owner di "Semua cabang" memilih cabang di form.',
            'where' => 'Kasir',
            'roles' => 'Owner, Admin',
            'tags' => 'penjualan langsung rujukan walk-in umum floss behel tanpa periksa',
        ],
        [
            'group' => 'Kasir & pembayaran',
            'q' => 'Di mana mengatur metode bayar (tunai, transfer, QRIS)?',
            'a' => 'Pengaturan › tab "Aplikasi", bagian metode bayar. Hanya metode yang aktif yang tampil di Kasir.',
            'where' => 'Pengaturan › Aplikasi',
            'roles' => 'Owner',
            'tags' => 'metode bayar tunai transfer qris debit',
        ],
        [
            'group' => 'Kasir & pembayaran',
            'q' => 'Di mana mengubah tarif tindakan?',
            'a' => 'Pengaturan › tab "Tindakan & tarif". Tarif bisa berbeda per cabang. Perubahan tarif hanya berlaku untuk tindakan baru, bukan tagihan yang sudah ada.',
            'where' => 'Pengaturan › Tindakan & tarif',
            'roles' => 'Owner, Admin',
            'tags' => 'tarif harga tindakan biaya layanan',
        ],

        // ── Stok & lab ───────────────────────────────────────────────
        [
            'group' => 'Stok & lab',
            'q' => 'Bagaimana mencatat obat atau bahan baru dan pembelian stok?',
            'a' => 'Buka Stok obat & bahan. Tab Barang untuk menambah barang (kategori, satuan, harga beli/jual, stok minimum; centang "Dijual ke pasien" bila boleh dijual). Tab Pembelian untuk mencatat barang masuk dari supplier: stok cabang bertambah, harga beli terakhir diperbarui, dan totalnya otomatis tercatat di Pengeluaran kategori "Pembelian obat & bahan". Owner di "Semua cabang" memilih "Cabang penerima" di form.',
            'where' => 'Stok obat & bahan › Barang / Pembelian',
            'roles' => 'Owner, Admin',
            'tags' => 'stok obat bahan alat beli pembelian supplier barang masuk',
        ],
        [
            'group' => 'Stok & lab',
            'q' => 'Pembelian salah input, bagaimana mengubah atau membatalkannya?',
            'a' => 'Di Stok obat & bahan › tab Pembelian, tekan "Ubah" pada riwayat pembelian. Form terisi data lama; perbaiki tanggal, pemasok, barang, jumlah, atau harga, lalu "Simpan perubahan". Selisih jumlah dicatat sebagai koreksi stok dan pengeluarannya ikut diperbarui. Untuk membatalkan seluruh pembelian, tekan "Batalkan" dan isi alasannya. Bila barangnya sudah terpakai/terjual sehingga stok tidak cukup, pengurangan ditolak; kurangi sebagian saja atau koreksi lewat stok opname.',
            'where' => 'Stok obat & bahan › Pembelian',
            'roles' => 'Owner, Admin',
            'tags' => 'ubah edit koreksi batal hapus pembelian salah input faktur',
        ],
        [
            'group' => 'Stok & lab',
            'q' => 'Bagaimana mencatat pemakaian bahan atau stock opname?',
            'a' => 'Stok obat & bahan › tab "Pemakaian & opname". "Pemakaian klinik" mengurangi stok bahan yang terpakai untuk tindakan (tidak boleh melebihi stok). "Stok opname": masukkan jumlah stok fisik di rak beserta keterangan; selisihnya dicatat sebagai penyesuaian. Keduanya tidak menambah pengeluaran (uangnya sudah tercatat saat pembelian). Riwayat semua keluar-masuk barang ada di tab Mutasi stok.',
            'where' => 'Stok obat & bahan › Pemakaian & opname',
            'roles' => 'Owner, Admin',
            'tags' => 'pemakaian opname hitung stok mutasi',
        ],
        [
            'group' => 'Stok & lab',
            'q' => 'Bagaimana mencatat order ke lab gigi dan pembayarannya?',
            'a' => 'Buka Order lab gigi › "+ Order lab": pasien, nama lab, pekerjaan (mis. mahkota PFM), gigi, tanggal kirim, perkiraan jadi, dan biaya dari lab. Perbarui status "Sudah diterima dari lab" lalu "Sudah dipasang". Saat klinik membayar tagihan lab tekan "Bayar ke lab" — otomatis tercatat sebagai pengeluaran kategori "Lab gigi" hari itu (bila salah, "Batalkan bayar" menghapus pengeluarannya). Order yang belum dibayar terlihat di kartu "Utang ke lab". Biaya ke pasien tetap ditagih lewat tindakan di rekam medis/kasir.',
            'where' => 'Order lab gigi',
            'roles' => 'Owner, Admin',
            'tags' => 'lab gigi order gigi palsu crown mahkota bayar lab utang',
        ],
        [
            'group' => 'Stok & lab',
            'q' => 'Bagaimana stok, order lab, dan gaji memengaruhi laporan keuangan?',
            'a' => 'Laporan bersifat kas (uang masuk − uang keluar). Pemasukan bertambah saat pasien membayar di Kasir (tindakan maupun obat/barang). Pengeluaran bertambah otomatis saat: mencatat pembelian stok ("Pembelian obat & bahan"), "Bayar ke lab" ("Lab gigi"), dan "Catat ke pengeluaran" di Gaji ("Gaji dan bagi hasil"). Pemakaian bahan, opname, janji temu, dan order lab yang belum dibayar tidak mengubah kas. Karena pembelian langsung dihitung sebagai pengeluaran saat dibeli, untung dari jualan obat/barang dilihat di Stok › "Laporan jual-beli" (Penjualan − HPP = laba kotor).',
            'where' => 'Laporan · Stok › Laporan jual-beli',
            'roles' => 'Owner, Admin',
            'tags' => 'laporan keuangan kas laba rugi hpp untung stok lab gaji pengeluaran',
        ],

        // ── Keuangan ─────────────────────────────────────────────────
        [
            'group' => 'Keuangan',
            'q' => 'Bagaimana mencatat pengeluaran klinik?',
            'a' => 'Buka Pengeluaran › tambah. Pilih cabang, kategori, tanggal, nominal, dan unggah bukti (opsional). Kategori bisa ditambah di Pengaturan › Kategori pengeluaran.',
            'where' => 'Pengeluaran',
            'roles' => 'Owner, Admin',
            'tags' => 'pengeluaran biaya belanja listrik sewa kategori',
        ],
        [
            'group' => 'Keuangan',
            'q' => 'Di mana melihat laporan pemasukan dan pengeluaran?',
            'a' => 'Menu Laporan. Pilih periode, lihat ringkasan kas, rincian pembayaran, dan pengeluaran per kategori. Bisa diekspor ke Excel atau PDF.',
            'where' => 'Laporan',
            'roles' => 'Owner, Admin',
            'tags' => 'laporan omzet pemasukan pendapatan excel pdf ekspor',
        ],

        // ── Akun & pengaturan ────────────────────────────────────────
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Bagaimana menambah pengguna atau mengganti password?',
            'a' => 'Pengaturan › tab "Pengguna & peran". Tambah pengguna dengan username, nama, peran (Owner/Admin/Dokter), dan cabang aksesnya. Untuk mengganti password, ubah pengguna tersebut lalu isi password baru.',
            'where' => 'Pengaturan › Pengguna & peran',
            'roles' => 'Owner',
            'tags' => 'user pengguna akun login username password kata sandi peran role',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Bagaimana pindah cabang atau berganti peran?',
            'a' => 'Gunakan tombol cabang di kanan atas untuk berpindah cabang ("Semua cabang" khusus Owner). Pengguna dengan lebih dari satu peran dapat berganti lewat tombol profil di kanan atas.',
            'where' => 'Kanan atas layar',
            'roles' => 'Semua peran',
            'tags' => 'ganti cabang pindah cabang peran role switch',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Di mana mengubah profil klinik, logo, dan data cabang?',
            'a' => 'Pengaturan › tab "Klinik & cabang" untuk nama, alamat, jam buka, dan nomor awal kwitansi tiap cabang. Logo, tagline, kontak, dan lama sesi login ada di tab "Aplikasi".',
            'where' => 'Pengaturan › Klinik & cabang / Aplikasi',
            'roles' => 'Owner',
            'tags' => 'profil klinik logo alamat jam buka cabang kwitansi',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Kenapa saya otomatis keluar (logout)?',
            'a' => 'Demi keamanan, sesi berakhir bila tidak ada aktivitas selama waktu tertentu. Lamanya diatur Owner di Pengaturan › Aplikasi.',
            'where' => 'Pengaturan › Aplikasi',
            'roles' => 'Owner',
            'tags' => 'logout keluar sendiri sesi habis timeout',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Siapa yang mengubah data? Di mana melihat riwayatnya?',
            'a' => 'Pengaturan › tab "Jejak audit" mencatat siapa mengubah apa dan kapan.',
            'where' => 'Pengaturan › Jejak audit',
            'roles' => 'Owner',
            'tags' => 'audit log riwayat siapa mengubah',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Saya di "Semua cabang", kenapa harus memilih cabang saat menyimpan?',
            'a' => 'Owner masuk otomatis ke "Semua cabang". Untuk mencatat sesuatu yang terikat cabang (daftar antrean, pembelian stok, pemakaian bahan, janji temu, order lab), form akan menampilkan pilihan Cabang. Pilih cabangnya lalu simpan; tidak perlu pindah cabang di kanan atas.',
            'where' => 'Form Antrean, Stok, Janji temu, Order lab',
            'roles' => 'Owner',
            'tags' => 'semua cabang pilih cabang tombol tidak bisa simpan pembelian daftar antrean disabled',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Apa fungsi tombol "Update database"?',
            'a' => 'Dipakai setelah aplikasi diperbarui ke versi baru: menjalankan perubahan struktur database yang tertunda dan membersihkan cache. Data yang ada tidak dihapus dan data contoh tidak dimasukkan. Jumlah migrasi tertunda terlihat di atas tombol.',
            'where' => 'Pengaturan › Aplikasi › Pemeliharaan',
            'roles' => 'Owner',
            'tags' => 'update database migrasi cache pemeliharaan versi baru',
        ],
    ];

    /** Cari di pertanyaan, jawaban, lokasi menu, dan kata kunci; semua kata harus cocok. */
    public static function search(string $term): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($term))));

        if (! $words) {
            return static::ITEMS;
        }

        return array_values(array_filter(static::ITEMS, function (array $i) use ($words) {
            $haystack = mb_strtolower(implode(' ', [$i['q'], $i['a'], $i['where'], $i['group'], $i['tags']]));

            foreach ($words as $w) {
                if (! str_contains($haystack, $w)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
