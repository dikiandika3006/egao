<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Livewire\Concerns\ModulePage;
use App\Models\Appointment;
use App\Models\AppointmentReschedule;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
use App\Support\AppointmentRules;
use App\Support\BranchContext;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Janji temu & kontrol (permintaan klien poin 1: "jadwal harian pasien").
 * - Admin mencatat janji untuk tanggal mendatang, di cabang mana pun milik klinik.
 * - Jadwal ulang: ganti tanggal/jam/cabang/dokter dengan alasan, riwayat tersimpan (appointment_reschedules).
 * - Rencana evaluasi dokter di rekam medis otomatis menjadi janji "kontrol" (ExamService::syncFollowUp).
 * - Hari-H: tombol "Datang" langsung membuat nomor antrean.
 */
class JanjiTemu extends Component
{
    use ModulePage;

    public const MENU = 'janji';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['shiftDay', 'goToday', 'setView', 'shiftMonth', 'closeStatus'];

    /** Pilihan cepat alasan batal / tidak datang / jadwal ulang. */
    public const REASONS = ['Tidak bisa dihubungi', 'Pasien sakit', 'Minta jadwal lain', 'Dokter berhalangan'];

    #[Url(as: 'tanggal', except: '')]
    public string $date = '';

    /** minggu | bulan (kalender bulanan) */
    #[Url(as: 'tampilan', except: 'minggu')]
    public string $view = 'minggu';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public string $patientSearch = '';

    /** Modal alasan batal / tidak datang: ['id' => ?int, 'status' => string, 'reason' => string]. */
    public array $statusForm = ['id' => null, 'status' => '', 'reason' => ''];

    public function mount(): void
    {
        $this->date = $this->day()->toDateString();
        $this->resetForm();
    }

