<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pekerjaan yang dikirim ke laboratorium gigi luar (mahkota, gigi tiruan, retainer, dll).
 * Lab menagih klinik per pekerjaan; klinik mencatat kapan dibayar → otomatis jadi pengeluaran "Lab gigi".
 */
class LabOrder extends Model
{
    public const STATUSES = [
        'dikirim' => ['Dikirim ke lab', 'bg-sky-soft text-sky-ink'],
        'diterima' => ['Sudah diterima', 'bg-amber-soft text-amber-ink'],
        'dipasang' => ['Dipasang ke pasien', 'bg-leaf-100 text-brand-600'],
        'batal' => ['Batal', 'bg-mist text-muted'],
    ];

    protected $fillable = [
        'clinic_id', 'branch_id', 'patient_id', 'doctor_id', 'lab_name', 'work', 'tooth', 'sent_at', 'due_at',
        'received_at', 'cost', 'paid_at', 'expense_id', 'status', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'date', 'due_at' => 'date', 'received_at' => 'date', 'paid_at' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status][1] ?? 'bg-mist text-muted';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'dikirim' && $this->due_at && $this->due_at->isPast() && ! $this->due_at->isToday();
    }
}
