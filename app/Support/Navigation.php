<?php

namespace App\Support;

use App\Enums\Role;

/**
 * Struktur menu sesuai SRS §2.3. Satu sumber untuk sidebar, bottom bar, route, dan halaman placeholder.
 */
class Navigation
{
    /**
     * @return array<string, array{group:string, number:?string, label:string, route:string, path:string, roles:list<Role>, title:string, subtitle:string, breadcrumb:list<string>, requirements:string}>
     */
    public static function items(): array
    {
        $all = [Role::Owner, Role::Admin, Role::Dokter];

        return [
            'dashboard' => [
                'group' => 'klinik', 'number' => '01', 'label' => 'Dashboard', 'route' => 'dashboard', 'path' => '/',
                'roles' => $all, 'title' => 'Dashboard', 'subtitle' => '',
                'breadcrumb' => ['Operasional', 'Dashboard'], 'requirements' => 'FR-DSH-01 – 07',
            ],
            'antrean' => [
                'group' => 'klinik', 'number' => '02', 'label' => 'Antrean', 'route' => 'antrean', 'path' => '/antrean',
                'roles' => $all, 'title' => 'Antrean hari ini',
                'subtitle' => 'Panggil pasien, pantau status, dan kirim ke kasir tanpa mengetik ulang.',
                'breadcrumb' => ['Operasional', 'Antrean'], 'requirements' => 'FR-ANT-01 – 11',
            ],
            'janji' => [
                'group' => 'klinik', 'number' => null, 'label' => 'Janji temu', 'route' => 'janji', 'path' => '/janji-temu',
                'roles' => $all, 'title' => 'Janji temu & kontrol',
                'subtitle' => 'Jadwal kedatangan pasien per hari. Kontrol dari dokter masuk otomatis; pasien datang → langsung ke antrean.',
                'breadcrumb' => ['Operasional', 'Janji temu'], 'requirements' => 'Permintaan klien: jadwal harian pasien',
            ],
            'pasien' => [
                'group' => 'klinik', 'number' => '03', 'label' => 'Data pasien', 'route' => 'pasien', 'path' => '/pasien',
                'roles' => $all, 'title' => 'Pasien dikenal, perawatan tersambung.',
                'subtitle' => 'Satu rekam pasien untuk Batu dan Malang. Tidak perlu mendaftar ulang saat berpindah cabang.',
                'breadcrumb' => ['Operasional', 'Data pasien'], 'requirements' => 'FR-PAS-01 – 20',
            ],
            'rekam-medis' => [
                'group' => 'klinik', 'number' => '04', 'label' => 'Rekam medis', 'route' => 'rekam-medis', 'path' => '/rekam-medis',
                'roles' => [Role::Dokter, Role::Admin, Role::Owner], 'title' => 'Pemeriksaan',
                'subtitle' => 'Odontogram, diagnosis ICD-10, tindakan, dan resep dalam satu layar.',
                'breadcrumb' => ['Operasional', 'Rekam medis', 'Kunjungan'], 'requirements' => 'FR-RM-01 – 25',
            ],
            'kasir' => [
                'group' => 'klinik', 'number' => '05', 'label' => 'Kasir', 'route' => 'kasir', 'path' => '/kasir',
                'roles' => [Role::Admin, Role::Owner], 'title' => 'Pembayaran & kwitansi',
                'subtitle' => 'Tindakan dokter otomatis menjadi rincian tagihan. Lunasi, lalu cetak kwitansi A5.',
                'breadcrumb' => ['Operasional', 'Kasir'], 'requirements' => 'FR-KSR-01 – 06, FR-CTK-01 – 03',
            ],
            'jadwal' => [
                'group' => 'klinik', 'number' => '06', 'label' => 'Jadwal dokter', 'route' => 'jadwal', 'path' => '/jadwal-dokter',
                'roles' => $all, 'title' => 'Tim yang sama, dua cabang.',
                'subtitle' => 'Jadwal rolling dokter di Batu dan Malang. Satu dokter, satu lokasi di jam yang sama.',
                'breadcrumb' => ['Operasional', 'Jadwal dokter'], 'requirements' => 'FR-JDW-01 – 11',
            ],
            'dokter' => [
                'group' => 'klinik', 'number' => '07', 'label' => 'Data dokter', 'route' => 'dokter', 'path' => '/dokter',
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Data dokter',
                'subtitle' => 'Identitas, STR/SIP, cabang utama, dan sejak kapan dokter bergabung di klinik.',
                'breadcrumb' => ['Operasional', 'Data dokter'], 'requirements' => 'FR-SET (master dokter)',
            ],
            'laporan' => [
                'group' => 'manajemen', 'number' => null, 'label' => 'Laporan', 'route' => 'laporan', 'path' => '/laporan',
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Angka yang mudah dipahami.',
                'subtitle' => 'Pemasukan, pengeluaran, dan selisih kas per cabang.',
                'breadcrumb' => ['Manajemen', 'Laporan'], 'requirements' => 'FR-LAP-01 – 10',
            ],
            'pengeluaran' => [
                'group' => 'manajemen', 'number' => null, 'label' => 'Pengeluaran', 'route' => 'pengeluaran', 'path' => '/pengeluaran',
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Pengeluaran',
                'subtitle' => 'Catat biaya operasional per cabang. Gaji & bagi hasil terisi otomatis.',
                'breadcrumb' => ['Manajemen', 'Pengeluaran'], 'requirements' => 'FR-PNG-01 – 05',
            ],
            'stok' => [
                'group' => 'manajemen', 'number' => null, 'label' => 'Stok obat & bahan', 'route' => 'stok', 'path' => '/stok',
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Stok obat, bahan & alat',
                'subtitle' => 'Pembelian menambah stok dan tercatat sebagai pengeluaran; penjualan di kasir mengurangi stok saat lunas.',
                'breadcrumb' => ['Manajemen', 'Stok'], 'requirements' => 'Permintaan klien: jual-beli obat, alat & bahan',
            ],
            'lab' => [
                'group' => 'manajemen', 'number' => null, 'label' => 'Order lab gigi', 'route' => 'lab', 'path' => '/lab-gigi',
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Order lab gigi',
                'subtitle' => 'Pekerjaan yang dikirim ke lab (mahkota, gigi tiruan, retainer): status, biaya lab, dan pembayaran ke lab.',
                'breadcrumb' => ['Manajemen', 'Order lab gigi'], 'requirements' => 'Permintaan klien: pembayaran lab gigi',
            ],
            'gaji' => [
                'group' => 'manajemen', 'number' => null, 'label' => 'Gaji & bagi hasil', 'route' => 'gaji', 'path' => '/gaji',
                'roles' => [Role::Owner], 'title' => 'Gaji & bagi hasil',
                'subtitle' => 'Tindakan kotor lintas cabang, bagi hasil, dan gaji pokok per dokter.',
                'breadcrumb' => ['Manajemen', 'Gaji & bagi hasil'], 'requirements' => 'FR-GJI-01 – 08',
            ],
            'pengaturan' => [
                'group' => 'manajemen', 'number' => null, 'label' => 'Pengaturan', 'route' => 'pengaturan', 'path' => '/pengaturan',
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Pengaturan',
                'subtitle' => 'Profil klinik, master dokter, tindakan & tarif, pengguna dan peran.',
                'breadcrumb' => ['Manajemen', 'Pengaturan'], 'requirements' => 'FR-SET-01 – 10',
            ],
            // Grup "bantuan" tidak tampil di sidebar; dibuka dari kotak "Butuh bantuan?" dan menu Lainnya.
            'tentang' => [
                'group' => 'bantuan', 'number' => null, 'label' => 'Tentang aplikasi', 'route' => 'tentang', 'path' => '/tentang',
                'roles' => $all, 'title' => 'Tentang aplikasi',
                'subtitle' => 'Info aplikasi, aturan yang berlaku di sistem, dan riwayat perubahan.',
                'breadcrumb' => ['Bantuan', 'Tentang aplikasi'], 'requirements' => '-',
            ],
        ];
    }

