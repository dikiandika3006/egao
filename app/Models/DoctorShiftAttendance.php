<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tanda hadir dokter per shift (diisi Owner di Jadwal), dasar uang duduk per shift. */
class DoctorShiftAttendance extends Model
{
    protected $fillable = ['doctor_id', 'branch_id', 'date', 'shift', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'shift' => 'integer'];
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
