<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Karyawan non-dokter (admin, perawat, dll) untuk pencatatan gaji (FR-GJI-05). */
class Employee extends Model
{
    public const POSITIONS = ['Admin', 'Perawat', 'Asisten dokter', 'Kebersihan', 'Lainnya'];

    protected $fillable = ['clinic_id', 'user_id', 'primary_branch_id', 'name', 'position', 'base_salary', 'joined_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }
}
