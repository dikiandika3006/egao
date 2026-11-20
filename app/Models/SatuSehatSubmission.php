<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antrean pengiriman satu kunjungan ke SATUSEHAT (Encounter + Condition).
 */
class SatuSehatSubmission extends Model
{
    public const QUEUED = 'antre';

    public const SENT = 'terkirim';

    public const FAILED = 'gagal';

    public const STATUSES = [
        self::QUEUED => 'Menunggu dikirim',
        self::SENT => 'Terkirim',
        self::FAILED => 'Gagal',
    ];

    protected $table = 'satusehat_submissions';

    protected $fillable = [
        'visit_id', 'branch_id', 'status', 'attempts', 'last_error', 'encounter_id', 'condition_ids', 'environment', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'condition_ids' => 'array',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function badgeClass(): string
    {
        return match ($this->status) {
            self::SENT => 'bg-leaf-200 text-brand-700',
            self::FAILED => 'bg-rose-soft text-rose-ink',
            default => 'bg-amber-soft text-amber-ink',
        };
    }
}
