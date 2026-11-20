<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kalender Google milik Service Account: satu per dokter, plus satu kalender Owner (doctor_id null). */
class GoogleCalendar extends Model
{
    protected $fillable = ['clinic_id', 'doctor_id', 'calendar_id', 'error'];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(GoogleCalendarShare::class);
    }

    /**
     * Link untuk menambahkan kalender ini ke Google Calendar penerima (dibuka sekali saat login dengan Gmail yang diundang).
     * Pengganti email undangan, yang sering tidak dikirim Google bila pembagi adalah Service Account.
     */
    public function addUrl(): ?string
    {
        return $this->calendar_id
            ? 'https://calendar.google.com/calendar/u/0?cid='.rtrim(base64_encode($this->calendar_id), '=')
            : null;
    }

    public function title(): string
    {
        return 'Egao – '.($this->doctor_id ? $this->doctor?->name : 'Semua janji temu');
    }
}
