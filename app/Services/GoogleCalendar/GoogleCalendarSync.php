<?php

namespace App\Services\GoogleCalendar;

use App\Models\Appointment;
use App\Models\AppointmentCalendarEvent;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarShare;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Sinkron janji temu → Google Calendar.
 * Service Account membuat kalender sendiri ("Egao – drg. X" per dokter + "Egao – Semua janji temu" untuk Owner)
 * dan membagikannya otomatis ke email orangnya (undangan email; cukup diterima sekali).
 * Event janji ditulis ke kalender dokter yang dituju (bila kosong: dokter bertugas hari itu) + kalender Owner.
 * Janji batal / tidak datang → event dihapus. Tanpa HP/NIK di isi event.
 */
class GoogleCalendarSync
{
    public function __construct(private GoogleCalendarClient $client) {}

    public function enabled(): bool
    {
        return Settings::bool('gcal_enabled');
    }

    public function client(): GoogleCalendarClient
    {
        return $this->client;
    }

    /** Email Owner yang menerima kalender "Semua janji temu" (dipisah koma, spasi, atau baris baru). */
    public static function extraEmails(): array
    {
        return self::cleanEmails(preg_split('/[\s,;]+/', (string) Settings::get('gcal_extra_emails')));
    }

    public static function cleanEmails(iterable $emails): array
    {
        return collect($emails)
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }

    /** Jam mulai bila janji tanpa jam (default 11:00 = jam buka + 1 jam). */
    public static function defaultTime(): string
    {
        $time = (string) Settings::get('gcal_default_time', '11:00');

        return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '11:00';
    }

    /** Jam acara untuk janji tanpa jam: jam buka cabang hari itu + 1 jam; cadangan setting bila cabang tutup. */
    public static function defaultTimeFor(Appointment $appointment): string
    {
        $open = $appointment->branch?->openingTime($appointment->date);

        return $open ? Carbon::createFromFormat('H:i', $open)->addHour()->format('H:i') : self::defaultTime();
    }

    // ── Kalender & undangan ─────────────────────────────────────────────

