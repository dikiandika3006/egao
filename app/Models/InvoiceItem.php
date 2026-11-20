<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'procedure_id', 'item_id', 'doctor_id', 'name', 'tooth', 'quantity', 'unit_price', 'standard_price', 'amount'];

    /** Harga tindakan dinaikkan Dokter/Owner di atas harga standar. */
    public function isRaised(): bool
    {
        return $this->standard_price !== null && $this->unit_price > $this->standard_price;
    }

    /** Baris obat/barang (bukan tindakan): tidak masuk tindakan kotor dokter, mengurangi stok saat lunas. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function isGoods(): bool
    {
        return $this->item_id !== null;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
