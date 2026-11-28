<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\AuditLog;

/**
 * Hak akses menu per peran, diatur Owner di Pengaturan › Hak akses peran.
 *
 * Tiap menu punya tiga izin: lihat (menu tampil & halaman bisa dibuka), edit (aksi tulis),
 * keuangan (angka rupiah tampil). Owner selalu akses penuh dan tidak bisa dibatasi.
 * Disimpan sebagai JSON di settings.role_permissions; menu yang belum diatur memakai bawaan,
 * yaitu perilaku sebelum fitur ini ada.
 */
class Access
{
    public const KEY = 'role_permissions';

    public const ABILITIES = ['lihat' => 'Lihat', 'edit' => 'Edit', 'keuangan' => 'Keuangan'];

    /** Menu yang punya angka rupiah yang bisa disembunyikan tersendiri. */
    public const FINANCE_MENUS = ['dashboard', 'rekam-medis', 'stok', 'lab', 'master-barang', 'tindakan', 'gaji', 'posisi-stok', 'kartu-stok'];

    /** Menu yang isinya seluruhnya angka uang: keuangan ikut izin lihat. */
    public const MONEY_ONLY_MENUS = ['kasir', 'laporan', 'pengeluaran', 'tarif-gaji', 'laporan-stok'];

    /** Menu yang tidak diatur di matriks: Pengaturan & Master user khusus Owner, Tentang terbuka untuk semua. */
    public const FIXED_MENUS = ['pengaturan', 'master-user', 'tentang'];

    /**
     * Bawaan peran Admin / Asisten (25 Nov 2026): menu operasional saja. Laporan, Pengeluaran, Gaji, Master data
     * tertutup; omzet hari ini di dashboard tampil (admin menghitung totalan kas). Rekam medis hanya baca: yang memeriksa dokter.
     * Owner bisa mencentang Rekam medis › Edit agar Admin/Asisten memeriksa atas nama dokter (+ Keuangan = boleh ubah harga).
     */
    private const ADMIN = [
        'dashboard' => ['lihat' => true, 'edit' => true, 'keuangan' => true],
        'antrean' => ['lihat' => true, 'edit' => true, 'keuangan' => false],
        'janji' => ['lihat' => true, 'edit' => true, 'keuangan' => false],
        'pasien' => ['lihat' => true, 'edit' => true, 'keuangan' => false],
        'rekam-medis' => ['lihat' => true, 'edit' => false, 'keuangan' => false], // hanya baca; Edit = boleh memeriksa atas nama dokter (ExamService::isStaffEditor)
        'kasir' => ['lihat' => true, 'edit' => true, 'keuangan' => true],
        'jadwal' => ['lihat' => true, 'edit' => true, 'keuangan' => false],
        'absensi' => ['lihat' => true, 'edit' => true, 'keuangan' => false],
        // Stok: hanya melihat posisi & kartu stok (tanpa harga); pembelian/opname & laporan jual-beli khusus Owner.
        'posisi-stok' => ['lihat' => true, 'edit' => false, 'keuangan' => false],
        'kartu-stok' => ['lihat' => true, 'edit' => false, 'keuangan' => false],
        'lab' => ['lihat' => true, 'edit' => true, 'keuangan' => false],
    ];

    /** Peran yang izinnya bisa diatur (Owner selalu penuh). */
    public static function roles(): array
    {
        return array_values(array_filter(Role::cases(), fn (Role $r) => $r !== Role::Owner));
    }

    /** Menu yang tampil di matriks pengaturan. */
    public static function menus(): array
    {
        return array_diff_key(Navigation::items(), array_flip(self::FIXED_MENUS));
    }

