<?php

namespace App\Support;

use App\Enums\Role;

/**
 * Struktur menu sesuai SRS §2.3. Satu sumber untuk sidebar, bottom bar, route, dan halaman placeholder.
 */
class Navigation
{
    /** Urutan & judul grup menu (sidebar dan menu Lainnya). Grup "bantuan" sengaja tidak ditampilkan. */
    public const GROUPS = ['klinik' => 'Ruang klinik', 'master' => 'Master data', 'manajemen' => 'Manajemen'];

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
                'group' => 'master', 'number' => '03', 'label' => 'Data pasien', 'route' => 'pasien', 'path' => '/pasien',
                'roles' => $all, 'title' => 'Pasien dikenal, perawatan tersambung.',
                'subtitle' => 'Satu rekam pasien untuk Batu dan Malang. Tidak perlu mendaftar ulang saat berpindah cabang.',
                'breadcrumb' => ['Master data', 'Data pasien'], 'requirements' => 'FR-PAS-01 – 20',
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
            'absensi' => [
                'group' => 'klinik', 'number' => null, 'label' => 'Absensi', 'route' => 'absensi', 'path' => '/absensi',
                'roles' => $all, 'title' => 'Absensi shift',
                'subtitle' => 'Absen masuk & pulang per shift dari lokasi klinik. Dasar uang duduk dokter, upah shift & lembur asisten.',
                'breadcrumb' => ['Operasional', 'Absensi'], 'requirements' => 'Permintaan klien: absensi berbasis lokasi',
            ],
            'dokter' => [
                'group' => 'master', 'number' => '07', 'label' => 'Data dokter', 'route' => 'dokter', 'path' => '/dokter',
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Data dokter',
                'subtitle' => 'Identitas, STR/SIP, cabang utama, dan sejak kapan dokter bergabung di klinik.',
                'breadcrumb' => ['Master data', 'Data dokter'], 'requirements' => 'FR-SET (master dokter)',
            ],
            // Pintasan grup "Master data" ke tab modul lain: tanpa route sendiri (lihat modules()).
            'master-barang' => [
                'group' => 'master', 'number' => null, 'label' => 'Master barang', 'route' => 'stok', 'path' => '/stok',
                'query' => ['tab' => 'barang'], 'shortcut' => true,
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Master barang', 'subtitle' => '',
                'breadcrumb' => ['Master data', 'Master barang'], 'requirements' => '-',
            ],
            'tindakan' => [
                'group' => 'master', 'number' => null, 'label' => 'Tindakan & tarif', 'route' => 'pengaturan', 'path' => '/pengaturan',
                'query' => ['tab' => 'tindakan'], 'shortcut' => true,
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Tindakan & tarif', 'subtitle' => '',
                'breadcrumb' => ['Master data', 'Tindakan & tarif'], 'requirements' => '-',
            ],
            'kategori-pengeluaran' => [
                'group' => 'master', 'number' => null, 'label' => 'Kategori pengeluaran', 'route' => 'pengaturan', 'path' => '/pengaturan',
                'query' => ['tab' => 'kategori'], 'shortcut' => true,
                'roles' => [Role::Owner, Role::Admin], 'title' => 'Kategori pengeluaran', 'subtitle' => '',
                'breadcrumb' => ['Master data', 'Kategori pengeluaran'], 'requirements' => '-',
            ],
            'tarif-gaji' => [
                'group' => 'master', 'number' => null, 'label' => 'Tarif gaji & bagi hasil', 'route' => 'pengaturan', 'path' => '/pengaturan',
                'query' => ['tab' => 'bagi-hasil'], 'shortcut' => true,
                'roles' => [Role::Owner], 'title' => 'Tarif gaji & bagi hasil',
                'subtitle' => 'Bagi hasil global, per dokter, per tindakan, gaji pokok, dan komponen gaji per peran.',
                'breadcrumb' => ['Master data', 'Tarif gaji & bagi hasil'], 'requirements' => 'FR-GJI',
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
                // Route tetap Owner+Admin (pintasan Master data milik Admin membuka tab di sini); menunya hanya untuk Owner.
                'roles' => [Role::Owner, Role::Admin], 'menu_roles' => [Role::Owner], 'title' => 'Pengaturan',
                'subtitle' => 'Pengaturan program: profil klinik & cabang, aplikasi, integrasi, pengguna dan peran.',
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

    /** Hanya modul asli (punya halaman & route); pintasan tab tidak termasuk. */
    public static function modules(): array
    {
        return array_filter(static::items(), fn (array $i) => empty($i['shortcut']));
    }

    /** URL menu; pintasan membawa query tab-nya. */
    public static function url(array $item): string
    {
        return route($item['route'], $item['query'] ?? []);
    }

    /**
     * Menu tersorot? Pintasan aktif bila halaman induknya terbuka di tab yang sama;
     * modul induk tidak ikut tersorot selama tab itu milik pintasan.
     * Tanpa ?tab=, tab bawaan modul induk dipakai (Stok → barang).
     */
    public static function isActive(string $key, array $item, ?string $active, ?string $tab = null): bool
    {
        $tab ??= request()->query('tab') ?? (static::DEFAULT_TABS[$active] ?? null);
        $owner = fn (array $i) => ! empty($i['shortcut']) && $i['route'] === $active && $tab === ($i['query']['tab'] ?? null);

        if (! empty($item['shortcut'])) {
            return $owner($item);
        }

        return $key === $active && ! array_filter(static::items(), $owner);
    }

    /** Tab bawaan modul yang punya pintasan (sama dengan default properti $tab di komponennya). */
    private const DEFAULT_TABS = ['stok' => 'barang'];

    /**
     * Middleware route modul: "menu:<key>" memeriksa izin Lihat (Access).
     * 'roles' pada item = peran bawaan sebelum hak akses bisa diatur di Pengaturan › Hak akses peran.
     */
    public static function roleMiddleware(string $key): string
    {
        static::item($key);

        return 'menu:'.$key;
    }

    /** Menu yang terlihat untuk peran aktif. */
    public static function visible(?Role $role, ?string $group = null): array
    {
        return array_filter(
            static::items(),
            fn (array $i, string $key) => $role
                && (isset($i['menu_roles']) ? in_array($role, $i['menu_roles'], true) : Access::can($key, 'lihat', $role))
                && (! $group || $i['group'] === $group),
            ARRAY_FILTER_USE_BOTH,
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

        return Access::can('pasien', 'lihat', $role)
            ? [...$base, ['label' => 'Pasien', 'route' => 'pasien', 'active' => ['pasien']]]
            : $base;
    }
}