    /**
     * Samakan kalender & akses dengan data aplikasi: buat kalender yang belum ada, undang email baru,
     * cabut akses email lama / dokter nonaktif (kalender tidak dihapus). Hanya memanggil Google bila ada perubahan.
     *
     * @return int jumlah kalender yang gagal disiapkan
     */
    public function syncCalendars(int $clinicId): int
    {
        $failed = 0;

        foreach ($this->calendarTargets($clinicId) as $target) {
            $calendar = GoogleCalendar::where('clinic_id', $clinicId)->where('doctor_id', $target['doctor_id'])->first()
                ?? new GoogleCalendar(['clinic_id' => $clinicId, 'doctor_id' => $target['doctor_id']]);
            $calendar->setRelation('doctor', $target['doctor']);

            if (! $calendar->exists && ! $target['emails']) {
                continue; // belum perlu kalender
            }

            $errors = [];
            try {
                if (! $calendar->calendar_id) {
                    $calendar->calendar_id = $this->client->createCalendar($calendar->title());
                    $calendar->save();
                    AuditLog::record('gcal.kalender_dibuat', $calendar, ['nama' => $calendar->title()]);
                }

                $shares = $calendar->shares()->get()->toBase()->keyBy('email');

                foreach ($target['emails'] as $email) {
                    $share = $shares->get($email);
                    if ($share?->acl_rule_id) {
                        continue;
                    }
                    try {
                        $this->invite($calendar, $share ?? new GoogleCalendarShare(['email' => $email]));
                    } catch (GoogleCalendarException $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                foreach ($shares->except($target['emails']) as $share) {
                    try {
                        if ($share->acl_rule_id) {
                            $this->client->unshare($calendar->calendar_id, $share->acl_rule_id);
                        }
                        $share->delete();
                        AuditLog::record('gcal.akses_dicabut', $calendar, ['email' => $share->email]);
                    } catch (GoogleCalendarException $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            } catch (GoogleCalendarException $e) {
                $errors[] = $e->getMessage();
            }

            if ($calendar->exists) {
                $calendar->update(['error' => $errors ? str(implode(' ', array_unique($errors)))->limit(490)->toString() : null]);
            }
            $failed += $errors ? 1 : 0;
        }

        return $failed;
    }

    /** Kirim (ulang) undangan: cabut ACL lama bila ada, lalu buat baru agar Google mengirim email lagi. */
    public function invite(GoogleCalendar $calendar, GoogleCalendarShare $share): void
    {
        try {
            if ($share->acl_rule_id) {
                $this->client->unshare($calendar->calendar_id, $share->acl_rule_id);
            }
            $ruleId = $this->client->share($calendar->calendar_id, $share->email);
        } catch (GoogleCalendarException $e) {
            $calendar->shares()->updateOrCreate(['email' => $share->email], ['acl_rule_id' => null, 'error' => $e->getMessage()]);

            throw $e;
        }

        $calendar->shares()->updateOrCreate(['email' => $share->email], ['acl_rule_id' => $ruleId, 'invited_at' => now(), 'error' => null]);
        AuditLog::record('gcal.undangan', $calendar, ['email' => $share->email]);
    }

    /**
     * Kalender yang seharusnya ada beserta email penerimanya: semua dokter yang pernah/sedang punya email
     * (nonaktif / email kosong → email [] agar akses dicabut) + kalender Owner.
     *
     * @return array<int, array{doctor_id:?int, doctor:?Doctor, emails:array}>
     */
    private function calendarTargets(int $clinicId): array
    {
        $withCalendar = GoogleCalendar::where('clinic_id', $clinicId)->whereNotNull('doctor_id')->pluck('doctor_id');

        $doctors = Doctor::where('clinic_id', $clinicId)
            ->where(fn ($q) => $q->whereNotNull('calendar_email')->orWhereIn('id', $withCalendar))
            ->orderBy('name')->get();

        return [
            ...$doctors->map(fn (Doctor $d) => [
                'doctor_id' => $d->id,
                'doctor' => $d,
                'emails' => $d->is_active ? self::cleanEmails([$d->calendar_email]) : [],
            ])->all(),
            ['doctor_id' => null, 'doctor' => null, 'emails' => self::extraEmails()],
        ];
    }

    // ── Janji temu → event ──────────────────────────────────────────────

    /** Dokter penerima: dokter janji, atau semua dokter yang bertugas di cabang & tanggal itu. */
    public function doctors(Appointment $appointment): Collection
    {
        if ($appointment->doctor_id) {
            return collect([$appointment->doctor])->filter();
        }

        return Doctor::where('clinic_id', $appointment->clinic_id)->where('is_active', true)
            ->with(['schedules', 'overrides' => fn ($q) => $q->whereDate('date', $appointment->date)])
            ->orderBy('name')->get()
            ->filter(fn (Doctor $d) => ($d->scheduleOn($appointment->date)['branch_id'] ?? null) === $appointment->branch_id)
            ->values();
    }

    /** Calendar ID (milik Service Account) yang seharusnya memuat janji ini. */
    public function recipients(Appointment $appointment): array
    {
        return $this->resolveRecipients($appointment)['ids'];
    }

    /**
     * @return array{ids: array<int,string>, missing: array<int,string>} missing = penerima yang kalendernya belum siap
     */
    private function resolveRecipients(Appointment $appointment): array
    {
        if (! in_array($appointment->status, [Appointment::SCHEDULED, Appointment::ARRIVED], true)) {
            return ['ids' => [], 'missing' => []];
        }

        $calendars = GoogleCalendar::where('clinic_id', $appointment->clinic_id)->whereNotNull('calendar_id')->get();
        $ids = $missing = [];

        foreach ($this->doctors($appointment)->filter(fn (Doctor $d) => filled($d->calendar_email)) as $doctor) {
            $calendar = $calendars->firstWhere('doctor_id', $doctor->id);
            if ($calendar) {
                $ids[] = $calendar->calendar_id;
            } else {
                $missing[] = $doctor->name;
            }
        }

        if (self::extraEmails()) {
            $calendar = $calendars->first(fn (GoogleCalendar $c) => $c->doctor_id === null);
            if ($calendar) {
                $ids[] = $calendar->calendar_id;
            } else {
                $missing[] = 'Owner';
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'missing' => $missing];
    }

    public function payload(Appointment $appointment): array
    {
        $tz = config('google_calendar.timezone');
        $time = $appointment->time ? substr($appointment->time, 0, 5) : self::defaultTimeFor($appointment);
        $start = Carbon::parse($appointment->date->toDateString().' '.$time, $tz);
        $end = $start->copy()->addMinutes(max(15, (int) config('google_calendar.duration_minutes')));

        $doctors = $this->doctors($appointment)->pluck('name')->implode(', ');
        $branch = $appointment->branch;

        $title = 'Janji temu: '.$appointment->patient->name.($appointment->purpose ? ' — '.$appointment->purpose : '');

        $description = collect([
            'Pasien: '.$appointment->patient->name,
            $appointment->purpose ? 'Keperluan: '.$appointment->purpose : null,
            'Cabang: '.$branch->name,
            'Dokter: '.($doctors ?: 'dokter yang bertugas'),
            $appointment->time ? null : 'Jam belum ditentukan (ditampilkan '.$time.').',
            $appointment->status === Appointment::ARRIVED ? 'Status: pasien sudah datang.' : null,
            '',
            'Dibuat otomatis oleh aplikasi Egao Dental Care. Ubah/batalkan janji dari aplikasi, bukan dari kalender.',
        ])->filter(fn ($l) => $l !== null)->implode("\n");

        return [
            'summary' => $title,
            'description' => $description,
            'location' => trim('Egao Dental Care '.$branch->name.($branch->address ? ', '.$branch->address : '')),
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
            'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
            'reminders' => ['useDefault' => true],
            'extendedProperties' => ['private' => ['egao_appointment_id' => (string) $appointment->id]],
        ];
    }

    /** Sinkron satu janji. true = semua kalender berhasil. */
    public function syncAppointment(Appointment $appointment): bool
    {
        $appointment->loadMissing(['patient', 'branch', 'doctor', 'calendarEvents']);

        ['ids' => $wanted, 'missing' => $missing] = $this->resolveRecipients($appointment);
        // toBase(): except() milik Eloquent Collection menyaring primary key, bukan key calendar_id.
        $existing = $appointment->calendarEvents->toBase()->keyBy('calendar_id');
        $payload = $wanted ? $this->payload($appointment) : [];
        // Kalender yang belum siap → janji tetap ditandai perlu sinkron agar dicoba lagi.
        $errors = $missing ? ['Kalender belum siap: '.implode(', ', $missing).'.'] : [];

        foreach ($wanted as $calendarId) {
            try {
                /** @var AppointmentCalendarEvent|null $row */
                $row = $existing->get($calendarId);
                $eventId = $row ? $this->client->patch($calendarId, $row->google_event_id, $payload) : null;
                $eventId ??= $this->client->insert($calendarId, $payload);

                AppointmentCalendarEvent::updateOrCreate(
                    ['appointment_id' => $appointment->id, 'calendar_id' => $calendarId],
                    ['google_event_id' => $eventId],
                );
            } catch (GoogleCalendarException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach ($existing->except($wanted) as $row) {
            try {
                $this->client->delete($row->calendar_id, $row->google_event_id);
                $row->delete();
            } catch (GoogleCalendarException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Update lewat query agar tidak memicu penanda "perlu sinkron" lagi.
        Appointment::whereKey($appointment->id)->update($errors ? [
            'calendar_attempts' => $appointment->calendar_attempts + 1,
            'calendar_error' => str(implode(' ', array_unique($errors)))->limit(490)->toString(),
        ] : [
            'calendar_dirty' => false,
            'calendar_attempts' => 0,
            'calendar_error' => null,
            'calendar_synced_at' => now(),
        ]);

        return ! $errors;
    }

    /**
     * Siapkan kalender lalu kirim janji yang ditandai perlu sinkron (hari ini ke depan, atau yang sudah punya event).
     *
     * @return array{0:int,1:int} [berhasil, gagal]
     */
    public function syncPending(?int $limit = null, bool $force = false, ?int $clinicId = null): array
    {
        $clinicIds = $clinicId ? [$clinicId] : Appointment::where('calendar_dirty', true)->distinct()->pluck('clinic_id')
            ->merge(Doctor::whereNotNull('calendar_email')->distinct()->pluck('clinic_id'))->unique()->all();
        foreach ($clinicIds as $id) {
            $this->syncCalendars((int) $id);
        }

        $ok = $failed = 0;

        Appointment::where('calendar_dirty', true)
            ->when($clinicId, fn ($q) => $q->where('clinic_id', $clinicId))
            ->when(! $force, fn ($q) => $q->where('calendar_attempts', '<', config('google_calendar.max_attempts')))
            ->where(fn ($q) => $q->whereDate('date', '>=', today())->orWhereHas('calendarEvents'))
            ->orderBy('date')->orderBy('id')
            ->limit($limit ?? config('google_calendar.batch_size'))
            ->get()
            ->each(function (Appointment $a) use (&$ok, &$failed) {
                $this->syncAppointment($a) ? $ok++ : $failed++;
            });

        return [$ok, $failed];
    }
}
