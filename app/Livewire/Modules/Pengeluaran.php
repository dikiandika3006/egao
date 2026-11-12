<?php

namespace App\Livewire\Modules;

use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Pengeluaran (FR-PNG-01 … 05): biaya operasional per cabang. Kategori sistem (gaji & bagi hasil) diisi otomatis.
 */
class Pengeluaran extends Component
{
    use ModulePage, WithFileUploads;

    #[Url(as: 'bulan', except: '')]
    public string $month = '';

    #[Url(as: 'kategori', except: null)]
    public ?int $category = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $expense = [];

    public $proof = null;

    public function mount(): void
    {
        $this->month = $this->period()->format('Y-m');
    }

    private function period(): Carbon
    {
        return rescue(fn () => Carbon::createFromFormat('Y-m', $this->month ?: now()->format('Y-m'))->startOfMonth(), now()->startOfMonth(), false);
    }

    public function shiftMonth(int $n): void
    {
        $this->month = $this->period()->addMonths($n)->format('Y-m');
    }

    public function create(BranchContext $context): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->proof = null;
        $this->expense = [
            'branch_id' => $context->id() ?? $context->options()->first()?->id,
            'expense_category_id' => null,
            'date' => today()->toDateString(),
            'amount' => null,
            'description' => '',
        ];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $e = $this->query()->findOrFail($id);
        abort_if($e->category->is_system, 422, 'Pengeluaran otomatis diubah dari menu Gaji & bagi hasil.');

        $this->resetValidation();
        $this->editingId = $e->id;
        $this->proof = null;
        $this->expense = [
            'branch_id' => $e->branch_id, 'expense_category_id' => $e->expense_category_id,
            'date' => $e->date->toDateString(), 'amount' => $e->amount, 'description' => (string) $e->description,
        ];
        $this->showForm = true;
    }

    public function save(BranchContext $context): void
    {
        $data = $this->validate([
            'expense.branch_id' => ['required', Rule::in($context->id() ? [$context->id()] : $context->options()->pluck('id')->all())],
            'expense.expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where('clinic_id', $this->clinicId())->where('is_system', false)],
            'expense.date' => ['required', 'date', 'before_or_equal:today'],
            'expense.amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'expense.description' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
        ], attributes: [
            'expense.branch_id' => 'cabang', 'expense.expense_category_id' => 'kategori', 'expense.date' => 'tanggal',
            'expense.amount' => 'nominal', 'proof' => 'bukti',
        ])['expense'];

        $data['description'] = $data['description'] ?: null;
        $e = $this->editingId ? $this->query()->findOrFail($this->editingId) : new Expense(['created_by' => auth()->id()]);

        if ($this->proof) {
            if ($e->proof_path) {
                Storage::disk('local')->delete($e->proof_path);
            }
            $data['proof_path'] = $this->proof->store('bukti-pengeluaran', 'local');
        }

        $e->fill($data)->save();
        AuditLog::record($this->editingId ? 'expense.updated' : 'expense.created', $e, ['amount' => $e->amount]);

        $this->showForm = false;
        $this->month = $e->date->format('Y-m');
        $this->toast('Pengeluaran disimpan.');
    }

    public function delete(int $id): void
    {
        $e = $this->query()->findOrFail($id);
        abort_if($e->category->is_system, 422);
        AuditLog::record('expense.deleted', $e, $e->only(['branch_id', 'date', 'amount', 'description']));
        if ($e->proof_path) {
            Storage::disk('local')->delete($e->proof_path);
        }
        $e->delete();
        $this->showForm = false;
        $this->toast('Pengeluaran dihapus.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function downloadProof(int $id)
    {
        $e = $this->query()->findOrFail($id);
        abort_unless($e->proof_path && Storage::disk('local')->exists($e->proof_path), 404);

        return Storage::disk('local')->download($e->proof_path);
    }

    private function query()
    {
        $context = app(BranchContext::class);

        return Expense::query()
            ->with('category')
            ->when($context->id(), fn ($q) => $q->where('branch_id', $context->id()), fn ($q) => $q->whereIn('branch_id', $context->options()->pluck('id')));
    }

    public function render(BranchContext $context)
    {
        $start = $this->period();
        $rows = $this->query()
            ->with(['branch'])
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->when($this->category, fn ($q) => $q->where('expense_category_id', $this->category))
            ->orderByDesc('date')->orderByDesc('id')
            ->get();

        $categories = ExpenseCategory::where('clinic_id', $this->clinicId())->orderBy('is_system')->orderBy('name')->get();

        return $this->page('pengeluaran', 'livewire.modules.pengeluaran', [
            'context' => $context,
            'start' => $start,
            'rows' => $rows,
            'total' => $rows->sum('amount'),
            'byCategory' => $rows->groupBy('expense_category_id')->map(fn ($g) => ['name' => $g->first()->category->name, 'total' => $g->sum('amount')])->sortByDesc('total'),
            'categories' => $categories,
            'branches' => $context->id() ? $context->options()->where('id', $context->id()) : $context->options(),
        ]);
    }
}
