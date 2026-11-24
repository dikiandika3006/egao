<?php

namespace App\Models;

use App\Models\Concerns\HasCheckInOut;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tanda hadir dokter per shift (centang Owner di Jadwal atau absen lokasi), dasar uang duduk per shift. */
class DoctorShiftAttendance extends Model
{
    use HasCheckInOut;

    protected $fillable = [
        'doctor_id', 'branch_id', 'date', 'shift', 'recorded_by',
        'check_in_at', 'check_out_at', 'check_in_lat', 'check_in_lng', 'check_in_accuracy', 'check_out_lat', 'check_out_lng', 'distance_m', 'source', 'note',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'shift' => 'integer', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime'];
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