    private function day(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->date ?: today())->startOfDay(), today(), false);
    }

    public function shiftDay(int $n): void
    {
        $this->date = $this->day()->addDays($n)->toDateString();
    }

    public function goToday(): void
    {
        $this->date = today()->toDateString();
    }

    public function setView(string $view): void
    {
        abort_unless(in_array($view, ['minggu', 'bulan'], true), 404);
        $this->view = $view;
    }

    /** Navigasi kalender bulanan; tanggal terpilih pindah ke tanggal 1 bulan tujuan (atau hari ini bila bulan berjalan). */
    public function shiftMonth(int $n): void
    {
        $target = $this->day()->startOfMonth()->addMonthsNoOverflow($n);
        $this->date = ($target->isSameMonth(today()) ? today() : $target)->toDateString();
    }

    private function canWrite(): bool
    {
        return app(BranchContext::class)->is(Role::Admin, Role::Owner) && $this->canEditMenu();
    }

    private function resetForm(): void
    {
        $this->form = [
            'patient_id' => null, 'branch_id' => (string) (app(BranchContext::class)->id() ?? ''),
            'date' => $this->day()->toDateString(), 'time' => '', 'doctor_id' => '', 'purpose' => '', 'note' => '', 'reason' => '',
        ];
        $this->editingId = null;
        $this->patientSearch = '';
    }

    public function create(BranchContext $context): void
    {
        abort_unless($this->canWrite(), 403);
        $this->resetForm();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    /** Jadwal ulang: janji terjadwal, tidak datang, atau batal (tanggal berapa pun). Janji yang sudah datang tidak bisa. */
    public function edit(int $id): void
    {
        abort_unless($this->canWrite(), 403);
        $a = $this->query()->findOrFail($id);
        abort_unless($this->reschedulable($a), 422);

        $this->editingId = $a->id;
        $this->form = [
            'patient_id' => $a->patient_id, 'branch_id' => (string) $a->branch_id,
            // Janji yang tanggalnya sudah lewat: usulkan hari ini sebagai tanggal baru.
            'date' => ($a->date->isPast() && ! $a->date->isToday() ? today() : $a->date)->toDateString(),
            'time' => $a->time ? substr($a->time, 0, 5) : '',
            'doctor_id' => (string) $a->doctor_id, 'purpose' => (string) $a->purpose, 'note' => (string) $a->note, 'reason' => '',
        ];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    private function reschedulable(Appointment $a): bool
    {
        return in_array($a->status, [Appointment::SCHEDULED, Appointment::NO_SHOW, Appointment::CANCELLED], true);
    }

    public function pickPatient(int $id): void
    {
        $this->form['patient_id'] = Patient::where('clinic_id', $this->clinicId())->findOrFail($id)->id;
        $this->patientSearch = '';
    }

    public function save(BranchContext $context): void
    {
        abort_unless($this->canWrite(), 403);

        $data = $this->validate([
            'form.patient_id' => ['required', Rule::exists('patients', 'id')->where('clinic_id', $this->clinicId())],
            // Semua cabang aktif klinik boleh dipilih (pasien dialihkan ke cabang lain), bukan hanya cabang akses pengguna.
            'form.branch_id' => ['required', Rule::exists('branches', 'id')->where('clinic_id', $this->clinicId())->where('is_active', true)],
            'form.date' => ['required', 'date', 'after_or_equal:today'],
            'form.time' => ['nullable', 'date_format:H:i'],
            'form.doctor_id' => ['nullable', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId())],
            'form.purpose' => ['nullable', 'string', 'max:255'],
            'form.note' => ['nullable', 'string', 'max:255'],
            'form.reason' => ['nullable', 'string', 'max:255'],
        ], [
            'form.date.after_or_equal' => 'Tanggal tidak boleh sebelum hari ini.',
            'form.branch_id.required' => 'Pilih cabang terlebih dulu.',
        ], [
            'form.patient_id' => 'pasien', 'form.branch_id' => 'cabang', 'form.date' => 'tanggal', 'form.time' => 'jam',
            'form.doctor_id' => 'dokter', 'form.reason' => 'alasan',
        ])['form'];

        $branchId = (int) $data['branch_id'];
        $doctorId = filled($data['doctor_id']) ? (int) $data['doctor_id'] : null;
        $time = filled($data['time']) ? $data['time'] : null;

        $appointment = $this->editingId
            ? $this->query()->findOrFail($this->editingId)
            : new Appointment([
                'clinic_id' => $this->clinicId(), 'patient_id' => (int) $data['patient_id'],
                'source' => 'manual', 'status' => Appointment::SCHEDULED, 'created_by' => auth()->id(),
            ]);

        if ($this->editingId) {
            abort_unless($this->reschedulable($appointment), 422);
        }

        // Jadwal ulang = tanggal/jam/cabang/dokter berubah, atau janji batal/tidak datang dihidupkan lagi → wajib alasan.
        $moved = $this->editingId && (
            $appointment->date->toDateString() !== $data['date']
            || ($appointment->time ? substr($appointment->time, 0, 5) : null) !== $time
            || (int) $appointment->branch_id !== $branchId
            || ($appointment->doctor_id ? (int) $appointment->doctor_id : null) !== $doctorId
            || $appointment->status !== Appointment::SCHEDULED
        );
        $reason = trim((string) $data['reason']);

        if ($moved && $reason === '') {
            throw ValidationException::withMessages(['form.reason' => 'Isi alasan jadwal ulang.']);
        }

        AppointmentRules::rejectDoubleBooking($this->clinicId(), $doctorId, $data['date'], $time, $appointment->id);

        $before = $appointment->exists ? $appointment->only(['status', 'date', 'time', 'branch_id', 'doctor_id']) : null;

        DB::transaction(function () use ($appointment, $data, $branchId, $doctorId, $time, $moved, $reason, $before) {
            $appointment->fill([
                'branch_id' => $branchId, 'date' => $data['date'], 'time' => $time, 'doctor_id' => $doctorId,
                'purpose' => $data['purpose'] ?: null, 'note' => $data['note'] ?: null,
                'status' => Appointment::SCHEDULED, 'status_reason' => null,
            ])->save();

            if ($moved) {
                AppointmentReschedule::create([
                    'appointment_id' => $appointment->id, 'from_status' => $before['status'],
                    'from_date' => $before['date'], 'from_time' => $before['time'],
                    'from_branch_id' => $before['branch_id'], 'from_doctor_id' => $before['doctor_id'],
                    'to_date' => $data['date'], 'to_time' => $time, 'to_branch_id' => $branchId, 'to_doctor_id' => $doctorId,
                    'reason' => $reason, 'user_id' => auth()->id(),
                ]);
            }
        });

        if ($moved) {
            AuditLog::record('janji.dijadwal_ulang', $appointment, [
                'sebelum' => ['status' => $before['status'], 'date' => $before['date']->toDateString(), 'time' => $before['time'], 'branch_id' => $before['branch_id'], 'doctor_id' => $before['doctor_id']],
                'sesudah' => ['date' => $data['date'], 'time' => $time, 'branch_id' => $branchId, 'doctor_id' => $doctorId],
                'alasan' => $reason,
            ]);
        } else {
            AuditLog::record($this->editingId ? 'janji.updated' : 'janji.created', $appointment, $appointment->only(['date', 'time', 'branch_id', 'doctor_id']));
        }

        $appointment->load('branch');
        $this->date = $appointment->date->toDateString();
        $this->showForm = false;
        $warning = AppointmentRules::hoursWarning($appointment->branch, $appointment->date, $appointment->time);
        $away = $appointment->branch_id !== app(BranchContext::class)->id() && ! app(BranchContext::class)->isAll()
            ? " Janji ada di {$appointment->branch->shortName()}." : '';
        $this->toast(($moved ? 'Janji dijadwal ulang.' : 'Janji temu tersimpan.').$away.($warning ? ' Perhatian: '.str_replace(' Janji tetap bisa disimpan.', '', $warning) : ''));
    }

    /** Pasien datang → buat kunjungan & nomor antrean hari ini. */
    public function checkIn(int $id): void
    {
        abort_unless($this->canWrite(), 403);
        $a = $this->query()->with('patient')->findOrFail($id);
        abort_unless($a->status === Appointment::SCHEDULED, 422);

        if (! $a->date->isToday()) {
            throw ValidationException::withMessages(['checkin' => 'Pasien hanya bisa dimasukkan ke antrean pada hari janjinya. Ubah tanggal janji bila datang lebih awal.']);
        }

        $doctorId = $a->doctor_id ?? AppointmentRules::doctorsOn($this->clinicId(), $a->branch_id, today())->first()?->id;
        if (! $doctorId) {
            throw ValidationException::withMessages(['checkin' => 'Belum ada dokter bertugas di cabang ini hari ini. Pilih dokter di janji temu.']);
        }

        $visit = DB::transaction(function () use ($a, $doctorId) {
            DB::table('doctors')->where('id', $doctorId)->lockForUpdate()->first();

            $existing = Visit::where('patient_id', $a->patient_id)->where('branch_id', $a->branch_id)
                ->whereDate('visit_date', today())->where('status', '!=', VisitStatus::Batal)->first();

            $visit = $existing ?? Visit::create([
                'patient_id' => $a->patient_id, 'doctor_id' => $doctorId, 'branch_id' => $a->branch_id,
                'registered_by' => auth()->id(), 'visit_date' => today()->toDateString(),
                'queue_number' => Visit::nextQueueNumber($a->branch_id, $doctorId, today()->toDateString()),
                'scheduled_at' => $a->time ? substr($a->time, 0, 5) : now()->format('H:i'),
                'purpose' => $a->purpose ?: 'Kontrol', 'complaint' => $a->source === 'kontrol' ? ($a->purpose ?: 'Kontrol') : null,
                'status' => VisitStatus::Menunggu,
            ]);

            $a->update(['status' => Appointment::ARRIVED, 'visit_id' => $visit->id]);

            return $visit;
        });

        AuditLog::record('janji.datang', $a, ['visit_id' => $visit->id]);
        $this->toast("{$a->patient->name} masuk antrean nomor {$visit->queueLabel()}.");
    }

    /** Buka modal alasan untuk Batal / Tidak datang. */
    public function askStatus(int $id, string $status): void
    {
        abort_unless($this->canWrite(), 403);
        abort_unless(in_array($status, [Appointment::CANCELLED, Appointment::NO_SHOW], true), 422);
        $a = $this->query()->findOrFail($id);
        abort_unless($a->status === Appointment::SCHEDULED, 422);

        $this->resetErrorBag();
        $this->statusForm = ['id' => $a->id, 'status' => $status, 'reason' => ''];
    }

    public function closeStatus(): void
    {
        $this->statusForm = ['id' => null, 'status' => '', 'reason' => ''];
    }

    public function confirmStatus(): void
    {
        abort_unless($this->statusForm['id'], 422);
        $this->validate(['statusForm.reason' => ['nullable', 'string', 'max:255']], attributes: ['statusForm.reason' => 'alasan']);

        $this->setStatus((int) $this->statusForm['id'], (string) $this->statusForm['status'], trim((string) $this->statusForm['reason']) ?: null);
        $this->closeStatus();
    }

    /** Ubah status janji. Pulihkan (terjadwal) mengosongkan alasan. */
    public function setStatus(int $id, string $status, ?string $reason = null): void
    {
        abort_unless($this->canWrite(), 403);
        abort_unless(in_array($status, [Appointment::CANCELLED, Appointment::NO_SHOW, Appointment::SCHEDULED], true), 422);

        $a = $this->query()->findOrFail($id);
        abort_if($a->status === Appointment::ARRIVED, 422);
        $reason = $status === Appointment::SCHEDULED ? null : ($reason !== null ? mb_substr($reason, 0, 255) : null);

        $a->update(['status' => $status, 'status_reason' => $reason]);
        AuditLog::record('janji.status', $a, ['status' => $status, 'alasan' => $reason]);

        if ($status !== Appointment::SCHEDULED) {
            $this->toast($status === Appointment::CANCELLED ? 'Janji dibatalkan.' : 'Ditandai tidak datang.');
        }
    }

    private function query()
    {
        $context = app(BranchContext::class);

        return Appointment::query()
            ->where('clinic_id', $this->clinicId())
            ->when($context->id(), fn ($q) => $q->where('branch_id', $context->id()), fn ($q) => $q->whereIn('branch_id', $context->options()->pluck('id')));
    }

    public function render(BranchContext $context)
    {
        $day = $this->day();
        $weekStart = $day->copy()->startOfWeek();

        $appointments = $this->query()->whereDate('date', $day)
            ->with(['patient', 'doctor', 'branch', 'visit', 'reschedules'])
            ->orderByRaw('time IS NULL')->orderBy('time')->orderBy('id')
            ->get();

        $weekCounts = $this->query()
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->whereIn('status', [Appointment::SCHEDULED, Appointment::ARRIVED])
            ->selectRaw('DATE(date) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd');

        // Kalender bulanan: grid Senin–Minggu yang menutup seluruh bulan, janji dikelompokkan per tanggal.
        $month = null;
        if ($this->view === 'bulan') {
            $gridStart = $day->copy()->startOfMonth()->startOfWeek();
            $gridEnd = $day->copy()->endOfMonth()->endOfWeek();

            $byDate = $this->query()
                ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->where('status', '!=', Appointment::CANCELLED)
                ->with('patient')
                ->orderByRaw('time IS NULL')->orderBy('time')->orderBy('id')
                ->get()
                ->groupBy(fn (Appointment $a) => $a->date->toDateString());

            $month = collect();
            for ($d = $gridStart->copy(); $d->lte($gridEnd); $d->addDay()) {
                $month->push(['date' => $d->copy(), 'items' => $byDate->get($d->toDateString(), collect())]);
            }
        }

        $branches = AppointmentRules::branches($this->clinicId());
        $formDate = rescue(fn () => Carbon::parse($this->form['date'] ?? $day), $day, false);
        $branchId = (int) ($this->form['branch_id'] ?? 0) ?: null;
        $branchId = $branches->contains('id', $branchId) ? $branchId : null;

        // Panel di form: dokter bertugas di tanggal & cabang itu + jam yang sudah terisi (untuk menjawab pasien via WA).
        $dayPlan = collect();
        $onDuty = $this->showForm && $branchId ? AppointmentRules::doctorsOn($this->clinicId(), $branchId, $formDate) : collect();
        if ($this->showForm && $branchId) {
            $booked = Appointment::where('clinic_id', $this->clinicId())->whereDate('date', $formDate)
                ->where('status', Appointment::SCHEDULED)->whereNotNull('doctor_id')
                ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
                ->with('patient')->orderBy('time')->get()->groupBy('doctor_id');
            $dayPlan = $onDuty->map(fn (Doctor $d) => [
                'doctor' => $d,
                'schedule' => $d->scheduleOn($formDate),
                'booked' => $booked->get($d->id, collect()),
            ]);
        }

        // Jam kerja cabang di tanggal form + peringatan bila jam janji di luar jam kerja (tidak memblokir simpan).
        $formBranch = $this->showForm && $branchId ? $branches->firstWhere('id', $branchId) : null;
        $hoursWarning = $formBranch ? AppointmentRules::hoursWarning($formBranch, $formDate, $this->form['time'] ?? '') : null;

        // Janji pada hari terpilih yang dokternya tidak bertugas di cabang itu (izin/libur/pindah) → perlu dikabari.
        $dutyByBranch = $appointments->pluck('branch_id')->unique()
            ->mapWithKeys(fn ($bid) => [$bid => AppointmentRules::doctorsOn($this->clinicId(), $bid, $day)->pluck('id')->all()]);
        $doctorAway = $appointments
            ->filter(fn (Appointment $a) => $a->status === Appointment::SCHEDULED && $a->doctor_id
                && ! in_array($a->doctor_id, $dutyByBranch[$a->branch_id] ?? [], true))
            ->pluck('id')->all();

        // Janji terjadwal pada hari terpilih yang jatuh di luar jam kerja cabang (hanya penanda).
        $outsideHours = $appointments
            ->filter(fn (Appointment $a) => $a->status === Appointment::SCHEDULED
                && AppointmentRules::hoursWarning($a->branch, $a->date, $a->time) !== null)
            ->pluck('id')->all();

        $editing = $this->showForm && $this->editingId
            ? $this->query()->with(['reschedules.fromBranch', 'reschedules.user'])->find($this->editingId)
            : null;

        return $this->page('janji', 'livewire.modules.janji-temu', [
            'context' => $context,
            'day' => $day,
            'appointments' => $appointments,
            'month' => $month,
            'week' => collect(range(0, 6))->map(fn ($i) => ['date' => $d = $weekStart->copy()->addDays($i), 'count' => (int) ($weekCounts[$d->toDateString()] ?? 0)]),
            'upcoming' => $this->query()->where('status', Appointment::SCHEDULED)
                ->whereBetween('date', [today()->addDay()->toDateString(), today()->addDays(7)->toDateString()])->count(),
            'canWrite' => $this->canWrite(),
            'gcalEnabled' => Settings::bool('gcal_enabled'),
            'branches' => $branches,
            'doctors' => Doctor::where('clinic_id', $this->clinicId())->where('is_active', true)->orderBy('name')->get(),
            'onDutyIds' => $onDuty->pluck('id')->all(),
            'dayPlan' => $dayPlan,
            'formBranch' => $formBranch,
            'hoursWarning' => $hoursWarning,
            'doctorAway' => $doctorAway,
            'outsideHours' => $outsideHours,
            'editing' => $editing,
            'reasons' => self::REASONS,
            'selectedPatient' => ($this->form['patient_id'] ?? null) ? Patient::find($this->form['patient_id']) : null,
            'patientResults' => mb_strlen(trim($this->patientSearch)) >= 2
                ? Patient::where('clinic_id', $this->clinicId())->search($this->patientSearch)->limit(6)->get()
                : collect(),
        ]);
    }
}
