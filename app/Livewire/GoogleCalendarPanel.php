<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarShare;
use App\Services\GoogleCalendar\GoogleCalendarException;
use App\Services\GoogleCalendar\GoogleCalendarSync;
use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Panel Pengaturan → Google Calendar (khusus Owner): saklar aktif, jam default, email Owner penerima,
 * status kalender & undangan per orang (kirim ulang undangan), dan daftar janji yang gagal tersinkron (kirim ulang).
 */
class GoogleCalendarPanel extends Component
{
    use WithPagination;

    public bool $enabled = false;

    /** Filter riwayat pengiriman: '' | synced | pending | failed | removed. */
    public string $statusFilter = '';

    public function updatedStatusFilter(): void
    {
        abort_unless(in_array($this->statusFilter, ['', 'synced', 'pending', 'failed', 'removed'], true), 422);
        $this->resetPage('kirim');
    }

    public string $defaultTime = '11:00';

    public string $extraEmails = '';

    public function mount(GoogleCalendarSync $sync): void
    {
        $this->authorizeOwner();
        $this->enabled = $sync->enabled();
        $this->defaultTime = GoogleCalendarSync::defaultTime();
        $this->extraEmails = implode("\n", GoogleCalendarSync::extraEmails());
    }

    public function saveConfig(GoogleCalendarSync $sync): void
    {
        $this->authorizeOwner();

        $this->validate([
            'defaultTime' => ['required', 'date_format:H:i'],
            'extraEmails' => ['nullable', 'string', 'max:1000'],
        ], [], ['defaultTime' => 'jam default', 'extraEmails' => 'email Owner']);

        $raw = array_filter(preg_split('/[\s,;]+/', $this->extraEmails));
        $emails = GoogleCalendarSync::cleanEmails($raw);
        if (count($emails) !== count(array_unique(array_map('mb_strtolower', $raw)))) {
            throw ValidationException::withMessages(['extraEmails' => 'Ada alamat email yang tidak valid.']);
        }

        if ($this->enabled && ! $sync->client()->configured()) {
            throw ValidationException::withMessages(['enabled' => 'File kunci Service Account belum dipasang di server, jadi integrasi belum bisa diaktifkan.']);
        }

        $wasEnabled = $sync->enabled();
        Settings::set('gcal_enabled', $this->enabled ? '1' : '0');
        Settings::set('gcal_default_time', $this->defaultTime);
        Settings::set('gcal_extra_emails', implode(',', $emails));
        $this->extraEmails = implode("\n", $emails);

        // Baru diaktifkan → janji mendatang yang sudah ada ikut dikirim.
        $queued = (! $wasEnabled && $this->enabled) ? $this->markUpcoming() : 0;

        // Siapkan kalender & undangan sekarang (email Owner bisa berubah).
        if ($this->enabled) {
            $sync->syncCalendars($this->clinicId());
        }

        AuditLog::record('gcal.pengaturan', null, ['aktif' => $this->enabled, 'jam_default' => $this->defaultTime, 'jumlah_email_owner' => count($emails)]);
        $this->dispatch('toast', message: 'Pengaturan Google Calendar disimpan.'.($queued ? " {$queued} janji mendatang akan dikirim." : ''));
    }

    /** Buat kalender yang belum ada & kirim undangan ke email baru (juga berjalan otomatis tiap menit). */
    public function prepareCalendars(GoogleCalendarSync $sync): void
    {
        $this->authorizeOwner();

        if (! $this->ensureEnabled($sync)) {
            return;
        }

        $failed = $sync->syncCalendars($this->clinicId());
        $this->dispatch('toast', message: $failed ? "{$failed} kalender gagal disiapkan — lihat keterangan merah." : 'Kalender & undangan sudah siap.');
    }

    /** Dipanggil saat Owner menekan "Kirim via WA" / "Salin link": tandai link sudah dikirim. */
    public function markLinkSent(int $shareId): void
    {
        $this->authorizeOwner();
        $share = GoogleCalendarShare::whereHas('calendar', fn ($q) => $q->where('clinic_id', $this->clinicId())->whereNotNull('calendar_id'))
            ->with('calendar')->findOrFail($shareId);

        $share->update(['link_sent_at' => now()]);
        AuditLog::record('gcal.link_dikirim', $share->calendar, ['email' => $share->email]);
    }

    public function resendInvite(int $shareId, GoogleCalendarSync $sync): void
    {
        $this->authorizeOwner();
        $share = GoogleCalendarShare::whereHas('calendar', fn ($q) => $q->where('clinic_id', $this->clinicId())->whereNotNull('calendar_id'))
            ->with('calendar')->findOrFail($shareId);

        if (! $this->ensureEnabled($sync)) {
            return;
        }

        try {
            $sync->invite($share->calendar, $share);
            AuditLog::record('gcal.kirim_ulang_undangan', $share->calendar, ['email' => $share->email]);
            $this->dispatch('toast', message: "Undangan dikirim ulang ke {$share->email}.");
        } catch (GoogleCalendarException $e) {
            $this->dispatch('toast', message: 'Gagal: '.$e->getMessage());
        }
    }

