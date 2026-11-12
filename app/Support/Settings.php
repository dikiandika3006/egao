<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/** Key-value tabel `settings` (FR-SET). Diubah Owner di Pengaturan → Aplikasi. */
class Settings
{
    /** Nilai bawaan bila belum pernah disimpan. */
    public const DEFAULTS = [
        'share_percent_global' => '20',
        'session_idle_hours' => '8',
        'clinic_tagline' => 'Klinik gigi keluarga',
        'clinic_email' => '',
        'clinic_whatsapp' => '',
        'receipt_prefix' => 'KW',
        'receipt_show_logo' => '1',
        'payment_methods' => 'tunai,transfer,debit,kredit,qris',
        'admin_max_discount_percent' => '20',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value') ?? $default ?? (static::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, ?string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    public static function bool(string $key): bool
    {
        return (bool) (int) static::get($key);
    }

    /** Persentase bagi hasil default bila dokter & tindakan tidak punya tarif sendiri. */
    public static function globalShare(): float
    {
        return (float) static::get('share_percent_global');
    }

    /** Metode bayar yang diaktifkan Owner, urut sesuai Payment::METHODS. */
    public static function paymentMethods(): array
    {
        $enabled = array_filter(explode(',', (string) static::get('payment_methods')));
        $methods = array_intersect_key(Payment::METHODS, array_flip($enabled));

        return $methods ?: ['tunai' => Payment::METHODS['tunai']];
    }

    public static function idleHours(): int
    {
        return max(1, (int) static::get('session_idle_hours'));
    }
}
