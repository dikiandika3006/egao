<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedurePrice extends Model
{
    protected $fillable = ['procedure_id', 'branch_id', 'price'];

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
