<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Diagnosis extends Model
{
    protected $fillable = ['visit_id', 'icd10_code', 'is_primary', 'tooth_note'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function icd10(): BelongsTo
    {
        return $this->belongsTo(Icd10Code::class, 'icd10_code', 'code');
    }
}