    public function resend(int $id, GoogleCalendarSync $sync): void
    {
        $this->authorizeOwner();
        $appointment = Appointment::where('clinic_id', $this->clinicId())->findOrFail($id);

        if (! $this->ensureEnabled($sync)) {
            return;
        }

        $ok = $sync->syncAppointment($appointment);
        AuditLog::record('gcal.kirim_ulang', $appointment, ['berhasil' => $ok]);
        $this->dispatch('toast', message: $ok ? 'Janji tersinkron ke Google Calendar.' : 'Masih gagal: '.$appointment->fresh()->calendar_error);
    }

    /** Tandai ulang semua janji mendatang (mis. setelah email dokter/Owner diubah), lalu kirim. */
    public function resyncUpcoming(GoogleCalendarSync $sync): void
    {
        $this->authorizeOwner();

        if (! $this->ensureEnabled($sync)) {
            return;
        }

        $count = $this->markUpcoming();
        [$ok, $failed] = $sync->syncPending(200, true, $this->clinicId());
        AuditLog::record('gcal.sinkron_ulang', null, ['janji' => $count, 'berhasil' => $ok, 'gagal' => $failed]);
        $this->dispatch('toast', message: "{$ok} janji tersinkron, {$failed} gagal.");
    }

    private function markUpcoming(): int
    {
        return Appointment::where('clinic_id', $this->clinicId())
            ->whereDate('date', '>=', today())
            ->whereIn('status', [Appointment::SCHEDULED, Appointment::ARRIVED])
            ->update(['calendar_dirty' => true, 'calendar_attempts' => 0, 'calendar_error' => null]);
    }

    private function ensureEnabled(GoogleCalendarSync $sync): bool
    {
        if (! $sync->enabled()) {
            $this->dispatch('toast', message: 'Aktifkan integrasi Google Calendar terlebih dulu.');

            return false;
        }

        return true;
    }

    private function doctors()
    {
        return Doctor::where('clinic_id', $this->clinicId())->where('is_active', true)->orderBy('name')->get();
    }

    private function authorizeOwner(): void
    {
        abort_unless(app(BranchContext::class)->is(Role::Owner), 403);
    }

    private function clinicId(): int
    {
        return (int) auth()->user()->clinic_id;
    }

    public function render(GoogleCalendarSync $sync)
    {
        $calendars = GoogleCalendar::where('clinic_id', $this->clinicId())->with(['shares', 'doctor'])->get();

        // Riwayat: janji yang pernah/perlu dikirim. Janji lampau yang belum pernah terkirim tidak diproses syncPending, jadi tidak ditampilkan.
        $log = Appointment::where('clinic_id', $this->clinicId())
            ->where(fn ($q) => $q->whereNotNull('calendar_synced_at')
                ->orWhere(fn ($q) => $q->where('calendar_dirty', true)
                    ->where(fn ($q) => $q->whereDate('date', '>=', today())->orWhereHas('calendarEvents'))));

        $filtered = (clone $log)->when($this->statusFilter, fn ($q, $s) => match ($s) {
            'pending' => $q->where('calendar_dirty', true)->whereNull('calendar_error'),
            'failed' => $q->where('calendar_dirty', true)->whereNotNull('calendar_error'),
            'synced' => $q->where('calendar_dirty', false)->whereIn('status', [Appointment::SCHEDULED, Appointment::ARRIVED]),
            'removed' => $q->where('calendar_dirty', false)->whereNotIn('status', [Appointment::SCHEDULED, Appointment::ARRIVED]),
            default => $q,
        });

        return view('livewire.google-calendar-panel', [
            'serviceEmail' => $sync->client()->serviceAccountEmail(),
            'savedEnabled' => $sync->enabled(),
            'doctors' => $this->doctors(),
            'owners' => GoogleCalendarSync::extraEmails(),
            'calendars' => $calendars->keyBy(fn (GoogleCalendar $c) => $c->doctor_id ?? 'owner'),
            'calendarTitles' => $calendars->filter(fn ($c) => $c->calendar_id)->mapWithKeys(fn (GoogleCalendar $c) => [$c->calendar_id => $c->title()]),
            'counts' => [
                'pending' => (clone $log)->where('calendar_dirty', true)->whereNull('calendar_error')->count(),
                'failed' => (clone $log)->where('calendar_dirty', true)->whereNotNull('calendar_error')->count(),
            ],
            'deliveries' => $filtered->with(['patient', 'branch', 'doctor', 'calendarEvents'])
                ->orderByDesc('date')->orderByDesc('time')->orderByDesc('id')
                ->paginate(15, pageName: 'kirim'),
            'maxAttempts' => config('google_calendar.max_attempts'),
        ]);
    }
}
