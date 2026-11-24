<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Karyawan non-dokter (admin, perawat, dll) untuk pencatatan gaji (FR-GJI-05). */
class Employee extends Model
{
    public const POSITIONS = ['Admin', 'Perawat', 'Asisten dokter', 'Kebersihan', 'Lainnya'];

    protected $fillable = ['clinic_id', 'user_id', 'primary_branch_id', 'name', 'position', 'base_salary', 'morning_shift_rate', 'evening_shift_rate', 'joined_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'is_active' => 'boolean',
            'morning_shift_rate' => 'integer',
            'evening_shift_rate' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeShiftAttendance::class);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }
}
