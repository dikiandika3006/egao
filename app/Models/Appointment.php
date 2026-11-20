<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Janji temu / jadwal kontrol pasien (permintaan klien: "jadwal harian pasien"). */
class Appointment extends Model
{
    public const SCHEDULED = 'terjadwal';

    public const ARRIVED = 'datang';

    public const CANCELLED = 'batal';

    public const NO_SHOW = 'tidak_datang';

    public const STATUSES = [
        self::SCHEDULED => ['Terjadwal', 'bg-sky-soft text-sky-ink'],
        self::ARRIVED => ['Datang', 'bg-leaf-100 text-brand-600'],
        self::CANCELLED => ['Batal', 'bg-mist text-muted'],
        self::NO_SHOW => ['Tidak datang', 'bg-rose-soft text-rose-ink'],
    ];

    protected $fillable = [
        'clinic_id', 'patient_id', 'branch_id', 'doctor_id', 'date', 'time', 'purpose', 'note',
        'status', 'source', 'source_visit_id', 'visit_id', 'created_by',
    ];

    /** Kolom yang memengaruhi isi event Google Calendar. */
    private const CALENDAR_FIELDS = ['patient_id', 'branch_id', 'doctor_id', 'date', 'time', 'purpose', 'status'];

    protected function casts(): array
    {
        return ['date' => 'date', 'calendar_dirty' => 'boolean', 'calendar_synced_at' => 'datetime'];
    }

    /** Setiap janji baru / berubah ditandai perlu sinkron ke Google Calendar (dikirim oleh egao:gcal-sync). */
    protected static function booted(): void
    {
        static::creating(function (Appointment $a) {
            $a->calendar_dirty = true;
            $a->calendar_attempts = 0;
        });

        // Lewat query agar tetap tersimpan walau atribut di instance ini sudah usang (sinkron mengubahnya langsung di DB).
        static::updated(function (Appointment $a) {
            if ($a->wasChanged(self::CALENDAR_FIELDS)) {
                static::whereKey($a->id)->update(['calendar_dirty' => true, 'calendar_attempts' => 0]);
                $a->forceFill(['calendar_dirty' => true, 'calendar_attempts' => 0])->syncOriginalAttributes(['calendar_dirty', 'calendar_attempts']);
            }
        });
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(AppointmentCalendarEvent::class);
    }

    /** synced | pending | failed | null (belum pernah & tidak perlu). */
    public function calendarState(): ?string
    {
        return match (true) {
            $this->calendar_dirty && filled($this->calendar_error) => 'failed',
            (bool) $this->calendar_dirty => 'pending',
            $this->calendar_synced_at !== null => 'synced',
            default => null,
        };
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

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status][1] ?? 'bg-mist text-muted';
    }

    /**
     * Link wa.me berisi pesan konfirmasi janji (tanpa API WhatsApp; admin tinggal tekan kirim).
     * Nomor 08xx → 628xx. Null bila nomor pasien tidak valid.
     */
    public function whatsappUrl(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->patient?->phone);
        if (strlen($digits) < 9) {
            return null;
        }
        $number = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

        $when = $this->date->translatedFormat('l, j F Y').($this->time ? ' pukul '.str_replace(':', '.', substr($this->time, 0, 5)) : '');
        $with = $this->doctor ? " dengan {$this->doctor->name}" : '';
        $what = $this->purpose ? " untuk {$this->purpose}" : '';

        $text = "Halo {$this->patient->name}, kami dari Egao Dental Care {$this->branch->shortName()}. "
            ."Mengingatkan janji temu Anda{$what} pada {$when}{$with}. "
            .'Mohon balas YA untuk konfirmasi, atau kabari kami bila ingin mengubah jadwal. Terima kasih.';

        return 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }
}
