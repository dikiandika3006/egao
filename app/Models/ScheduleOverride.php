<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleOverride extends Model
{
    public const TYPE_MOVE = 'move';

    public const TYPE_OFF = 'off';

    protected $fillable = ['doctor_id', 'date', 'type', 'branch_id', 'starts_at', 'ends_at', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
