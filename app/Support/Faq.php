<?php

namespace App\Support;

/**
 * Panduan / FAQ di halaman Tentang. Bisa dicari (pertanyaan, jawaban, lokasi menu, kata kunci).
 * Tambah entri baru setiap ada fitur baru. 'where' = jalur menu yang dibuka, 'roles' = siapa yang bisa.
 */
class Faq
{
    public const ITEMS = [
        // ── Google Calendar ───────────────────────────────────────────
        [
            'group' => 'Google Calendar',
            'q' => 'Menyambungkan Google Calendar',
            'a' => 'Langkah sekali saja: (1) Buka console.cloud.google.com, buat project, lalu di "APIs & Services › Library" aktifkan Google Calendar API (gratis). (2) Di "IAM & Admin › Service Accounts" buat service account, buka tab Keys › Add key › Create new key › JSON, lalu unduh filenya. (3) Serahkan file JSON itu ke pengelola server untuk dipasang di storage/app/google/calendar.json; jangan dikirim lewat grup chat. (4) Isi email Google tiap dokter di Data Dokter. (5) Di Pengaturan › tab "Google Calendar", isi email Owner, centang Aktifkan, lalu Simpan. Aplikasi otomatis membuat kalender "Egao – nama dokter" untuk tiap dokter dan "Egao – Semua janji temu" untuk Owner, lalu Google mengirim email undangan. (6) Google sering tidak mengirim email undangan dari Service Account. Karena itu, di Pengaturan › Google Calendar tekan "Salin link kalender" pada tiap orang, lalu kirim link itu ke orangnya (misalnya lewat WA). Ia cukup membukanya sekali saat login dengan Gmail yang terdaftar, lalu menekan Tambahkan; kalender langsung muncul di Google Calendar-nya. Link hanya berfungsi untuk email yang terdaftar.',
            'where' => 'Pengaturan › Google Calendar',
            'roles' => 'Owner',
            'tags' => 'google calendar kalender gmail service account sinkron jadwal janji temu integrasi',
        ],
        [
            'group' => 'Google Calendar',
            'q' => 'Janji temu masuk ke kalender siapa saja?',
            'a' => 'Ke kalender "Egao – nama dokter" milik dokter yang dipilih di janji temu. Bila dokter dikosongkan ("Dokter yang bertugas"), janji masuk ke kalender semua dokter yang terjadwal di cabang dan tanggal itu. Semua janji juga masuk ke kalender "Egao – Semua janji temu" yang dibagikan ke Owner. Dokter yang dinonaktifkan atau emailnya dihapus otomatis kehilangan akses, tetapi kalendernya tidak dihapus. Janji tanpa jam dipasang pada jam buka cabang hari itu + 1 jam (mis. 11.00), selama 1 jam. Isi acara hanya nama pasien, keperluan, cabang, dan dokter, tanpa nomor HP/NIK. Janji kontrol dari rekam medis juga ikut dikirim.',
            'where' => 'Janji temu',
            'roles' => 'Owner, Admin',
            'tags' => 'google calendar kalender dokter owner penerima jam default janji kontrol',
        ],
        [
            'group' => 'Google Calendar',
            'q' => 'Janji diubah atau dibatalkan, apakah kalendernya ikut berubah?',
            'a' => 'Ya. Mengubah tanggal, jam, dokter, atau keperluan akan memperbarui acara dalam ±1 menit. Bila dokter diganti, acara di kalender dokter lama dihapus. Janji yang Batal atau Tidak datang dihapus dari kalender. Ubah dan batalkan janji selalu dari aplikasi, bukan langsung di Google Calendar. Bila jadwal dokter berubah, tekan "Sinkron ulang janji mendatang" di Pengaturan › Google Calendar. Status pengiriman terlihat di bawah nama pasien di Janji temu.',
            'where' => 'Janji temu · Pengaturan › Google Calendar',
            'roles' => 'Owner, Admin',
            'tags' => 'google calendar ubah batal hapus sinkron ulang gagal kirim ulang',
        ],

        // ── SATUSEHAT ─────────────────────────────────────────────────
        [
            'group' => 'SATUSEHAT',
            'q' => 'Bagaimana menghubungkan aplikasi ke SATUSEHAT?',
            'a' => 'Ambil Organization ID, Client ID, dan Client Secret dari satusehat.kemkes.go.id/platform (menu Kode Akses API). Buka Pengaturan › tab "SATUSEHAT", pilih Sandbox untuk uji coba atau Production untuk data asli, isi kredensial di kartu cabang, centang "Aktifkan pengiriman", lalu Simpan. Tekan "Tes koneksi": bila muncul "Terhubung ke …" berarti sudah benar. Pastikan NIK pasien dan NIK dokter terisi.',
            'where' => 'Pengaturan › SATUSEHAT',
            'roles' => 'Owner',
            'tags' => 'satusehat satu sehat kemenkes kemkes integrasi client id secret organization sandbox production fhir',
        ],
        [
            'group' => 'SATUSEHAT',
            'q' => 'Kapan kunjungan dikirim ke SATUSEHAT?',
            'a' => 'Otomatis setelah dokter (atau Admin/Owner) menekan Kirim ke kasir di Rekam medis. Kunjungan masuk antrean lalu dikirim dalam ±5 menit oleh jadwal server. Yang dikirim: data kunjungan (pasien, dokter, waktu, cabang) dan diagnosis ICD-10. Statusnya terlihat di Pengaturan › SATUSEHAT › Pengiriman kunjungan.',
            'where' => 'Pengaturan › SATUSEHAT',
            'roles' => 'Owner',
            'tags' => 'satusehat kirim otomatis encounter diagnosis icd jadwal antrean status',
        ],
        [
            'group' => 'SATUSEHAT',
            'q' => 'Kenapa kunjungan gagal terkirim ke SATUSEHAT?',
            'a' => 'Lihat kolom Keterangan di Pengaturan › SATUSEHAT. Penyebab umum: NIK pasien atau NIK dokter belum diisi atau salah (perbaiki di Data pasien / Data dokter), kunjungan tanpa diagnosis ICD-10, kredensial salah, atau internet server terputus. Setelah diperbaiki tekan "Kirim ulang" atau "Kirim semua yang tertunda". Gagal kirim tidak memengaruhi tagihan dan kasir.',
            'where' => 'Pengaturan › SATUSEHAT',
            'roles' => 'Owner',
            'tags' => 'satusehat gagal error kirim ulang nik tidak ditemukan diagnosis',
        ],

        // ── Gaji & bagi hasil ─────────────────────────────────────────
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Di mana mengatur gaji pokok dokter?',
            'a' => 'Buka menu Master data › Tarif gaji & bagi hasil. Di tabel dokter, isi kolom Gaji pokok untuk masing-masing dokter lalu tekan Simpan. Nilai ini dipakai saat menghitung gaji bulanan di menu Gaji & bagi hasil.',
            'where' => 'Master data › Tarif gaji & bagi hasil',
            'roles' => 'Owner',
            'tags' => 'gaji pokok dokter salary setting upah',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Di mana mengatur persentase bagi hasil dokter?',
            'a' => 'Menu Master data › Tarif gaji & bagi hasil. Ada tiga tingkat: (1) Tarif global untuk semua dokter, (2) persentase khusus per dokter, (3) persentase per tindakan, serta (4) aturan khusus dokter + tindakan tertentu. Yang paling spesifik yang dipakai. Kosongkan kolom per dokter/tindakan agar kembali memakai tarif global.',
            'where' => 'Master data › Tarif gaji & bagi hasil',
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
            'a' => 'Buka Gaji & bagi hasil, pilih bulan, lalu klik baris dokter atau karyawan. Pilih jenisnya (Bonus, THR, Bonus akhir tahun, atau Potongan), isi nominal dan keterangan, lalu simpan. Semua jenis selain Potongan menambah gaji, dan hanya berlaku untuk bulan yang dipilih. Bila target omzet tahunan diisi di Pengaturan, menu Gaji menampilkan apakah target sudah tercapai sebagai dasar memberi bonus akhir tahun.',
            'where' => 'Gaji & bagi hasil',
            'roles' => 'Owner',
            'tags' => 'bonus potongan tunjangan denda kasbon thr',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Bagaimana aturan gaji dokter, asisten, dan admin?',
            'a' => 'Atur di menu Master data › Tarif gaji & bagi hasil (tiga kartu: Dokter, Asisten, Admin & staf lain). Dokter: gaji pokok + uang duduk (UD) per jam + fee tindakan (tarif global, mis. 35%). Fee dihitung setelah harga tindakan dipotong "Biaya modal" (isi di tabel Per tindakan, mis. alat behel atau x-ray) dan biaya order lab dokter itu di bulan tersebut. Asisten (jabatan "Asisten dokter"): gaji pokok + UD per jam + fee % dari tindakan lunas yang ia dampingi + lembur per jam. Admin & jabatan lain: gaji pokok + lembur per jam. THR dan bonus akhir tahun diisi per orang di menu Gaji. Nominal yang dibiarkan 0 tidak dipakai.',
            'where' => 'Master data › Tarif gaji & bagi hasil',
            'roles' => 'Owner',
            'tags' => 'ud uang duduk per jam fee asisten lembur admin biaya modal lab behel xray 35% 2% thr bonus akhir tahun target omzet',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Bagaimana fee gigi palsu dan behel dihitung (DP cetak, insersi, pemasangan)?',
            'a' => 'Atur di Master data › Tindakan & tarif (khusus Owner), buka tindakan lalu isi "Aturan fee bertahap". Gigi palsu: beri tindakan Cetak fee 0% (DP ditahan), lalu pada tindakan Insersi pilih "Dasar fee ikut pembayaran tindakan" = Cetak dan centang "Potong biaya lab". Saat insersi lunas, fee = % × (DP cetak + pelunasan − biaya order lab pasien). Behel: Cetak behel diberi biaya modal Rp100.000 (analisis kasus) sehingga fee 35% × Rp200.000; Pasang behel diberi fee 10% dan dasar ikut Cetak behel, sehingga fee = 10% × (harga pasang + cetak). Pembayaran tahap awal & lab dihitung sejak tindakan akhir sebelumnya pasien yang sama, jadi kasus lama tidak terhitung dua kali. Bila sudah ada tindakan bertanda "Potong biaya lab", biaya lab tidak lagi dipotong di bulan order dikirim, melainkan saat tindakan itu lunas.',
            'where' => 'Master data › Tindakan & tarif',
            'roles' => 'Owner',
            'tags' => 'gigi palsu gigi tiruan gtsl gtl gtj insersi cetak dp behel ortho pasang analisis kasus lab 10% 35% fee bertahap',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Di mana mengisi jam hadir dan jam lembur?',
            'a' => 'Buka Gaji & bagi hasil, pilih bulan, lalu klik baris dokter atau karyawan. Isi total jam hadir (untuk UD per jam; khusus dokter dan asisten) dan jam lembur (asisten dan admin) bulan itu, lalu tekan "Simpan jam". Boleh desimal, mis. 40,5.',
            'where' => 'Gaji & bagi hasil',
            'roles' => 'Owner',
            'tags' => 'jam hadir kehadiran absensi lembur overtime ud uang duduk',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Bagaimana memilih asisten pendamping supaya fee asisten terhitung?',
            'a' => 'Di Kasir, buka tagihan kunjungan pasien. Di bawah nama dokter ada pilihan "Asisten"; pilih asisten yang mendampingi. Pilihan ini bisa diubah sebelum atau sesudah lunas dan tercatat di jejak audit. Fee asisten dihitung dari tindakan pada tagihan yang sudah lunas. Hanya karyawan berjabatan "Asisten dokter" yang muncul di pilihan.',
            'where' => 'Kasir',
            'roles' => 'Admin, Owner',
            'tags' => 'asisten pendamping fee asisten kasir dampingi',
        ],
        [
            'group' => 'Gaji & bagi hasil',
            'q' => 'Bagaimana gaji masuk ke pengeluaran dan laporan?',
            'a' => 'Di Gaji & bagi hasil, pilih bulan lalu tekan tombol "Catat ke pengeluaran". Total gaji dan bagi hasil otomatis dicatat sebagai pengeluaran kategori "Gaji dan bagi hasil" per cabang. Bila setting gaji atau biaya modal diubah lalu bulan lama dicatat ulang, nilainya ikut berubah. Pengeluaran otomatis ini tidak bisa diubah dari menu Pengeluaran; bila ada perubahan, tekan "Perbarui di pengeluaran" di menu Gaji.',
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
            'q' => 'Di mana mengatur jam kerja cabang?',
            'a' => 'Pengaturan › Klinik & cabang › Ubah pada cabang. Jam kerja diatur per hari dan per cabang: Sesi 1, Sesi 2 (opsional, untuk jam setelah istirahat), atau centang Tutup. Bawaannya Senin–Jumat 10.00–13.00 & 15.00–20.00, Sabtu–Minggu 10.00–17.00. Bila janji temu dibuat di luar jam kerja, di jam istirahat, atau di hari tutup, muncul peringatan kuning; janji tetap bisa disimpan. Janji tanpa jam masuk Google Calendar pada jam buka cabang hari itu + 1 jam.',
            'where' => 'Pengaturan › Klinik & cabang',
            'roles' => 'Owner',
            'tags' => 'jam kerja jam buka operasional praktik istirahat tutup libur cabang janji peringatan',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Bagaimana mendaftarkan pasien baru?',
            'a' => 'Buka Data pasien › tambah pasien, atau tombol "+ Daftarkan pasien" di Dashboard. Hanya nama dan jenis kelamin yang wajib; tanggal lahir, tempat lahir, telepon, dan NIK boleh dilengkapi belakangan. Data pasien berlaku di semua cabang, jadi tidak perlu daftar ulang di cabang lain.',
            'where' => 'Data pasien',
            'roles' => 'Semua peran',
            'tags' => 'pasien baru daftar registrasi nomor rm nik',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Bagaimana aturan nomor rekam medis (No. RM)?',
            'a' => 'No. RM = huruf awal nama + nomor urut per huruf, mis. "Budi" → B-043 (gelar dr./drg. diabaikan). Kosongkan kolom No. RM saat mendaftar agar dibuat otomatis, atau ketik sendiri bila pasien sudah punya nomor; nomor yang sudah dipakai ditolak. Pencarian bisa memakai "b43", "B-043", atau nama. Pasien contoh lama tetap tampil "RM-00128".',
            'where' => 'Data pasien',
            'roles' => 'Admin, Owner',
            'tags' => 'nomor rm rekam medis format huruf ubah nomor pasien',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Data pasien dari Assist.id sudah dipindahkan?',
            'a' => 'Ya, 1.347 pasien Assist.id sudah diimpor dengan No. RM aslinya. Tanggal lahir "31-12-1999" di Assist.id dianggap tidak diketahui, jadi umur tampil "umur –"; lengkapi tanggal lahir saat pasien datang. NIK yang tidak 16 digit atau dobel dikosongkan. Dua No. RM ganda diberi nomor baru: Makna Alam Pratama → M-138 dan Zaky Taffana Gemilang → Z-017.',
            'where' => 'Data pasien',
            'roles' => 'Semua peran',
            'tags' => 'assist.id import impor migrasi data lama umur kosong tanggal lahir',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Bagaimana mengimpor pasien dari Excel Assist.id?',
            'a' => 'Khusus Owner: buka Data pasien › tombol "Impor dari Excel", pilih file Data Pasien.xlsx hasil ekspor Assist.id, lalu tekan Impor. No. RM yang sudah ada otomatis dilewati sehingga aman diulang; No. RM ganda diberi nomor baru, dan NIK yang tidak 16 digit atau dobel dikosongkan. Setelah selesai muncul ringkasan dan tombol "Unduh laporan detail (CSV)" berisi tindakan per baris. Impor juga bisa lewat perintah server egao:import-pasien.',
            'where' => 'Data pasien',
            'roles' => 'Owner',
            'tags' => 'impor import excel xlsx assist.id upload unggah pasien massal',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Bagaimana mengimpor daftar tindakan & tarif dari Excel?',
            'a' => 'Buka menu Master data › Tindakan & tarif › tombol "Impor Excel". Pilih file (.xlsx/.xls/.csv) dengan kolom Nama Prosedur dan Total Harga (seperti Data Prosedur.xlsx dari Assist.id; kolom Kode & Kategori opsional), lalu pilih "Terapkan tarif ke": Semua cabang atau satu cabang. Tindakan dengan nama yang sudah ada hanya diperbarui tarifnya di cabang terpilih; yang belum ada dibuat baru dengan kode otomatis. Baris tanpa harga dilewati. Tagihan yang sudah dibuat tidak berubah.',
            'where' => 'Master data › Tindakan & tarif',
            'roles' => 'Owner, Admin',
            'tags' => 'impor import excel xlsx upload unggah tindakan prosedur tarif harga massal assist.id',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Di mana menu Data pasien, Data dokter, barang, dan tarif tindakan?',
            'a' => 'Semuanya dikumpulkan di grup menu "Master data" di sidebar: Data pasien, Data dokter, Master barang (daftar barang di menu Stok), Tindakan & tarif, Kategori pengeluaran, dan Tarif gaji & bagi hasil (khusus Owner). Klik judul "Master data" untuk membuka atau menutup grup; pilihan ini diingat di browser. Di ponsel, menu ini ada di Lainnya. Dokter hanya melihat Data pasien.',
            'where' => 'Sidebar › Master data',
            'roles' => 'Semua peran',
            'tags' => 'master data menu sidebar grup pasien dokter barang tindakan tarif kategori pengeluaran gaji bagi hasil persen pengaturan letak',
        ],
        [
            'group' => 'Pasien, antrean & janji',
            'q' => 'Bagaimana memasukkan pasien ke antrean hari ini?',
            'a' => 'Buka Antrean › "Daftarkan kunjungan", cari pasien, pilih dokter dan keperluan. Antrean hanya punya 3 tab: Menunggu, Selesai tindakan (sudah diperiksa dokter, lanjut/sudah bayar), dan Tidak hadir. Admin cukup mendaftarkan dan menandai Tidak hadir; dokter menekan Periksa, dan setelah pemeriksaan disimpan pasien otomatis pindah ke Selesai tindakan. Pasien yang masih aktif di antrean hari ini tidak bisa didaftarkan dua kali.',
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
            'a' => 'Dari Antrean tekan "Periksa", atau buka Rekam medis. Yang bisa mengisi: dokter kunjungan, Admin (cabangnya sendiri, mis. bila dokter mendikte), dan Owner; penginput tercatat. Isi keluhan, odontogram, diagnosis ICD-10, tindakan, dan resep. Tindakan otomatis menjadi rincian tagihan di Kasir setelah dikirim.',
            'where' => 'Rekam medis',
            'roles' => 'Dokter, Admin, Owner',
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
        [
            'group' => 'Rekam medis',
            'q' => 'Bisakah harga tindakan diubah saat pemeriksaan?',
            'a' => 'Bisa, khusus Dokter dan Owner. Di bagian Tindakan & tagihan tekan "Ubah harga" pada tindakan, lalu isi harga baru. Harga hanya boleh sama atau lebih tinggi dari harga standar cabang; lebih rendah ditolak (potongan harga dilakukan lewat diskon di Kasir). Kosongkan isian untuk kembali ke harga standar. Tindakan yang dinaikkan diberi label "standar Rp …", tercatat di jejak audit, dan bagi hasil dokter dihitung dari harga yang sudah dinaikkan. Admin tidak bisa mengubah harga, tetapi kenaikan dari dokter tetap terjaga saat Admin menyimpan.',
            'where' => 'Rekam medis › Tindakan & tagihan',
            'roles' => 'Dokter, Owner',
            'tags' => 'harga tindakan ubah naik edit tarif standar bagi hasil',
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
            'a' => 'Di Kasir tekan "+ Penjualan langsung". Pilih pasien terdaftar atau ketik nama pembeli (mis. "drg. Rina (rujukan)"), lalu "Buat tagihan". Tambahkan barang lewat "+ Tambah obat / barang" (mis. dental floss, behel per satuan), lalu terima pembayaran seperti biasa — stok berkurang saat lunas dan kwitansi bisa dicetak. Tagihan ini tampil berlabel "Penjualan langsung" dan masuk ke Laporan › Pembayaran atas nama pembeli. Penjualan langsung yang belum berisi barang bisa dihapus. Owner di "Semua cabang" memilih cabang di form. Tidak ada komisi untuk perujuk; kwitansi dicetak hanya bila pembeli memintanya.',
            'where' => 'Kasir',
            'roles' => 'Owner, Admin',
            'tags' => 'penjualan langsung rujukan walk-in umum floss behel tanpa periksa',
        ],
        [
            'group' => 'Kasir & pembayaran',
            'q' => 'Bagaimana mencatat pasien rujukan dokter gigi luar?',
            'a' => 'Di Data pasien, saat menambah atau mengubah pasien, isi kolom "Dirujuk oleh" (mis. drg. Budi – Klinik Sehat). Pasien ini tampil dengan tanda "Rujukan", bisa disaring lewat pilihan "Rujukan drg luar" di Data pasien, dan namanya tampil berlabel rujukan di Kasir saat membeli behel lewat Penjualan langsung.',
            'where' => 'Data pasien · Kasir',
            'roles' => 'Admin, Owner',
            'tags' => 'rujukan perujuk drg luar behel pasien rujukan catatan',
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
            'a' => 'Menu Master data › Tindakan & tarif. Tarif bisa berbeda per cabang. Perubahan tarif hanya berlaku untuk tindakan baru, bukan tagihan yang sudah ada.',
            'where' => 'Master data › Tindakan & tarif',
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
            'a' => 'Buka Pengeluaran › tambah. Pilih cabang, kategori, tanggal, nominal, dan unggah bukti (opsional). Kategori bisa ditambah di menu Master data › Kategori pengeluaran.',
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
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Bagaimana backup database dan mengunduh file backup?',
            'a' => 'Buka Bantuan & aturan › tab "Tentang & aturan", gulir ke bagian "Backup & restore database", masukkan PIN, lalu tekan "Buat backup & unduh". Backup otomatis juga berjalan setiap hari (default jam 02.00) dan tampil di riwayat. Simpan salinan file backup di tempat lain (flashdisk/Google Drive). PIN diatur oleh pengelola server (BACKUP_PIN di .env).',
            'where' => 'Bantuan & aturan › Tentang & aturan',
            'roles' => 'Owner',
            'tags' => 'backup cadangan database unduh download pin simpan data',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Bagaimana mengembalikan (restore) data dari backup?',
            'a' => 'Di panel Backup & restore (setelah memasukkan PIN), pilih "Pilih untuk restore" pada file di riwayat atau upload file .sql/.sql.gz, ketik RESTORE, lalu tekan "Restore database". Seluruh data diganti dengan isi backup — data setelah tanggal backup hilang. Sistem otomatis membuat backup "sebelum restore" dulu, dan semua pengguna harus login ulang setelahnya.',
            'where' => 'Bantuan & aturan › Tentang & aturan',
            'roles' => 'Owner',
            'tags' => 'restore pulihkan kembalikan data backup hilang rusak',
        ],
        [
            'group' => 'Akun & pengaturan',
            'q' => 'Bagaimana aplikasi di server diperbarui?',
            'a' => 'Otomatis. Setiap perubahan program yang dikirim ke GitHub langsung diambil server Hostinger. Paling lambat 1–2 menit kemudian sistem membuat backup (bila ada perubahan struktur data), memperbarui database, dan menyegarkan tampilan sendiri — tidak perlu login ke server. Bila halaman terasa belum berubah, tekan Ctrl+F5.',
            'where' => '—',
            'roles' => 'Owner',
            'tags' => 'update perbarui deploy server hostinger github versi baru',
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
