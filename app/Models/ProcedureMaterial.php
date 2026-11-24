<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Resep bahan: takaran satu barang (boleh pecahan) untuk satu kali tindakan. Diisi Owner bertahap. */
class ProcedureMaterial extends Model
{
    protected $fillable = ['procedure_id', 'item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
