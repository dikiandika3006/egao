<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Barang persediaan: obat, bahan habis pakai, alat, barang jual (odol, sikat gigi, bracket, dll.). */
class Item extends Model
{
    public const CATEGORIES = ['obat' => 'Obat', 'bahan' => 'Bahan', 'alat' => 'Alat', 'barang' => 'Barang jual'];

    protected $fillable = ['clinic_id', 'code', 'name', 'category', 'unit', 'buy_price', 'sell_price', 'margin_percent', 'min_stock', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'margin_percent' => 'decimal:2'];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockAt(int $branchId): int
    {
        return (int) ($this->stocks->firstWhere('branch_id', $branchId)?->quantity ?? 0);
    }

    public function isSellable(): bool
    {
        return $this->sell_price !== null;
    }
}
