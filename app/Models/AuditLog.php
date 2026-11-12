<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'branch_id', 'action', 'auditable_type', 'auditable_id', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(string $action, ?Model $subject = null, array $changes = []): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'branch_id' => app(\App\Support\BranchContext::class)->id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}
