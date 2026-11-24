<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jadwal rutin asisten (pola sama dengan DoctorSchedule). */
class EmployeeSchedule extends Model
{
    protected $fillable = [
        'employee_id', 'branch_id', 'day_of_week', 'starts_at', 'ends_at', 'valid_from', 'valid_until', 'note',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function appliesOn(CarbonInterface $date): bool
    {
        return $this->day_of_week === $date->dayOfWeekIso
            && (! $this->valid_from || $date->gte($this->valid_from->startOfDay()))
            && (! $this->valid_until || $date->lte($this->valid_until->endOfDay()));
    }
}
