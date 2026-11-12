<?php

namespace App\Support;

/** Format nomor kwitansi: PREFIX/KODECABANG/YYYYMM/00001 (prefix kosong = tanpa prefix). */
class Receipts
{
    public static function code(string $branchCode, int $number, ?string $prefix = null, ?\DateTimeInterface $at = null): string
    {
        $prefix = strtoupper(trim($prefix ?? (string) Settings::get('receipt_prefix')));
        $parts = array_filter([$prefix, $branchCode, ($at ?? now())->format('Ym'), sprintf('%05d', $number)], 'strlen');

        return implode('/', $parts);
    }

    public static function preview(string $prefix, string $branchCode): string
    {
        return static::code($branchCode, 1, preg_replace('/[^A-Za-z]/', '', $prefix));
    }
}
