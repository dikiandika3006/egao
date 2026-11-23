<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Procedure extends Model
{
    protected $fillable = ['clinic_id', 'code', 'name', 'category', 'share_percent', 'cost_amount', 'fee_from_procedure_id', 'fee_minus_lab', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'fee_minus_lab' => 'boolean',
            'share_percent' => 'decimal:2',
            'cost_amount' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProcedurePrice::class);
    }

    /** Tindakan tahap awal (mis. Cetak) yang pembayarannya ikut dasar fee tindakan ini. */
    public function feeFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fee_from_procedure_id');
    }

    public function priceFor(int $branchId): ?int
    {
        return $this->prices->firstWhere('branch_id', $branchId)?->price;
    }
}
