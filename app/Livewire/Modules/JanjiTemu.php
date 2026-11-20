<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Livewire\Concerns\ModulePage;
use App\Livewire\Concerns\PicksBranch;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
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
 * - Admin mencatat janji untuk tanggal mendatang.
 * - Rencana evaluasi dokter di rekam medis otomatis menjadi janji "kontrol" (ExamService::syncFollowUp).
 * - Hari-H: tombol "Datang" langsung membuat nomor antrean.
 */
class JanjiTemu extends Component
{
    use ModulePage, PicksBranch;

    #[Url(as: 'tanggal', except: '')]
    public string $date = '';

    /** minggu | bulan (kalender bulanan) */
    #[Url(as: 'tampilan', except: 'minggu')]
    public string $view = 'minggu';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public string $patientSearch = '';

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
        return app(BranchContext::class)->is(Role::Admin, Role::Owner);
    }

    private function resetForm(): void
    {
        $this->form = ['patient_id' => null, 'date' => $this->day()->toDateString(), 'time' => '', 'doctor_id' => '', 'purpose' => '', 'note' => ''];
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

    public function edit(int $id): void
    {
        abort_unless($this->canWrite(), 403);
        $a = $this->query()->findOrFail($id);
        abort_unless($a->status === Appointment::SCHEDULED, 422);

        $this->editingId = $a->id;
        $this->form = [
            'patient_id' => $a->patient_id, 'date' => $a->date->toDateString(), 'time' => $a->time ? substr($a->time, 0, 5) : '',
            'doctor_id' => (string) $a->doctor_id, 'purpose' => (string) $a->purpose, 'note' => (string) $a->note,
        ];
        $this->resetErrorBag();
        $this->showForm = true;
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
            'form.date' => ['required', 'date', 'after_or_equal:today'],
            'form.time' => ['nullable', 'date_format:H:i'],
            'form.doctor_id' => ['nullable', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId())],
            'form.purpose' => ['nullable', 'string', 'max:255'],
            'form.note' => ['nullable', 'string', 'max:255'],
        ], ['form.date.after_or_equal' => 'Tanggal tidak boleh sebelum hari ini.'], [
            'form.patient_id' => 'pasien', 'form.date' => 'tanggal', 'form.time' => 'jam', 'form.doctor_id' => 'dokter',
        ])['form'];

        $appointment = $this->editingId
            ? $this->query()->findOrFail($this->editingId)
            : new Appointment([
                'clinic_id' => $this->clinicId(), 'branch_id' => $this->targetBranch($context),
                'source' => 'manual', 'status' => Appointment::SCHEDULED, 'created_by' => auth()->id(),
            ]);

        $this->rejectDoubleBooking($data, $appointment->id);

        $appointment->fill([
            'patient_id' => (int) $data['patient_id'], 'date' => $data['date'], 'time' => $data['time'] ?: null,
            'doctor_id' => $data['doctor_id'] ?: null, 'purpose' => $data['purpose'] ?: null, 'note' => $data['note'] ?: null,
        ])->save();

        AuditLog::record($this->editingId ? 'janji.updated' : 'janji.created', $appointment, $appointment->only(['date', 'time', 'doctor_id']));

        $this->date = $appointment->date->toDateString();
        $this->showForm = false;
        $warning = $this->hoursWarning($appointment->loadMissing('branch')->branch, $appointment->date, $appointment->time);
        $this->toast('Janji temu tersimpan.'.($warning ? ' Perhatian: '.str_replace(' Janji tetap bisa disimpan.', '', $warning) : ''));
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

        $doctorId = $a->doctor_id ?? $this->doctorOnDuty($a->branch_id, today())?->id;
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

    public function setStatus(int $id, string $status): void
    {
        abort_unless($this->canWrite(), 403);
        abort_unless(in_array($status, [Appointment::CANCELLED, Appointment::NO_SHOW, Appointment::SCHEDULED], true), 422);

        $a = $this->query()->findOrFail($id);
        abort_if($a->status === Appointment::ARRIVED, 422);
        $a->update(['status' => $status]);
        AuditLog::record('janji.status', $a, ['status' => $status]);
    }

    /** Satu dokter tidak boleh punya dua janji berdekatan (< 30 menit) di tanggal yang sama. */
    public const SLOT_MINUTES = 30;

    private function rejectDoubleBooking(array $data, ?int $ignoreId): void
    {
        if (blank($data['doctor_id']) || blank($data['time'])) {
            return;
        }

        $time = Carbon::parse($data['date'].' '.$data['time']);

        $clash = Appointment::where('clinic_id', $this->clinicId())
            ->where('doctor_id', (int) $data['doctor_id'])
            ->whereDate('date', $data['date'])
            ->where('status', Appointment::SCHEDULED)
            ->whereNotNull('time')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with('patient')
            ->get()
            ->first(fn (Appointment $a) => abs(Carbon::parse($data['date'].' '.$a->time)->diffInMinutes($time)) < self::SLOT_MINUTES);

        if ($clash) {
            throw ValidationException::withMessages([
                'form.time' => sprintf('Dokter ini sudah ada janji jam %s (%s). Pilih jam lain, beri jarak minimal %d menit.',
                    substr($clash->time, 0, 5), $clash->patient->name, self::SLOT_MINUTES),
            ]);
        }
    }

    /** Peringatan jam kerja cabang (null = aman). Janji tetap boleh disimpan. */
    private function hoursWarning(Branch $branch, Carbon $date, ?string $time): ?string
    {
        $day = $date->translatedFormat('l');

        if (! $branch->hoursOn($date)) {
            return "{$branch->shortName()} tutup di hari {$day}. Janji tetap bisa disimpan.";
        }

        if (filled($time) && preg_match('/^\d{2}:\d{2}/', $time) && ! $branch->isOpenAt($date, $time)) {
            return sprintf('Jam %s di luar jam kerja %s (%s: %s). Janji tetap bisa disimpan.',
                str_replace(':', '.', substr($time, 0, 5)), $branch->shortName(), $day, $branch->hoursLabel($date));
        }

        return null;
    }

    private function doctorOnDuty(int $branchId, Carbon $date): ?Doctor
    {
        return $this->doctorsOn($branchId, $date)->first();
    }

    private function doctorsOn(int $branchId, Carbon $date)
    {
        return Doctor::where('clinic_id', $this->clinicId())->where('is_active', true)
            ->with(['schedules', 'overrides' => fn ($q) => $q->whereDate('date', $date)])
            ->orderBy('name')->get()
            ->filter(fn (Doctor $d) => ($d->scheduleOn($date)['branch_id'] ?? null) === $branchId)
            ->values();
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
            ->with(['patient', 'doctor', 'branch', 'visit'])
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

        $formDate = rescue(fn () => Carbon::parse($this->form['date'] ?? $day), $day, false);
        $branchId = $this->editingId ? $this->query()->whereKey($this->editingId)->value('branch_id') : $this->formBranchId($context);

        // Panel di form: dokter bertugas di tanggal & cabang itu + jam yang sudah terisi (untuk menjawab pasien via WA).
        $dayPlan = collect();
        if ($this->showForm && $branchId) {
            $onDuty = $this->doctorsOn($branchId, $formDate);
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
        $formBranch = $this->showForm && $branchId ? Branch::where('clinic_id', $this->clinicId())->find($branchId) : null;
        $hoursWarning = $formBranch ? $this->hoursWarning($formBranch, $formDate, $this->form['time'] ?? '') : null;

        // Janji pada hari terpilih yang dokternya tidak bertugas di cabang itu (izin/libur/pindah) → perlu dikabari.
        $dutyByBranch = $appointments->pluck('branch_id')->unique()
            ->mapWithKeys(fn ($bid) => [$bid => $this->doctorsOn($bid, $day)->pluck('id')->all()]);
        $doctorAway = $appointments
            ->filter(fn (Appointment $a) => $a->status === Appointment::SCHEDULED && $a->doctor_id
                && ! in_array($a->doctor_id, $dutyByBranch[$a->branch_id] ?? [], true))
            ->pluck('id')->all();

        // Janji terjadwal pada hari terpilih yang jatuh di luar jam kerja cabang (hanya penanda).
        $outsideHours = $appointments
            ->filter(fn (Appointment $a) => $a->status === Appointment::SCHEDULED
                && $this->hoursWarning($a->branch, $a->date, $a->time) !== null)
            ->pluck('id')->all();

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
            'doctors' => Doctor::where('clinic_id', $this->clinicId())->where('is_active', true)->orderBy('name')->get(),
            'onDutyIds' => $branchId ? $this->doctorsOn($branchId, $formDate)->pluck('id')->all() : [],
            'dayPlan' => $dayPlan,
            'formBranch' => $formBranch,
            'hoursWarning' => $hoursWarning,
            'doctorAway' => $doctorAway,
            'outsideHours' => $outsideHours,
            'selectedPatient' => ($this->form['patient_id'] ?? null) ? Patient::find($this->form['patient_id']) : null,
            'patientResults' => mb_strlen(trim($this->patientSearch)) >= 2
                ? Patient::where('clinic_id', $this->clinicId())->search($this->patientSearch)->limit(6)->get()
                : collect(),
        ]);
    }
}
