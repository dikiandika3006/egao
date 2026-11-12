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

    #[Url(as: 'bulan', except: '')]
    public string $month = '';

    #[Url(except: 'dokter')]
    public string $tab = 'dokter';

    /** Baris yang dibuka, mis. "doctor-3" / "employee-2". */
    public ?string $expanded = null;

    /** Form bonus/potongan untuk baris yang dibuka. */
    public array $adjustment = ['type' => 'bonus', 'amount' => '', 'note' => ''];

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
        $this->resetErrorBag();
    }

    // ── Bonus / potongan (FR-GJI-04) ────────────────────────────────

    public function addAdjustment(): void
    {
        [$payeeType, $payeeId] = $this->expandedPayee();

        $data = $this->validate([
            'adjustment.type' => ['required', Rule::in([PayrollAdjustment::TYPE_BONUS, PayrollAdjustment::TYPE_DEDUCTION])],
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
        $this->toast(($data['type'] === 'bonus' ? 'Bonus' : 'Potongan').' ditambahkan.');
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
        $this->employee = ['name' => '', 'position' => 'Admin', 'primary_branch_id' => '', 'base_salary' => '', 'joined_at' => '', 'is_active' => true];
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
            'employee.is_active' => ['boolean'],
        ], attributes: [
            'employee.name' => 'nama', 'employee.position' => 'jabatan', 'employee.primary_branch_id' => 'cabang',
            'employee.base_salary' => 'gaji pokok', 'employee.joined_at' => 'tanggal masuk',
        ])['employee'];

        $e = $this->editingEmployeeId
            ? Employee::where('clinic_id', $this->clinicId())->findOrFail($this->editingEmployeeId)
            : new Employee(['clinic_id' => $this->clinicId()]);

        $before = $e->only(['base_salary', 'position', 'is_active']);
        $e->fill([
            'name' => $data['name'], 'position' => $data['position'], 'primary_branch_id' => (int) $data['primary_branch_id'],
            'base_salary' => (int) $data['base_salary'], 'joined_at' => $data['joined_at'] ?: null, 'is_active' => (bool) $data['is_active'],
        ])->save();

        AuditLog::record($this->editingEmployeeId ? 'employee.updated' : 'employee.created', $e, ['before' => $before, 'after' => $e->only(['base_salary', 'position', 'is_active'])]);

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

            foreach ($doctors as $row) {
                $perBranch = collect($row['branches'])->map(fn ($b) => $b['share'])->all();
                $primary = $row['doctor']->primary_branch_id ?? $fallbackBranch;
                $perBranch[$primary] = ($perBranch[$primary] ?? 0) + $row['base'] + $row['bonus'] - $row['deduction'];
                $write($perBranch, $row['doctor']->name);
            }

            foreach ($employees as $row) {
                $write([$row['employee']->primary_branch_id ?? $fallbackBranch => $row['total']], $row['employee']->name.' ('.$row['employee']->position.')');
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
                'base' => $rows->sum('base'),
                'bonus' => $rows->sum('bonus') - $rows->sum('deduction'),
                'total' => $rows->sum('total'),
            ],
            'employeeTotals' => [
                'base' => $employees->sum('base'),
                'bonus' => $employees->sum('bonus') - $employees->sum('deduction'),
                'total' => $employees->sum('total'),
            ],
            'grandTotal' => $rows->sum('total') + $employees->sum('total'),
            'posted' => (int) $posted,
            'global' => Settings::globalShare(),
            'positions' => Employee::POSITIONS,
        ]);
    }
}
