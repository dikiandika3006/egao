<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPES = [
        'masuk' => 'Pembelian',
        'jual' => 'Terjual',
        'batal_jual' => 'Batal jual',
        'pakai' => 'Dipakai',
        'penyesuaian' => 'Penyesuaian',
    ];

    protected $fillable = [
        'item_id', 'branch_id', 'type', 'quantity', 'unit_cost', 'unit_price',
        'purchase_id', 'invoice_id', 'note', 'user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
