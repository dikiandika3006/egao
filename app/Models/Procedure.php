<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Procedure extends Model
{
    protected $fillable = ['clinic_id', 'code', 'name', 'category', 'share_percent', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'share_percent' => 'decimal:2',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProcedurePrice::class);
    }

    public function priceFor(int $branchId): ?int
    {
        return $this->prices->firstWhere('branch_id', $branchId)?->price;
    }
}
