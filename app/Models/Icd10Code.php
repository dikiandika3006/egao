<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Icd10Code extends Model
{
    protected $table = 'icd10_codes';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'name_en', 'name_id', 'is_dental'];

    protected function casts(): array
    {
        return ['is_dental' => 'boolean'];
    }

    /** "karang" → K03.6, "K03" → semua K03.x (FR-RM-10). */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query->where(fn (Builder $q) => $q
            ->where('code', 'like', $term.'%')
            ->orWhere('name_id', 'like', "%{$term}%")
            ->orWhere('name_en', 'like', "%{$term}%"))
            ->orderByDesc('is_dental')
            ->orderBy('code');
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name_en;
    }
}
