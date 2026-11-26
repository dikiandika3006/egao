<?php

namespace App\Enums;

/**
 * Tiga peran (25 Nov 2026): Owner/Administrator, Admin/Asisten (satu peran), Dokter.
 * Data lama berperan "asisten" dipindah ke "admin" oleh migrasi 2026_11_25_000001.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Dokter = 'dokter';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner / Administrator',
            self::Admin => 'Admin / Asisten',
            self::Dokter => 'Dokter',
        };
    }
}
