<?php

namespace App\Support;

class Format
{
    /** Diset per halaman modul (ModulePage::page) bila peran tidak boleh melihat keuangan menu itu; berlaku untuk request ini saja. */
    public static function mask(bool $masked): void
    {
        request()->attributes->set('money_masked', $masked);
    }

    public static function masked(): bool
    {
        return (bool) request()->attributes->get('money_masked', false);
    }

    public const MASK = 'Rp•••';

    /** Rp1.250.000 */
    public static function rupiah(int|float|null $amount): string
    {
        if (static::masked()) {
            return static::MASK;
        }

        // Minus ditulis di depan: −Rp475.000 (bukan Rp-475.000).
        return ($amount < 0 ? '−' : '').'Rp'.number_format(abs((float) $amount), 0, ',', '.');
    }

    /** 2,1 (juta) */
    public static function juta(int|float|null $amount, int $decimals = 1): string
    {
        if (static::masked()) {
            return '•••';
        }

        return number_format(((float) $amount) / 1_000_000, $decimals, ',', '.');
    }

    /** 10.00 dari "10:00:00" */
    public static function jam(?string $time): string
    {
        return $time ? str_replace(':', '.', substr($time, 0, 5)) : '';
    }

    /** Terbilang bahasa Indonesia, untuk kwitansi (FR-CTK-01). */
    public static function terbilang(int $n): string
    {
        $n = abs($n);
        $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

        $result = match (true) {
            $n < 12 => $words[$n],
            $n < 20 => self::terbilang($n - 10).' belas',
            $n < 100 => self::terbilang(intdiv($n, 10)).' puluh '.self::terbilang($n % 10),
            $n < 200 => 'seratus '.self::terbilang($n - 100),
            $n < 1000 => self::terbilang(intdiv($n, 100)).' ratus '.self::terbilang($n % 100),
            $n < 2000 => 'seribu '.self::terbilang($n - 1000),
            $n < 1_000_000 => self::terbilang(intdiv($n, 1000)).' ribu '.self::terbilang($n % 1000),
            $n < 1_000_000_000 => self::terbilang(intdiv($n, 1_000_000)).' juta '.self::terbilang($n % 1_000_000),
            default => self::terbilang(intdiv($n, 1_000_000_000)).' miliar '.self::terbilang($n % 1_000_000_000),
        };

        return trim(preg_replace('/\s+/', ' ', $result));
    }
}