    public static function item(string $key): array
    {
        return static::items()[$key];
    }

    /** "role:owner,admin" untuk route modul. */
    public static function roleMiddleware(string $key): string
    {
        return 'role:'.implode(',', array_map(fn (Role $r) => $r->value, static::item($key)['roles']));
    }

    /** Menu yang terlihat untuk peran aktif. */
    public static function visible(?Role $role, ?string $group = null): array
    {
        return array_filter(
            static::items(),
            fn (array $i) => $role && in_array($role, $i['roles'], true) && (! $group || $i['group'] === $group),
        );
    }

    /**
     * Bottom bar ponsel (SRS §2.3): admin & dokter = Dashboard, Antrean, Pasien;
     * owner = Dashboard, Antrean, Keuangan, Lainnya.
     *
     * @return list<array{label:string, route:string, active:list<string>}>
     */
    public static function mobile(?Role $role): array
    {
        $base = [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => ['dashboard']],
            ['label' => 'Antrean', 'route' => 'antrean', 'active' => ['antrean']],
        ];

        if ($role === Role::Owner) {
            return [...$base,
                ['label' => 'Keuangan', 'route' => 'laporan', 'active' => ['laporan', 'pengeluaran', 'gaji']],
                ['label' => 'Lainnya', 'route' => 'lainnya', 'active' => ['lainnya', 'pasien', 'jadwal', 'dokter', 'pengaturan', 'kasir', 'rekam-medis', 'tentang']],
            ];
        }

        return [...$base, ['label' => 'Pasien', 'route' => 'pasien', 'active' => ['pasien']]];
    }
}
