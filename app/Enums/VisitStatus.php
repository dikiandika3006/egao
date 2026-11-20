<?php

namespace App\Enums;

/**
 * Status kunjungan / antrean (SRS §3.4.1).
 */
enum VisitStatus: string
{
    case Menunggu = 'menunggu';
    case Dipanggil = 'dipanggil';
    case Diperiksa = 'diperiksa';
    case MenungguPembayaran = 'menunggu_pembayaran';
    case Selesai = 'selesai';
    case Batal = 'batal';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu',
            self::Dipanggil => 'Dipanggil',
            self::Diperiksa => 'Diperiksa',
            self::MenungguPembayaran => 'Bayar',
            self::Selesai => 'Selesai',
            self::Batal => 'Tidak hadir',
        };
    }

    /** Kelas badge, warna mengikuti desain final. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Menunggu => 'bg-amber-soft text-amber-ink',
            self::Dipanggil => 'bg-orange-soft text-orange-ink',
            self::Diperiksa => 'bg-leaf-200 text-brand-700',
            self::MenungguPembayaran => 'bg-indigo-soft text-indigo-ink',
            self::Selesai => 'bg-leaf-50 text-brand-600',
            self::Batal => 'bg-rose-soft text-rose-ink',
        };
    }

    /**
     * Kelompok sederhana untuk layar antrean: menunggu (belum selesai ditangani dokter),
     * selesai (tindakan selesai, lanjut/sudah bayar), batal (tidak hadir).
     */
    public function queueGroup(): string
    {
        return match ($this) {
            self::Menunggu, self::Dipanggil, self::Diperiksa => 'menunggu',
            self::MenungguPembayaran, self::Selesai => 'selesai',
            self::Batal => 'batal',
        };
    }

    public function queueLabel(): string
    {
        return match ($this->queueGroup()) {
            'menunggu' => 'Menunggu',
            'selesai' => 'Selesai tindakan',
            default => 'Tidak hadir',
        };
    }

    public function queueBadgeClass(): string
    {
        return match ($this->queueGroup()) {
            'menunggu' => 'bg-amber-soft text-amber-ink',
            'selesai' => 'bg-leaf-200 text-brand-700',
            default => 'bg-rose-soft text-rose-ink',
        };
    }

    /** Keterangan kecil di bawah badge antrean. */
    public function queueHint(): ?string
    {
        return match ($this) {
            self::Diperiksa => 'Sedang diperiksa',
            self::MenungguPembayaran => 'Belum bayar',
            self::Selesai => 'Lunas',
            default => null,
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Menunggu, self::Dipanggil, self::Diperiksa, self::MenungguPembayaran], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isActive()));
    }
}
