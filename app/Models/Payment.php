<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHODS = [
        'tunai' => 'Tunai',
        'transfer' => 'Transfer',
        'debit' => 'Kartu debit',
        'kredit' => 'Kartu kredit',
        'qris' => 'QRIS',
    ];

    protected $fillable = ['invoice_id', 'received_by', 'method', 'amount', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
