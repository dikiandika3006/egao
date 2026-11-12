<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['medical_record_id', 'version', 'user_id', 'reason', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
