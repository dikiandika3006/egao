<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'clinic_id', 'code', 'name', 'city', 'address', 'phone', 'color',
        'opens_at', 'closes_at', 'receipt_start_number', 'receipt_footer', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** "Batu" dari "Cabang Batu". */
    public function shortName(): string
    {
        return str($this->name)->replace('Cabang ', '')->toString();
    }

    /** Warna penanda cabang di kisi jadwal & tabel. */
    public function badgeClass(): string
    {
        return match ($this->color) {
            'malang' => 'bg-sky-soft text-sky-ink',
            default => 'bg-leaf-100 text-brand-600',
        };
    }
}
