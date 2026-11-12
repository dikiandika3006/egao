<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Livewire\Concerns\ModulePage;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\ScheduleOverride;
use App\Support\BranchContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * 06 Jadwal dokter (FR-JDW-01 … 11): kisi mingguan lintas cabang, jadwal rutin, pindah sementara & libur.
 */
class Jadwal extends Component
{
    use ModulePage;

    /** Senin minggu yang ditampilkan (Y-m-d). */
    #[Url(as: 'minggu', except: '')]
    public string $week = '';

    /** null | routine | override */
    public ?string $form = null;

    public ?int $editingId = null;

    public array $routine = [];

    public array $override = [];

    public function mount(): void
    {
        $this->week = $this->weekStart()->toDateString();
    }

    public function shiftWeek(int $weeks): void
    {
        $this->week = $this->weekStart()->addWeeks($weeks)->toDateString();
    }

    public function thisWeek(): void
    {
        $this->week = today()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    private function weekStart(): Carbon
    {
        $date = rescue(fn () => Carbon::parse($this->week ?: today()), today(), false);

        return $date->startOfWeek(CarbonInterface::MONDAY);
    }

    private function canEdit(BranchContext $context): bool
    {
        return $context->is(Role::Owner, Role::Admin);
    }

    // ---- Jadwal rutin (FR-JDW-06/07) ------------------------------------------------

    public function newRoutine(?int $doctorId = null, ?int $day = null): void
    {
        $context = app(BranchContext::class);
        abort_unless($this->canEdit($context), 403);
        $this->resetValidation();
        $this->editingId = null;
        $this->routine = [
            'doctor_id' => $doctorId, 'day_of_week' => $day ?? 1,
            'branch_id' => $context->id() ?? $context->options()->first()?->id,
            'starts_at' => '10:00', 'ends_at' => '17:00', 'valid_from' => '', 'valid_until' => '', 'note' => '',
        ];
        $this->form = 'routine';
    }

    public function editRoutine(int $id, BranchContext $context): void
    {
        abort_unless($this->canEdit($context), 403);
        $s = $this->scheduleQuery()->findOrFail($id);
        $this->resetValidation();
        $this->editingId = $s->id;
        $this->routine = [
            'doctor_id' => $s->doctor_id, 'day_of_week' => $s->day_of_week, 'branch_id' => $s->branch_id,
            'starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5),
            'valid_from' => $s->valid_from?->toDateString() ?? '', 'valid_until' => $s->valid_until?->toDateString() ?? '',
            'note' => (string) $s->note,
        ];
        $this->form = 'routine';
    }

    public function saveRoutine(BranchContext $context): void
    {
        abort_unless($this->canEdit($context), 403);

        $data = $this->validate([
            'routine.doctor_id' => ['required', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId())],
            'routine.branch_id' => ['required', Rule::in($context->options()->pluck('id')->all())],
            'routine.day_of_week' => ['required', 'integer', 'between:1,7'],
            'routine.starts_at' => ['required', 'date_format:H:i'],
            'routine.ends_at' => ['required', 'date_format:H:i', 'after:routine.starts_at'],
            'routine.valid_from' => ['nullable', 'date'],
            'routine.valid_until' => ['nullable', 'date', 'after_or_equal:routine.valid_from'],
            'routine.note' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'routine.doctor_id' => 'dokter', 'routine.branch_id' => 'cabang', 'routine.ends_at' => 'jam selesai',
            'routine.starts_at' => 'jam mulai', 'routine.valid_until' => 'berlaku sampai',
        ])['routine'];

        $data['valid_from'] = $data['valid_from'] ?: null;
        $data['valid_until'] = $data['valid_until'] ?: null;
        $data['note'] = $data['note'] ?: null;

        $this->assertNoRoutineClash($data);

        $schedule = $this->editingId ? $this->scheduleQuery()->findOrFail($this->editingId) : new DoctorSchedule;
        $schedule->fill($data)->save();

        AuditLog::record($this->editingId ? 'schedule.updated' : 'schedule.created', $schedule, $data);
        $this->closeForm();
        $this->toast('Jadwal rutin disimpan.');
    }

