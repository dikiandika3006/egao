<?php

namespace App\Livewire\Modules;

use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollAdjustment;
use App\Models\PayrollHour;
use App\Support\BranchContext;
use App\Support\Payroll;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Gaji & bagi hasil (FR-GJI-01 … 08) — khusus Owner.
 * Tab Dokter: tindakan kotor lintas cabang + bagi hasil. Tab Karyawan: gaji pokok admin/perawat (FR-GJI-05).
 * Bonus/potongan per bulan untuk keduanya (FR-GJI-04). Slip PDF lewat PayslipController.
 */
class Gaji extends Component
{
    use ModulePage;

    public const MENU = 'gaji';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['shiftMonth', 'setTab', 'toggle'];

    #[Url(as: 'bulan', except: '')]
    public string $month = '';

    #[Url(except: 'dokter')]
    public string $tab = 'dokter';

    /** Baris yang dibuka, mis. "doctor-3" / "employee-2". */
    public ?string $expanded = null;

    /** Form bonus/potongan untuk baris yang dibuka. */
    public array $adjustment = ['type' => 'bonus', 'amount' => '', 'note' => ''];

    /** Form jam hadir & lembur untuk baris yang dibuka. */
    public array $hours = ['work_hours' => '', 'overtime_hours' => ''];

    /** Form karyawan. */
    public bool $showEmployeeForm = false;

    public ?int $editingEmployeeId = null;

    public array $employee = [];

    public function mount(): void
    {
        $this->month = $this->period()->format('Y-m');
        $this->resetEmployee();
    }

    private function period(): Carbon
    {
        return rescue(fn () => Carbon::createFromFormat('Y-m', $this->month ?: now()->format('Y-m'))->startOfMonth(), now()->startOfMonth(), false);
    }

    public function shiftMonth(int $n): void
    {
        $this->month = $this->period()->addMonths($n)->format('Y-m');
        $this->expanded = null;
    }

    public function setTab(string $tab): void
    {
        abort_unless(in_array($tab, ['dokter', 'karyawan'], true), 404);
        $this->tab = $tab;
        $this->expanded = null;
    }

    public function toggle(string $key): void
    {
        $this->expanded = $this->expanded === $key ? null : $key;
        $this->adjustment = ['type' => 'bonus', 'amount' => '', 'note' => ''];
        $this->hours = ['work_hours' => '', 'overtime_hours' => ''];
        $this->resetErrorBag();

        if ($this->expanded && str_contains($this->expanded, '-')) {
            [$payeeType, $payeeId] = explode('-', $this->expanded, 2);
            $h = $this->hourRow($payeeType, (int) $payeeId)->first(); // dibatasi clinic_id; validasi penuh saat simpan
            $fmt = fn ($v) => $v === null || (float) $v == 0 ? '' : rtrim(rtrim((string) $v, '0'), '.');
            $this->hours = ['work_hours' => $fmt($h?->work_hours), 'overtime_hours' => $fmt($h?->overtime_hours)];
        }
    }

    // ── Jam hadir & lembur per bulan (UD per jam, lembur per jam) ───

    private function hourRow(string $payeeType, int $payeeId)
    {
        return PayrollHour::where('clinic_id', $this->clinicId())
            ->where('payee_type', $payeeType)
            ->where('payee_id', $payeeId)
            ->whereDate('period', $this->period()->toDateString());
    }

