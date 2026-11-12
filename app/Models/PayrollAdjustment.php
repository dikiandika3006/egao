<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bonus atau potongan gaji per bulan untuk dokter/karyawan (FR-GJI-03/04). */
class PayrollAdjustment extends Model
{
    public const PAYEE_DOCTOR = 'doctor';

    public const PAYEE_EMPLOYEE = 'employee';

    public const TYPE_BONUS = 'bonus';

    public const TYPE_DEDUCTION = 'potongan';

    protected $fillable = ['clinic_id', 'period', 'payee_type', 'payee_id', 'type', 'amount', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['period' => 'date'];
    }

    public function signedAmount(): int
    {
        return $this->type === self::TYPE_DEDUCTION ? -$this->amount : $this->amount;
    }
}