    /** FR-JDW-03: satu dokter, satu lokasi di jam yang sama. */
    private function assertNoRoutineClash(array $data): void
    {
        $from = $data['valid_from'] ? Carbon::parse($data['valid_from']) : null;
        $until = $data['valid_until'] ? Carbon::parse($data['valid_until']) : null;

        $clash = DoctorSchedule::query()
            ->with('branch')
            ->where('doctor_id', $data['doctor_id'])
            ->where('day_of_week', $data['day_of_week'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->get()
            ->first(fn (DoctorSchedule $s) => (! $until || ! $s->valid_from || $s->valid_from->lte($until))
                && (! $from || ! $s->valid_until || $s->valid_until->gte($from)));

        if ($clash) {
            throw ValidationException::withMessages([
                'routine.starts_at' => "Bentrok dengan jadwal {$clash->branch->shortName()} "
                    .substr($clash->starts_at, 0, 5).'–'.substr($clash->ends_at, 0, 5).' di hari yang sama.',
            ]);
        }
    }

    public function deleteRoutine(int $id, BranchContext $context): void
    {
        abort_unless($this->canEdit($context), 403);
        $schedule = $this->scheduleQuery()->findOrFail($id);
        AuditLog::record('schedule.deleted', $schedule, $schedule->only(['doctor_id', 'branch_id', 'day_of_week', 'starts_at', 'ends_at']));
        $schedule->delete();
        $this->closeForm();
        $this->toast('Jadwal rutin dihapus.');
    }

    // ---- Pindah sementara & libur (FR-JDW-08/10) ------------------------------------

    public function openDay(int $doctorId, string $date, BranchContext $context): void
    {
        abort_unless($this->canEdit($context), 403);
        $this->resetValidation();

        $existing = ScheduleOverride::where('doctor_id', $doctorId)->whereDate('date', $date)->first();
        $this->editingId = $existing?->id;
        $this->override = [
            'doctor_id' => $doctorId,
            'date' => $date,
            'type' => $existing?->type ?? ScheduleOverride::TYPE_MOVE,
            'branch_id' => $existing?->branch_id ?? $context->id() ?? $context->options()->first()?->id,
            'starts_at' => $existing?->starts_at ? substr($existing->starts_at, 0, 5) : '',
            'ends_at' => $existing?->ends_at ? substr($existing->ends_at, 0, 5) : '',
            'note' => (string) $existing?->note,
            'substitute_id' => '',
            'move_appointments' => true,
        ];
        $this->form = 'override';
    }

    public function saveOverride(BranchContext $context): void
    {
        abort_unless($this->canEdit($context), 403);

        $data = $this->validate([
            'override.doctor_id' => ['required', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId())],
            'override.date' => ['required', 'date'],
            'override.type' => ['required', Rule::in([ScheduleOverride::TYPE_MOVE, ScheduleOverride::TYPE_OFF])],
            'override.branch_id' => ['required_if:override.type,move', 'nullable', Rule::in($context->options()->pluck('id')->all())],
            'override.starts_at' => ['nullable', 'date_format:H:i'],
            'override.ends_at' => ['nullable', 'date_format:H:i', 'after:override.starts_at'],
            'override.note' => ['nullable', 'string', 'max:255'],
            'override.substitute_id' => ['nullable', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId()), 'different:override.doctor_id'],
            'override.move_appointments' => ['boolean'],
        ], attributes: ['override.branch_id' => 'cabang tujuan', 'override.ends_at' => 'jam selesai', 'override.substitute_id' => 'dokter pengganti'])['override'];

        $off = $data['type'] === ScheduleOverride::TYPE_OFF;

        // Izin + pengganti: simpan dua pengecualian sekaligus (dokter libur, pengganti praktik di cabang & jam yang sama).
        if ($off && filled($data['substitute_id'] ?? null)) {
            $this->saveWithSubstitute($data);

            return;
        }
        $values = [
            'type' => $data['type'],
            'branch_id' => $off ? null : $data['branch_id'],
            'starts_at' => $off ? null : ($data['starts_at'] ?: null),
            'ends_at' => $off ? null : ($data['ends_at'] ?: null),
            'note' => $data['note'] ?: null,
        ];

        $override = ScheduleOverride::updateOrCreate(
            ['doctor_id' => $data['doctor_id'], 'date' => Carbon::parse($data['date'])->toDateString()],
            $values,
        );

        AuditLog::record('schedule.override', $override, $values + ['date' => $data['date']]);
        $this->closeForm();
        $this->toast($off ? 'Dokter ditandai libur.' : 'Pindah cabang sementara disimpan.');
    }