    public function saveHours(): void
    {
        [$payeeType, $payeeId] = $this->expandedPayee();
        $this->hours = array_map(fn ($v) => str_replace(',', '.', trim((string) $v)), $this->hours);

        $data = $this->validate([
            'hours.work_hours' => ['nullable', 'numeric', 'between:0,744'],
            'hours.overtime_hours' => ['nullable', 'numeric', 'between:0,744'],
        ], attributes: ['hours.work_hours' => 'jam hadir', 'hours.overtime_hours' => 'jam lembur'])['hours'];

        $values = ['work_hours' => (float) ($data['work_hours'] ?: 0), 'overtime_hours' => (float) ($data['overtime_hours'] ?: 0)];
        $row = $this->hourRow($payeeType, $payeeId)->first();
        $before = $row?->only(['work_hours', 'overtime_hours']);

        $row = $row
            ? tap($row)->update($values)
            : PayrollHour::create(['clinic_id' => $this->clinicId(), 'period' => $this->period()->toDateString(),
                'payee_type' => $payeeType, 'payee_id' => $payeeId] + $values);

        AuditLog::record('payroll.hours.saved', $row, ['payee_type' => $payeeType, 'payee_id' => $payeeId, 'before' => $before, 'after' => $values]);
        $this->toast('Jam kerja tersimpan.');
    }

    // ── Bonus / potongan (FR-GJI-04) ────────────────────────────────

    public function addAdjustment(): void
    {
        [$payeeType, $payeeId] = $this->expandedPayee();

        $data = $this->validate([
            'adjustment.type' => ['required', Rule::in(array_keys(PayrollAdjustment::TYPES))],
            'adjustment.amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'adjustment.note' => ['nullable', 'string', 'max:255'],
        ], attributes: ['adjustment.amount' => 'nominal', 'adjustment.note' => 'keterangan'])['adjustment'];

        $adj = PayrollAdjustment::create([
            'clinic_id' => $this->clinicId(),
            'period' => $this->period()->toDateString(),
            'payee_type' => $payeeType,
            'payee_id' => $payeeId,
            'type' => $data['type'],
            'amount' => (int) $data['amount'],
            'note' => $data['note'] ?: null,
            'created_by' => auth()->id(),
        ]);

        AuditLog::record('payroll.adjustment.created', $adj, $adj->only(['payee_type', 'payee_id', 'type', 'amount']));
        $this->adjustment = ['type' => 'bonus', 'amount' => '', 'note' => ''];
        $this->toast(PayrollAdjustment::TYPES[$data['type']].' ditambahkan.');
    }

    public function removeAdjustment(int $id): void
    {
        $adj = PayrollAdjustment::where('clinic_id', $this->clinicId())->findOrFail($id);
        AuditLog::record('payroll.adjustment.deleted', $adj, $adj->only(['payee_type', 'payee_id', 'type', 'amount']));
        $adj->delete();
    }

    /** @return array{0:string,1:int} */
    private function expandedPayee(): array
    {
        abort_unless($this->expanded && str_contains($this->expanded, '-'), 422);
        [$type, $id] = explode('-', $this->expanded, 2);
        $id = (int) $id;

        $exists = match ($type) {
            PayrollAdjustment::PAYEE_DOCTOR => Doctor::where('clinic_id', $this->clinicId())->whereKey($id)->exists(),
            PayrollAdjustment::PAYEE_EMPLOYEE => Employee::where('clinic_id', $this->clinicId())->whereKey($id)->exists(),
            default => false,
        };
        abort_unless($exists, 404);

        return [$type, $id];
    }

    // ── Karyawan (FR-GJI-05) ────────────────────────────────────────

    private function resetEmployee(): void
    {
        $this->employee = ['name' => '', 'position' => 'Admin', 'primary_branch_id' => '', 'base_salary' => '', 'morning_shift_rate' => '', 'evening_shift_rate' => '', 'joined_at' => '', 'is_active' => true];
        $this->editingEmployeeId = null;
    }

    public function newEmployee(): void
    {
        $this->resetEmployee();
        $this->resetErrorBag();
        $this->showEmployeeForm = true;
    }

    public function editEmployee(int $id): void
    {
        $e = Employee::where('clinic_id', $this->clinicId())->findOrFail($id);
        $this->editingEmployeeId = $e->id;
        $this->employee = [
            'name' => $e->name, 'position' => $e->position, 'primary_branch_id' => (string) $e->primary_branch_id,
            'base_salary' => (string) $e->base_salary, 'joined_at' => $e->joined_at?->toDateString() ?? '', 'is_active' => $e->is_active,
            'morning_shift_rate' => (string) $e->morning_shift_rate, 'evening_shift_rate' => (string) $e->evening_shift_rate,
        ];
        $this->resetErrorBag();
        $this->showEmployeeForm = true;
    }

