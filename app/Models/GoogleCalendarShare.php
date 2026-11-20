<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Akses (ACL) kalender Egao untuk satu email; undangan dikirim Google ke email tersebut. */
class GoogleCalendarShare extends Model
{
    protected $fillable = ['google_calendar_id', 'email', 'acl_rule_id', 'invited_at', 'link_sent_at', 'error'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'link_sent_at' => 'datetime'];
    }

    /** Link WhatsApp (pilih kontak sendiri) berisi pesan + link tambah kalender. */
    public function whatsappUrl(GoogleCalendar $calendar, string $name): ?string
    {
        $url = $calendar->addUrl();
        if (! $url) {
            return null;
        }

        $text = "Halo {$name}, ini kalender janji temu Egao Dental Care ({$calendar->title()}). "
            ."Mohon buka link berikut sekali saat login dengan Gmail {$this->email}, lalu tekan Tambahkan. "
            ."Setelah itu janji temu otomatis muncul di Google Calendar Anda.\n\n{$url}";

        return 'https://wa.me/?text='.rawurlencode($text);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendar::class, 'google_calendar_id');
    }
}