    public static function can(string $menu, string $ability, ?Role $role = null): bool
    {
        $role ??= app(BranchContext::class)->role();

        if ($role === null) {
            return false;
        }
        if ($role === Role::Owner) {
            return true;
        }
        if ($menu === 'tentang') {
            return true;
        }
        if ($menu === 'pengaturan') {
            return false; // Pengaturan program khusus Owner; tab pintasan Master data punya izin sendiri.
        }

        $perm = static::permission($role, $menu);
        // Dashboard = halaman awal, selalu bisa dibuka; yang diatur hanya angka pendapatannya.
        if ($menu === 'dashboard') {
            $perm['lihat'] = true;
        }
        if (! $perm['lihat']) {
            return false;
        }

        return match ($ability) {
            'lihat' => true,
            'edit' => $perm['edit'],
            'keuangan' => in_array($menu, self::MONEY_ONLY_MENUS, true) || (in_array($menu, self::FINANCE_MENUS, true) ? $perm['keuangan'] : true),
            default => false,
        };
    }

    /**
     * Halaman modul boleh dibuka? Izin Lihat menu itu sendiri, atau salah satu pintasan Master data
     * yang membuka tab di halaman tersebut (mis. Master barang → Stok, Tindakan & tarif → Pengaturan).
     */
    public static function canOpen(string $menu, ?Role $role = null): bool
    {
        $route = Navigation::item($menu)['route'];

        return static::can($menu, 'lihat', $role)
            || collect(Navigation::items())->contains(fn ($i, $k) => ! empty($i['shortcut']) && $i['route'] === $route && static::can($k, 'lihat', $role));
    }

    /** @return array{lihat:bool, edit:bool, keuangan:bool} */
    public static function permission(Role $role, string $menu): array
    {
        $stored = static::stored()[$role->value][$menu] ?? null;

        return is_array($stored)
            ? ['lihat' => (bool) ($stored['lihat'] ?? false), 'edit' => (bool) ($stored['edit'] ?? false), 'keuangan' => (bool) ($stored['keuangan'] ?? false)]
            : static::defaultPermission($role, $menu);
    }

    /** Bawaan: Admin/Asisten = daftar ADMIN; Dokter = peran yang tercantum di Navigation boleh lihat, edit, dan melihat angka. */
    public static function defaultPermission(Role $role, string $menu): array
    {
        if ($role === Role::Admin) {
            return self::ADMIN[$menu] ?? ['lihat' => false, 'edit' => false, 'keuangan' => false];
        }

        $item = Navigation::items()[$menu] ?? null;
        $allowed = $item !== null && in_array($role, $item['roles'], true);

        return ['lihat' => $allowed, 'edit' => $allowed, 'keuangan' => $allowed];
    }

    /** Matriks lengkap untuk form: [role][menu] => izin. */
    public static function matrix(): array
    {
        $out = [];
        foreach (static::roles() as $role) {
            foreach (array_keys(static::menus()) as $menu) {
                $out[$role->value][$menu] = static::permission($role, $menu);
            }
        }

        return $out;
    }

    /** Simpan matriks dari form; edit/keuangan dimatikan bila lihat mati. */
    public static function save(array $matrix): void
    {
        $clean = [];
        foreach (static::roles() as $role) {
            foreach (array_keys(static::menus()) as $menu) {
                $p = $matrix[$role->value][$menu] ?? [];
                $lihat = $menu === 'dashboard' || (bool) ($p['lihat'] ?? false);
                $clean[$role->value][$menu] = [
                    'lihat' => $lihat,
                    'edit' => $lihat && (bool) ($p['edit'] ?? false),
                    'keuangan' => $lihat && (bool) ($p['keuangan'] ?? false),
                ];
            }
        }

        $before = static::stored();
        Settings::set(self::KEY, json_encode($clean));
        static::flush();
        AuditLog::record('access.updated', null, ['before' => $before, 'after' => $clean]);
    }

    public static function reset(): void
    {
        Settings::set(self::KEY, null);
        static::flush();
        AuditLog::record('access.reset');
    }

    /** Dibaca sekali per request (disimpan di atribut request agar tidak basi antar-request). */
    private static function stored(): array
    {
        $attrs = request()->attributes;
        if (! $attrs->has(self::KEY)) {
            $attrs->set(self::KEY, json_decode((string) Settings::get(self::KEY), true) ?: []);
        }

        return $attrs->get(self::KEY);
    }

    public static function flush(): void
    {
        request()->attributes->remove(self::KEY);
    }
}