    public function saveEmployee(): void
    {
        $data = $this->validate([
            'employee.name' => ['required', 'string', 'max:255'],
            'employee.position' => ['required', 'string', 'max:50'],
            'employee.primary_branch_id' => ['required', Rule::exists('branches', 'id')->where('clinic_id', $this->clinicId())],
            'employee.base_salary' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'employee.joined_at' => ['nullable', 'date'],
            'employee.morning_shift_rate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'employee.evening_shift_rate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'employee.is_active' => ['boolean'],
        ], attributes: [
            'employee.name' => 'nama', 'employee.position' => 'jabatan', 'employee.primary_branch_id' => 'cabang',
            'employee.base_salary' => 'gaji pokok', 'employee.joined_at' => 'tanggal masuk',
            'employee.morning_shift_rate' => 'upah shift pagi', 'employee.evening_shift_rate' => 'upah shift sore',
        ])['employee'];

        $e = $this->editingEmployeeId
            ? Employee::where('clinic_id', $this->clinicId())->findOrFail($this->editingEmployeeId)
            : new Employee(['clinic_id' => $this->clinicId()]);

        $before = $e->only(['base_salary', 'morning_shift_rate', 'evening_shift_rate', 'position', 'is_active']);
        $e->fill([
            'name' => $data['name'], 'position' => $data['position'], 'primary_branch_id' => (int) $data['primary_branch_id'],
            'base_salary' => (int) $data['base_salary'], 'joined_at' => $data['joined_at'] ?: null, 'is_active' => (bool) $data['is_active'],
            // Kosong = ikut upah shift global (Pengaturan → Gaji & bagi hasil).
            'morning_shift_rate' => ($data['morning_shift_rate'] ?? '') === '' ? null : (int) $data['morning_shift_rate'],
            'evening_shift_rate' => ($data['evening_shift_rate'] ?? '') === '' ? null : (int) $data['evening_shift_rate'],
        ])->save();

        AuditLog::record($this->editingEmployeeId ? 'employee.updated' : 'employee.created', $e, ['before' => $before, 'after' => $e->only(['base_salary', 'morning_shift_rate', 'evening_shift_rate', 'position', 'is_active'])]);

        $this->showEmployeeForm = false;
        $this->resetEmployee();
        $this->toast('Data karyawan tersimpan.');
    }

    // ── Catat ke pengeluaran (FR-GJI-06, FR-PNG-03) ─────────────────

