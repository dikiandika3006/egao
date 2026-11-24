<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat jadwal ulang janji temu (tanggal/jam/cabang/dokter lama → baru + alasan). */
class AppointmentReschedule extends Model
{
    protected $fillable = [
        'appointment_id', 'from_status', 'from_date', 'from_time', 'from_branch_id', 'from_doctor_id',
        'to_date', 'to_time', 'to_branch_id', 'to_doctor_id', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }
}