    /**
     * Permintaan klien: "dokter jaga izin, digantikan drg lain" dalam satu langkah.
     * Dokter yang izin → libur; pengganti → praktik di cabang & jam dokter yang izin (aturan satu dokter satu lokasi tetap dicek).
     */
    private function saveWithSubstitute(array $data): void
    {
        $date = Carbon::parse($data['date'])->startOfDay();
        $doctors = Doctor::where('clinic_id', $this->clinicId())
            ->whereIn('id', [$data['doctor_id'], $data['substitute_id']])
            ->with(['schedules', 'overrides' => fn ($q) => $q->whereDate('date', $date)])
            ->get()->keyBy('id');

        $absent = $doctors->get((int) $data['doctor_id']);
        $sub = $doctors->get((int) $data['substitute_id']);
        $slot = $absent->scheduleOn($date);

        if (! $slot) {
            throw ValidationException::withMessages(['override.substitute_id' => "{$absent->name} memang tidak terjadwal pada tanggal ini, tidak perlu pengganti."]);
        }

        $subSlot = $sub->scheduleOn($date);
        if ($subSlot && $subSlot['branch_id'] !== $slot['branch_id']) {
            $branch = \App\Models\Branch::find($subSlot['branch_id']);
            throw ValidationException::withMessages(['override.substitute_id' => "{$sub->name} sedang bertugas di {$branch?->name} hari itu (satu dokter, satu lokasi)."]);
        }

        $note = $data['note'] ?: null;
        $moved = 0;

        DB::transaction(function () use ($data, $date, $absent, $sub, $slot, $note, &$moved) {
            ScheduleOverride::updateOrCreate(
                ['doctor_id' => $absent->id, 'date' => $date->toDateString()],
                ['type' => ScheduleOverride::TYPE_OFF, 'branch_id' => null, 'starts_at' => null, 'ends_at' => null,
                    'note' => trim('Izin, digantikan '.$sub->name.($note ? " — {$note}" : ''))],
            );
            ScheduleOverride::updateOrCreate(
                ['doctor_id' => $sub->id, 'date' => $date->toDateString()],
                ['type' => ScheduleOverride::TYPE_MOVE, 'branch_id' => $slot['branch_id'],
                    'starts_at' => substr($slot['starts_at'], 0, 5), 'ends_at' => substr($slot['ends_at'], 0, 5),
                    'note' => 'Menggantikan '.$absent->name],
            );

            if ($data['move_appointments'] ?? false) {
                $moved = Appointment::where('doctor_id', $absent->id)->whereDate('date', $date)
                    ->where('branch_id', $slot['branch_id'])->where('status', Appointment::SCHEDULED)
                    ->update(['doctor_id' => $sub->id]);
            }
        });

        AuditLog::record('schedule.substitute', $absent, ['date' => $date->toDateString(), 'substitute_id' => $sub->id, 'appointments_moved' => $moved]);
        $this->closeForm();
        $this->toast("{$absent->name} izin, digantikan {$sub->name}".($moved ? "; {$moved} janji temu dipindahkan." : '.'));
    }

    public function deleteOverride(BranchContext $context): void
    {
        abort_unless($this->canEdit($context) && $this->editingId, 403);
        $override = ScheduleOverride::query()
            ->whereHas('doctor', fn ($q) => $q->where('clinic_id', $this->clinicId()))
            ->findOrFail($this->editingId);
        AuditLog::record('schedule.override.deleted', $override, ['date' => $override->date->toDateString()]);
        $override->delete();
        $this->closeForm();
        $this->toast('Kembali ke jadwal rutin.');
    }

    public function closeForm(): void
    {
        $this->form = null;
        $this->editingId = null;
        $this->resetValidation();
    }

    private function scheduleQuery()
    {
        return DoctorSchedule::query()->whereHas('doctor', fn ($q) => $q->where('clinic_id', $this->clinicId()));
    }

    public function render(BranchContext $context)
    {
        $start = $this->weekStart();
        $end = $start->copy()->addDays(6);
        $days = collect(range(0, 6))->map(fn (int $i) => $start->copy()->addDays($i));
        $branches = $context->options()->keyBy('id');

        $doctors = Doctor::query()
            ->where('clinic_id', $this->clinicId())
            ->where('is_active', true)
            ->with([
                'primaryBranch',
                'schedules' => fn ($q) => $q->with('branch')->orderBy('day_of_week')->orderBy('starts_at'),
                'overrides' => fn ($q) => $q->whereBetween('date', [$start->toDateString(), $end->toDateString()]),
            ])
            ->orderBy('name')
            ->get();

        $grid = $doctors->map(function (Doctor $doctor) use ($days, $branches) {
            return [
                'doctor' => $doctor,
                'cells' => $days->map(function (Carbon $day) use ($doctor, $branches) {
                    $override = $doctor->overrides->first(fn (ScheduleOverride $o) => $o->date->isSameDay($day));
                    $schedule = $doctor->scheduleOn($day);

                    return [
                        'date' => $day,
                        'schedule' => $schedule,
                        'branch' => $schedule ? $branches->get($schedule['branch_id']) : null,
                        'off' => $override?->type === ScheduleOverride::TYPE_OFF,
                        'note' => $override?->note,
                    ];
                }),
            ];
        });

        $perDay = $days->map(fn (Carbon $day, int $i) => $branches->map(fn ($b) => $grid->filter(
            fn ($row) => ($row['cells'][$i]['schedule']['branch_id'] ?? null) === $b->id
        )->count()));

        return $this->page('jadwal', 'livewire.modules.jadwal', [
            'context' => $context,
            'days' => $days,
            'grid' => $grid,
            'perDay' => $perDay,
            'branches' => $branches,
            'doctors' => $doctors,
            'canEdit' => $this->canEdit($context),
            'dayNames' => [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'],
        ]);
    }
}
