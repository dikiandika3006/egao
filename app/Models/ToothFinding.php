<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToothFinding extends Model
{
    /** Label permukaan gigi (FR-RM-06). */
    public const SURFACES = [
        'O' => 'oklusal',
        'M' => 'mesial',
        'D' => 'distal',
        'B' => 'bukal',
        'L' => 'lingual',
        'P' => 'palatal',
    ];

    protected $fillable = ['visit_id', 'patient_id', 'tooth_condition_type_id', 'tooth', 'surfaces', 'note'];

    protected function casts(): array
    {
        return ['surfaces' => 'array'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(ToothConditionType::class, 'tooth_condition_type_id');
    }

    public function surfaceLabel(): string
    {
        $labels = collect($this->surfaces ?? [])->map(fn ($s) => self::SURFACES[$s] ?? $s);

        return $labels->isEmpty() ? 'seluruh gigi' : 'permukaan '.$labels->implode(', ');
    }
}
