<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Procedure extends Model
{
    protected $fillable = ['clinic_id', 'code', 'name', 'category', 'share_percent', 'cost_amount', 'fee_from_procedure_id', 'fee_minus_lab', 'price_locked', 'allow_lower_price', 'assistant_fee_amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'fee_minus_lab' => 'boolean',
            'price_locked' => 'boolean',
            'allow_lower_price' => 'boolean',
            'assistant_fee_amount' => 'integer',
            'share_percent' => 'decimal:2',
            'cost_amount' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProcedurePrice::class);
    }

    /** Resep bahan per 1 tindakan (takaran pecahan, diisi Owner bertahap). */
    public function materials(): HasMany
    {
        return $this->hasMany(ProcedureMaterial::class);
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
