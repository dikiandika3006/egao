<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pembelian obat/bahan/alat: menambah stok dan tercatat sebagai pengeluaran. */
class Purchase extends Model
{
    protected $fillable = ['branch_id', 'date', 'supplier', 'reference', 'total', 'expense_id', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
