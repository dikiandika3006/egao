<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_id', 'item_id', 'quantity', 'unit_cost', 'amount'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
