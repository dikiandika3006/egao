<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Total jam hadir & jam lembur per orang per bulan (dasar UD per jam dan lembur). */
class PayrollHour extends Model
{
    protected $fillable = ['clinic_id', 'period', 'payee_type', 'payee_id', 'work_hours', 'overtime_hours'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'work_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
        ];
    }
}
