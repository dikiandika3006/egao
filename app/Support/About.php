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
                'Nomor RM dibuat otomatis berurutan. NIK tidak boleh dobel; bila sudah terdaftar, cari pasien tersebut.',
                'Telepon dan NIK selalu disamarkan. Membuka data lengkap tercatat di jejak audit.',
                'Pengguna hanya melihat data cabang yang menjadi aksesnya. Pilihan "Semua cabang" hanya untuk Owner.',
            ],
        ],
        [
            'title' => 'Antrean',
            'rules' => [
                'Nomor antrean berurutan per dokter, per cabang, per hari.',
                'Pasien yang masih aktif di antrean hari ini tidak bisa didaftarkan dua kali.',
                'Alur status: Menunggu → Dipanggil → Diperiksa → Bayar → Selesai. Menunggu/Dipanggil bisa ditandai Tidak hadir.',
            ],
        ],
        [
            'title' => 'Rekam medis',
            'rules' => [
                'Dokter hanya membuka pasien miliknya sendiri; Owner bisa membuka semua pasien di cabang aksesnya.',
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
                'Bagi hasil dihitung dari tindakan kotor (sebelum diskon) pada tagihan lunas. Prioritas tarif: khusus dokter × tindakan → tindakan → dokter → global.',
                'Bagi hasil dibebankan ke cabang tempat tindakan; gaji pokok ke cabang utama dokter.',
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
    ];

    public const CHANGELOG = [
        [
            'date' => '12 November 2026',
            'changes' => [
                'Modul Antrean, Data pasien, Kasir + kwitansi PDF, Jadwal dokter, Laporan + ekspor Excel, Pengeluaran, Gaji & bagi hasil, dan Pengaturan.',
                'Pengaturan Aplikasi untuk Owner: identitas & logo, kwitansi, metode bayar, batas diskon Admin, batas idle sesi, pemeliharaan database.',
                'Halaman Tentang aplikasi.',
                'Masuk dengan username; modul Rekam medis dan Data dokter.',
            ],
        ],
    ];
}
