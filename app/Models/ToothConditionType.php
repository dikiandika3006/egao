<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToothConditionType extends Model
{
    protected $fillable = ['clinic_id', 'code', 'name', 'color', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
