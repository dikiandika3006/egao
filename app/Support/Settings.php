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
        // Komponen gaji per peran (Pengaturan → Gaji & bagi hasil). Nominal 0 = tidak dipakai.
        'doctor_hourly_rate' => '0',
        'assistant_hourly_rate' => '0',
        'assistant_share_percent' => '2',
        'assistant_overtime_rate' => '5000',
        // Upah asisten per shift hadir (tarif per asisten di data karyawan; kosong = ini).
        'assistant_morning_rate' => '15000',
        'assistant_evening_rate' => '20000',
        'admin_overtime_rate' => '0',
        'annual_revenue_target' => '0',
        // Uang duduk dokter per shift hadir; hangus bila kotor tindakan shift itu ≥ ambang (dokter dapat % fee).
        'doctor_sitting_fee' => '40000',
        'doctor_sitting_threshold' => '100000',
        // Biaya admin & cetak kartu, ditagih sekali per pasien (tagihan pertama).
        'admin_fee' => '5000',
        // Margin bawaan harga jual obat/produk (% di atas HPP).
        'default_margin_percent' => '0',
        // Absensi (Pengaturan → Absensi). Koordinat per cabang di Master › Cabang.
        'attendance_radius_m' => '25',
        'attendance_max_accuracy_m' => '50',
        'attendance_test_mode' => '0',
        // SATUSEHAT (Pengaturan → SATUSEHAT). Kredensial per cabang ada di tabel branches.
        'satusehat_enabled' => '0',
        'satusehat_env' => 'sandbox',
        // Google Calendar (Pengaturan → Google Calendar). Email dokter ada di tabel doctors.
        'gcal_enabled' => '0',
        'gcal_default_time' => '11:00',
        'gcal_extra_emails' => '',
    ];

    /** Key setting gaji yang berupa nominal rupiah. */
    public const PAYROLL_AMOUNT_KEYS = [
        'doctor_hourly_rate', 'assistant_hourly_rate', 'assistant_overtime_rate', 'assistant_morning_rate', 'assistant_evening_rate', 'admin_overtime_rate', 'annual_revenue_target',
        'doctor_sitting_fee', 'doctor_sitting_threshold', 'admin_fee',
    ];

    public static function int(string $key): int
    {
        return max(0, (int) static::get($key));
    }

    public static function float(string $key): float
    {
        return max(0.0, (float) static::get($key));
    }

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
