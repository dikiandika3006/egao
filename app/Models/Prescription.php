<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model
{
    protected $fillable = ['visit_id', 'item_id', 'drug_name', 'dose', 'quantity', 'item_quantity'];

    /** Obat dari stok klinik (opsional). Bila diisi, ikut ditagihkan di kasir. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
