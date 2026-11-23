<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Asisten = 'asisten';
    case Dokter = 'dokter';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Asisten => 'Asisten',
            self::Dokter => 'Dokter',
        };
    }
}
