<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Event Google Calendar milik satu janji di satu kalender Egao (dokter / Owner). */
class AppointmentCalendarEvent extends Model
{
    protected $fillable = ['appointment_id', 'calendar_id', 'google_event_id'];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
