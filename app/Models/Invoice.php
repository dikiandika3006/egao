<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** Tindakan masih disusun dokter, belum terlihat di kasir (FR-RM-19/20). */
    public const STATUS_DRAFT = 'draf';

    public const STATUS_UNPAID = 'belum';

    public const STATUS_PARTIAL = 'sebagian';

    public const STATUS_PAID = 'lunas';

    public const STATUS_VOID = 'batal';

    protected $fillable = [
        'visit_id', 'patient_id', 'buyer_name', 'branch_id', 'subtotal', 'discount', 'discount_reason', 'total', 'paid', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'stock_posted_at' => 'datetime'];
    }

    /** Stok obat/barang berkurang saat tagihan lunas, kembali bila tagihan lunas dibatalkan. */
    protected static function booted(): void
    {
        static::updated(function (Invoice $invoice) {
            if (! $invoice->wasChanged('status')) {
                return;
            }

            if ($invoice->status === self::STATUS_PAID) {
                app(\App\Services\StockService::class)->postSale($invoice);
            } elseif ($invoice->status === self::STATUS_VOID) {
                app(\App\Services\StockService::class)->reverseSale($invoice);
            }
        });
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** Pembeli pada penjualan langsung (tanpa kunjungan). */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Penjualan langsung di kasir: tanpa kunjungan/pemeriksaan. */
    public function isDirectSale(): bool
    {
        return $this->visit_id === null;
    }

    /** Pasien tagihan: dari kunjungan, atau pasien terdaftar pada penjualan langsung. */
    public function payerPatient(): ?Patient
    {
        return $this->visit?->patient ?? $this->patient;
    }

    /** Nama yang ditampilkan di kasir, laporan, dan kwitansi. */
    public function payerName(): string
    {
        return $this->payerPatient()?->name ?? ($this->buyer_name ?: 'Pembeli umum');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function outstanding(): int
    {
        return max(0, $this->total - $this->paid);
    }

    /** Hitung ulang subtotal & total dari rincian (diskon tetap). */
    public function recalculate(): self
    {
        $subtotal = (int) $this->items()->sum('amount');
        $discount = min((int) $this->discount, $subtotal);

        $this->forceFill(['subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount])->save();

        return $this;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Disusun dokter',
            self::STATUS_UNPAID => 'Belum dibayar',
            self::STATUS_PARTIAL => 'Bayar sebagian',
            self::STATUS_PAID => 'Lunas',
            self::STATUS_VOID => 'Batal',
            default => $this->status,
        };
    }
}
