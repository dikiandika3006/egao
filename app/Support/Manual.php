<?php

namespace App\Support;

use App\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Buku manual PDF per peran.
 *
 * Dua lapis: unggahan Owner (storage/app/private/manuals/{peran}.pdf, tidak di-commit) menimpa
 * file bawaan repo (resources/manuals/{peran}.pdf, ikut terkirim saat deploy).
 */
class Manual
{
    public const DIR = 'manuals';

    public static function uploadPath(Role $role): string
    {
        return self::DIR.'/'.$role->value.'.pdf';
    }

    public static function bundledPath(Role $role): string
    {
        return resource_path('manuals/'.$role->value.'.pdf');
    }

    /** Path absolut file yang berlaku, atau null bila belum ada. */
    public static function path(Role $role): ?string
    {
        if (Storage::disk('local')->exists(self::uploadPath($role))) {
            return Storage::disk('local')->path(self::uploadPath($role));
        }

        return is_file(self::bundledPath($role)) ? self::bundledPath($role) : null;
    }

    /** @return array{role: Role, label: string, exists: bool, size: ?int, updated: ?Carbon, uploaded: bool} */
    public static function info(Role $role): array
    {
        $path = self::path($role);

        return [
            'role' => $role,
            'label' => $role->label(),
            'exists' => (bool) $path,
            'size' => $path ? filesize($path) : null,
            'updated' => $path ? Carbon::createFromTimestamp(filemtime($path)) : null,
            'uploaded' => $path !== null && $path !== self::bundledPath($role),
        ];
    }

    public static function downloadName(Role $role): string
    {
        return 'Buku Manual '.str_replace(' / ', ' ', $role->label()).' - Egao Dental Care.pdf';
    }

    /** Manual yang boleh dilihat peran aktif: Owner semua, peran lain hanya miliknya. */
    public static function visibleFor(?Role $active): array
    {
        if ($active === Role::Owner) {
            return Role::cases();
        }

        return $active ? [$active] : [];
    }
}
