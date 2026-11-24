<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\DoctorShiftAttendance;
use App\Models\Employee;
use App\Models\EmployeeShiftAttendance;
use App\Services\AttendanceService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Absensi shift berbasis lokasi (dokter & asisten) + rekap bulanan. Koreksi manual khusus Owner (tercatat di audit).
 * Kehadiran dokter = doctor_shift_attendances (uang duduk), asisten = employee_shift_attendances (upah shift & lembur).
 */
class Absensi extends Component
{
    use ModulePage;

    public const MENU = 'absensi';

    public const READ_ACTIONS = ['closeForm'];

    #[Url(as: 'bulan', except: '')]
    public string $month = '';

    /** null | edit | manual */
    public ?string $form = null;

    public ?string $editingKey = null;

    public array $edit = [];

    public array $manual = [];

    public function mount(): void
    {
        $this->month = $this->period()->format('Y-m');
    }

    private function period(): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $this->month ?: now()->format('Y-m'))->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    // ---- Absen pribadi -------------------------------------------------------------------

    public function checkIn(int $shift, float $lat, float $lng, ?float $accuracy, BranchContext $context, AttendanceService $service): void
    {
        $branch = $this->activeBranch($context);
        $service->checkIn(auth()->user(), $branch, $shift, $lat, $lng, $accuracy);
        $this->toast('Absen masuk shift '.$service->label($shift).' tercatat.');
    }

    public function checkOut(int $shift, float $lat, float $lng, ?float $accuracy, BranchContext $context, AttendanceService $service): void
    {
        $branch = $this->activeBranch($context);
        $row = $service->checkOut(auth()->user(), $branch, $shift, $lat, $lng, $accuracy);
        $over = (int) ($row->overtime_minutes ?? 0);
        $this->toast('Absen pulang shift '.$service->label($shift).' tercatat'.($over > 0 ? ', lembur '.($over / 60).' jam.' : '.'));
    }

    private function activeBranch(BranchContext $context): Branch
    {
        $id = $this->requireBranch($context, 'absen');

        return Branch::where('clinic_id', $this->clinicId())->findOrFail($id);
    }

    // ---- Koreksi Owner -------------------------------------------------------------------

    /** "doctor-12" / "employee-3" → model kehadiran milik klinik ini. */
    private function findRow(string $key): Model
    {
        [$type, $id] = array_pad(explode('-', $key, 2), 2, null);

        return match ($type) {
            'doctor' => DoctorShiftAttendance::whereHas('doctor', fn ($q) => $q->where('clinic_id', $this->clinicId()))->findOrFail((int) $id),
            'employee' => EmployeeShiftAttendance::whereHas('employee', fn ($q) => $q->where('clinic_id', $this->clinicId()))->findOrFail((int) $id),
            default => abort(404),
        };
    }

    public function editRow(string $key, BranchContext $context): void
    {
        abort_unless($context->is(Role::Owner), 403, 'Hanya Owner yang mengoreksi absensi.');
        $row = $this->findRow($key);
        $this->editingKey = $key;
        $this->edit = [
            'check_in' => $row->check_in_at?->format('H:i') ?? '',
            'check_out' => $row->check_out_at?->format('H:i') ?? '',
            'overtime_hours' => $row instanceof EmployeeShiftAttendance ? (string) round($row->overtime_minutes / 60, 2) : '',
            'note' => (string) $row->note,
        ];
        $this->resetValidation();
        $this->form = 'edit';
    }

    public function saveRow(BranchContext $context): void
    {
        abort_unless($context->is(Role::Owner) && $this->editingKey, 403);
        $this->edit['overtime_hours'] = str_replace(',', '.', trim((string) ($this->edit['overtime_hours'] ?? '')));
        $v = $this->validate([
            'edit.check_in' => ['nullable', 'date_format:H:i'],
            'edit.check_out' => ['nullable', 'date_format:H:i', 'after:edit.check_in'],
            'edit.overtime_hours' => ['nullable', 'numeric', 'between:0,24'],
            'edit.note' => ['nullable', 'string', 'max:255'],
        ], attributes: ['edit.check_in' => 'jam masuk', 'edit.check_out' => 'jam pulang', 'edit.overtime_hours' => 'jam lembur', 'edit.note' => 'catatan'])['edit'];

        $row = $this->findRow($this->editingKey);
        $before = $row->only(['check_in_at', 'check_out_at', 'overtime_minutes', 'note']);
        $date = $row->date->copy();
        $row->fill([
            'check_in_at' => $v['check_in'] ? $date->copy()->setTimeFromTimeString($v['check_in']) : null,
            'check_out_at' => $v['check_out'] ? $date->copy()->setTimeFromTimeString($v['check_out']) : null,
            'note' => $v['note'] ?: null,
        ]);
        if ($row instanceof EmployeeShiftAttendance) {
            $row->overtime_minutes = (int) round(((float) ($v['overtime_hours'] ?: 0)) * 60);
        }
        $row->save();

        AuditLog::record('absensi.dikoreksi', $row, ['before' => $before, 'after' => $row->only(['check_in_at', 'check_out_at', 'overtime_minutes', 'note'])]);
        $this->closeForm();
        $this->toast('Absensi dikoreksi.');
    }

    public function deleteRow(BranchContext $context): void
    {
        abort_unless($context->is(Role::Owner) && $this->editingKey, 403);
        $row = $this->findRow($this->editingKey);
        AuditLog::record('absensi.dihapus', $row, $row->only(['date', 'shift', 'check_in_at', 'check_out_at', 'source']));
        $row->delete();
        $this->closeForm();
        $this->toast('Absensi dihapus.');
    }

    public function newManual(BranchContext $context): void
    {
        abort_unless($context->is(Role::Owner), 403, 'Hanya Owner yang menambah absensi manual.');
        $this->manual = ['person' => '', 'date' => now()->toDateString(), 'shift' => '1', 'branch_id' => (string) ($context->id() ?? $context->options()->first()?->id),
            'check_in' => '', 'check_out' => '', 'overtime_hours' => '', 'note' => ''];
        $this->resetValidation();
        $this->form = 'manual';
    }

    public function saveManual(BranchContext $context): void
    {
        abort_unless($context->is(Role::Owner) && $this->form === 'manual', 403);
        $this->manual['overtime_hours'] = str_replace(',', '.', trim((string) ($this->manual['overtime_hours'] ?? '')));
        $v = $this->validate([
            'manual.person' => ['required', 'regex:/^(doctor|employee)-\d+$/'],
            'manual.date' => ['required', 'date', 'before_or_equal:today'],
            'manual.shift' => ['required', Rule::in(['1', '2'])],
            'manual.branch_id' => ['required', Rule::exists('branches', 'id')->where('clinic_id', $this->clinicId())],
            'manual.check_in' => ['nullable', 'date_format:H:i'],
            'manual.check_out' => ['nullable', 'date_format:H:i', 'after:manual.check_in'],
            'manual.overtime_hours' => ['nullable', 'numeric', 'between:0,24'],
            'manual.note' => ['nullable', 'string', 'max:255'],
        ], attributes: ['manual.person' => 'nama', 'manual.date' => 'tanggal', 'manual.branch_id' => 'cabang', 'manual.check_out' => 'jam pulang'])['manual'];

        [$type, $id] = explode('-', $v['person'], 2);
        $date = Carbon::parse($v['date'])->startOfDay();
        $values = [
            'branch_id' => (int) $v['branch_id'], 'date' => $date->toDateString(), 'shift' => (int) $v['shift'],
            'check_in_at' => $v['check_in'] ? $date->copy()->setTimeFromTimeString($v['check_in']) : null,
            'check_out_at' => $v['check_out'] ? $date->copy()->setTimeFromTimeString($v['check_out']) : null,
            'source' => DoctorShiftAttendance::SOURCE_MANUAL, 'note' => $v['note'] ?: null, 'recorded_by' => auth()->id(),
        ];

        if ($type === 'doctor') {
            $doctor = Doctor::where('clinic_id', $this->clinicId())->findOrFail((int) $id);
            $exists = DoctorShiftAttendance::where('doctor_id', $doctor->id)->whereDate('date', $date)->where('shift', $values['shift'])->exists();
            $row = $exists ? null : DoctorShiftAttendance::create($values + ['doctor_id' => $doctor->id]);
        } else {
            $employee = Employee::where('clinic_id', $this->clinicId())->findOrFail((int) $id);
            $exists = EmployeeShiftAttendance::where('employee_id', $employee->id)->whereDate('date', $date)->where('shift', $values['shift'])->exists();
            $row = $exists ? null : EmployeeShiftAttendance::create($values + [
                'employee_id' => $employee->id, 'overtime_minutes' => (int) round(((float) ($v['overtime_hours'] ?: 0)) * 60),
            ]);
        }

        if (! $row) {
            throw ValidationException::withMessages(['manual.shift' => 'Sudah ada absensi untuk orang, tanggal, dan shift ini. Koreksi baris yang ada.']);
        }

        AuditLog::record('absensi.manual', $row, $values);
        $this->closeForm();
        $this->toast('Absensi manual ditambahkan.');
    }

    public function closeForm(): void
    {
        $this->form = null;
        $this->editingKey = null;
        $this->resetValidation();
    }

    public function render(BranchContext $context, AttendanceService $service)
    {
        $today = now();
        $person = $service->personFor(auth()->user());
        $branch = $context->id() ? Branch::where('clinic_id', $this->clinicId())->find($context->id()) : null;
        $windows = $branch ? $service->windows($branch, $today) : [];
        $mine = $person ? collect([1, 2])->mapWithKeys(fn ($s) => [$s => $service->record($person, $today, $s)])->all() : [];

        $isManager = $context->is(Role::Owner, Role::Admin);
        $rows = collect();
        $start = $this->period();
        $end = $start->copy()->endOfMonth();
        if ($isManager) {
            $branchIds = $context->id() ? [$context->id()] : $context->options()->pluck('id')->all();
            $range = [$start->toDateString(), $end->toDateString()];
            $doctorRows = DoctorShiftAttendance::with('doctor:id,name', 'branch')
                ->whereHas('doctor', fn ($q) => $q->where('clinic_id', $this->clinicId()))
                ->whereIn('branch_id', $branchIds)->whereBetween('date', $range)->get()
                ->map(fn ($a) => ['key' => 'doctor-'.$a->id, 'name' => $a->doctor->name, 'role' => 'Dokter', 'row' => $a]);
            $employeeRows = EmployeeShiftAttendance::with('employee:id,name,position', 'branch')
                ->whereHas('employee', fn ($q) => $q->where('clinic_id', $this->clinicId()))
                ->whereIn('branch_id', $branchIds)->whereBetween('date', $range)->get()
                ->map(fn ($a) => ['key' => 'employee-'.$a->id, 'name' => $a->employee->name, 'role' => $a->employee->position, 'row' => $a]);
            $rows = $doctorRows->concat($employeeRows)
                ->sortBy([fn ($a, $b) => $b['row']->date <=> $a['row']->date, fn ($a, $b) => $a['name'] <=> $b['name'], fn ($a, $b) => $a['row']->shift <=> $b['row']->shift])
                ->values();
        }

        return $this->page('absensi', 'livewire.modules.absensi', [
            'person' => $person,
            'branch' => $branch,
            'windows' => $windows,
            'mine' => $mine,
            'today' => $today,
            'isManager' => $isManager,
            'isOwner' => $context->is(Role::Owner),
            'rows' => $rows,
            'start' => $start,
            'shiftLabels' => DoctorShiftAttendance::SHIFT_LABELS,
            'people' => $context->is(Role::Owner) ? $this->people() : collect(),
            'branches' => $context->options(),
            'radiusInfo' => $branch?->hasLocation() ? $branch->attendance_radius_m : null,
        ]);
    }

    /** Pilihan orang untuk absensi manual: dokter aktif + asisten aktif. */
    private function people()
    {
        $doctors = Doctor::where('clinic_id', $this->clinicId())->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($d) => ['key' => 'doctor-'.$d->id, 'label' => $d->name.' (dokter)']);
        $employees = Employee::where('clinic_id', $this->clinicId())->where('is_active', true)->where('position', 'Asisten dokter')->orderBy('name')->get(['id', 'name'])
            ->map(fn ($e) => ['key' => 'employee-'.$e->id, 'label' => $e->name.' (asisten)']);

        return $doctors->concat($employees);
    }
}
