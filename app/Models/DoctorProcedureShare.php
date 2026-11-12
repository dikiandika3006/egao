<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tarif bagi hasil khusus satu dokter untuk satu tindakan. */
class DoctorProcedureShare extends Model
{
    protected $fillable = ['doctor_id', 'procedure_id', 'share_percent'];

    protected function casts(): array
    {
        return ['share_percent' => 'decimal:2'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