    /**
     * Bagi hasil dibebankan ke cabang tempat tindakan; gaji pokok, bonus, dan potongan ke cabang utama.
     * Idempoten: posting ulang mengganti catatan bulan yang sama.
     */
    public function post(): void
    {
        $start = $this->period();
        abort_if($start->isAfter(now()), 422);

        $category = ExpenseCategory::firstOrCreate(
            ['clinic_id' => $this->clinicId(), 'is_system' => true],
            ['name' => 'Gaji dan bagi hasil'],
        );

        $branchIds = Branch::where('clinic_id', $this->clinicId())->pluck('id');
        $fallbackBranch = $branchIds->first();
        $date = $start->copy()->endOfMonth()->min(today())->toDateString();
        $tag = '[GJI '.$start->format('Y-m').']';
        $doctors = Payroll::forMonth($this->clinicId(), $start);
        $employees = Payroll::employeesForMonth($this->clinicId(), $start);

        DB::transaction(function () use ($doctors, $employees, $category, $branchIds, $fallbackBranch, $date, $tag) {
            Expense::where('expense_category_id', $category->id)
                ->whereIn('branch_id', $branchIds)
                ->where('description', 'like', $tag.'%')
                ->delete();

            $write = function (array $perBranch, string $name) use ($category, $date, $tag) {
                foreach ($perBranch as $branchId => $amount) {
                    if ($amount > 0) {
                        Expense::create([
                            'branch_id' => $branchId, 'expense_category_id' => $category->id, 'created_by' => auth()->id(),
                            'date' => $date, 'amount' => $amount, 'description' => "{$tag} {$name}",
                        ]);
                    }
                }
            };

            // Sisa (gaji pokok, UD, lembur, bonus − potongan, dan selisih negatif per cabang) dibebankan ke cabang utama,
            // sehingga jumlah yang dicatat selalu sama dengan total gaji.
            $split = function (array $perBranch, int $primary, int $total): array {
                $perBranch = array_map(fn ($v) => max(0, (int) $v), $perBranch);
                unset($perBranch[$primary]);
                $others = array_sum($perBranch);
                if ($others > $total) {
                    $perBranch = [];
                    $others = 0;
                }
                $perBranch[$primary] = $total - $others;

                return $perBranch;
            };

            foreach ($doctors as $row) {
                $primary = $row['doctor']->primary_branch_id ?? $fallbackBranch;
                $shares = collect($row['branches'])->map(fn ($b) => $b['share'])->all();
                $write($split($shares, $primary, $row['total']), $row['doctor']->name);
            }

            foreach ($employees as $row) {
                $primary = $row['employee']->primary_branch_id ?? $fallbackBranch;
                $write($split($row['assistant_branches'], $primary, $row['total']), $row['employee']->name.' ('.$row['employee']->position.')');
            }
        });

        AuditLog::record('payroll.posted', null, [
            'month' => $start->format('Y-m'),
            'total' => $doctors->sum('total') + $employees->sum('total'),
        ]);
        $this->toast('Gaji '.$start->translatedFormat('F Y').' dicatat ke pengeluaran.');
    }

    public function render(BranchContext $context)
    {
        $start = $this->period();
        $rows = Payroll::forMonth($this->clinicId(), $start);
        $employees = Payroll::employeesForMonth($this->clinicId(), $start);

        $posted = Expense::query()
            ->whereHas('category', fn ($q) => $q->where('clinic_id', $this->clinicId())->where('is_system', true))
            ->where('description', 'like', '[GJI '.$start->format('Y-m').']%')
            ->sum('amount');

        return $this->page('gaji', 'livewire.modules.gaji', [
            'start' => $start,
            'rows' => $rows,
            'employees' => $employees,
            'branches' => Branch::where('clinic_id', $this->clinicId())->orderBy('id')->get()->keyBy('id'),
            'totals' => [
                'gross' => $rows->sum('gross'),
                'share' => $rows->sum('share'),
                'base' => $rows->sum('base') + $rows->sum('ud'),
                'bonus' => $rows->sum('bonus') - $rows->sum('deduction'),
                'total' => $rows->sum('total'),
            ],
            'employeeTotals' => [
                'base' => $employees->sum('base') + $employees->sum('ud'),
                'extra' => $employees->sum('assistant_share') + $employees->sum('overtime'),
                'bonus' => $employees->sum('bonus') - $employees->sum('deduction'),
                'total' => $employees->sum('total'),
            ],
            'ytd' => Payroll::yearToDate($this->clinicId(), $start),
            'rates' => [
                'doctor_hourly' => Settings::int('doctor_hourly_rate'),
                'assistant_hourly' => Settings::int('assistant_hourly_rate'),
                'assistant_overtime' => Settings::int('assistant_overtime_rate'),
                'admin_overtime' => Settings::int('admin_overtime_rate'),
            ],
            'adjustmentTypes' => PayrollAdjustment::TYPES,
            'grandTotal' => $rows->sum('total') + $employees->sum('total'),
            'posted' => (int) $posted,
            'global' => Settings::globalShare(),
            'positions' => Employee::POSITIONS,
        ]);
    }
}
