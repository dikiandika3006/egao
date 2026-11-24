<?php

namespace App\Models;

use App\Models\Concerns\HasCheckInOut;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kehadiran asisten per shift (absen lokasi atau centang Owner di Jadwal), dasar upah shift & lembur. */
class EmployeeShiftAttendance extends Model
{
    use HasCheckInOut;

    protected $fillable = [
        'employee_id', 'branch_id', 'date', 'shift', 'recorded_by',
        'check_in_at', 'check_out_at', 'check_in_lat', 'check_in_lng', 'check_in_accuracy', 'check_out_lat', 'check_out_lng', 'distance_m', 'source', 'note',
        'overtime_minutes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'shift' => 'integer', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime',
            'overtime_minutes' => 'integer',
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
}
