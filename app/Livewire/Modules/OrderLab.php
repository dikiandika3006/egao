<?php

namespace App\Livewire\Modules;

use App\Livewire\Concerns\ModulePage;
use App\Livewire\Concerns\PicksBranch;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Order lab gigi (permintaan klien poin 7: "laporan pembayaran lab gigi").
 * Klinik mengirim pekerjaan ke lab luar per pasien; lab menagih klinik. Di sini dicatat statusnya
 * dan kapan klinik membayar lab — pembayaran otomatis menjadi pengeluaran kategori "Lab gigi".
 */
class OrderLab extends Component
{
    use ModulePage, PicksBranch;

    public const MENU = 'lab';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = [];

    #[Url(as: 'filter', except: 'aktif')]
    public string $filter = 'aktif'; // aktif | belum_bayar | semua

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public string $patientSearch = '';

    public function mount(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->form = [
            'patient_id' => null, 'doctor_id' => '', 'lab_name' => '', 'work' => '', 'tooth' => '',
            'sent_at' => today()->toDateString(), 'due_at' => today()->addWeek()->toDateString(), 'cost' => null, 'note' => '',
        ];
        $this->editingId = null;
        $this->patientSearch = '';
    }

    private function query()
    {
        $context = app(BranchContext::class);

        return LabOrder::where('clinic_id', $this->clinicId())
            ->when($context->id(), fn ($q) => $q->where('branch_id', $context->id()), fn ($q) => $q->whereIn('branch_id', $context->options()->pluck('id')));
    }

    public function create(BranchContext $context): void
    {
        $this->resetForm();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $o = $this->query()->findOrFail($id);
        $this->editingId = $o->id;
        $this->form = [
            'patient_id' => $o->patient_id, 'doctor_id' => (string) $o->doctor_id, 'lab_name' => $o->lab_name, 'work' => $o->work,
            'tooth' => (string) $o->tooth, 'sent_at' => $o->sent_at->toDateString(), 'due_at' => $o->due_at?->toDateString() ?? '',
            'cost' => (int) $o->cost, 'note' => (string) $o->note,
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
        $data = $this->validate([
            'form.patient_id' => ['required', Rule::exists('patients', 'id')->where('clinic_id', $this->clinicId())],
            'form.doctor_id' => ['nullable', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId())],
            'form.lab_name' => ['required', 'string', 'max:255'],
            'form.work' => ['required', 'string', 'max:255'],
            'form.tooth' => ['nullable', 'string', 'max:30'],
            'form.sent_at' => ['required', 'date'],
            'form.due_at' => ['nullable', 'date', 'after_or_equal:form.sent_at'],
            'form.cost' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'form.note' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'form.patient_id' => 'pasien', 'form.lab_name' => 'nama lab', 'form.work' => 'pekerjaan', 'form.sent_at' => 'tanggal kirim',
            'form.due_at' => 'perkiraan jadi', 'form.cost' => 'biaya lab',
        ])['form'];

        $order = $this->editingId
            ? $this->query()->findOrFail($this->editingId)
            : new LabOrder(['clinic_id' => $this->clinicId(), 'branch_id' => $this->targetBranch($context), 'status' => 'dikirim', 'created_by' => auth()->id()]);

        abort_if($order->paid_at && (int) $data['cost'] !== (int) $order->cost, 422, 'Biaya tidak dapat diubah setelah dibayar ke lab.');

        $order->fill([
            'patient_id' => (int) $data['patient_id'], 'doctor_id' => $data['doctor_id'] ?: null, 'lab_name' => trim($data['lab_name']),
            'work' => trim($data['work']), 'tooth' => $data['tooth'] ?: null, 'sent_at' => $data['sent_at'], 'due_at' => $data['due_at'] ?: null,
            'cost' => (int) $data['cost'], 'note' => $data['note'] ?: null,
        ])->save();

        AuditLog::record($this->editingId ? 'lab.updated' : 'lab.created', $order, $order->only(['lab_name', 'work', 'cost']));
        $this->showForm = false;
        $this->toast('Order lab tersimpan.');
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, LabOrder::STATUSES), 422);
        $o = $this->query()->findOrFail($id);
        $o->update([
            'status' => $status,
            'received_at' => in_array($status, ['diterima', 'dipasang'], true) ? ($o->received_at ?? today()) : $o->received_at,
        ]);
        AuditLog::record('lab.status', $o, ['status' => $status]);
    }

    /** Klinik membayar tagihan lab → pengeluaran "Lab gigi" di cabang order. */
    public function markPaid(int $id): void
    {
        $o = $this->query()->with('patient')->findOrFail($id);
        abort_if($o->paid_at !== null || $o->status === 'batal', 422);

        DB::transaction(function () use ($o) {
            $category = ExpenseCategory::firstOrCreate(['clinic_id' => $this->clinicId(), 'name' => 'Lab gigi'], ['is_system' => false]);
            $expense = Expense::create([
                'branch_id' => $o->branch_id, 'expense_category_id' => $category->id, 'created_by' => auth()->id(),
                'date' => today()->toDateString(), 'amount' => (int) $o->cost,
                'description' => "[LAB] {$o->lab_name}: {$o->work}".($o->tooth ? " gigi {$o->tooth}" : '')." — {$o->patient->name}",
            ]);
            $o->update(['paid_at' => today(), 'expense_id' => $expense->id]);
        });

        AuditLog::record('lab.paid', $o, ['cost' => $o->cost]);
        $this->toast('Pembayaran ke lab dicatat di pengeluaran.');
    }

    /** Batalkan pembayaran yang salah catat: pengeluaran ikut dihapus. */
    public function unmarkPaid(int $id): void
    {
        $o = $this->query()->findOrFail($id);
        abort_unless($o->paid_at !== null, 422);

        DB::transaction(function () use ($o) {
            if ($o->expense_id) {
                Expense::whereKey($o->expense_id)->delete();
            }
            $o->update(['paid_at' => null, 'expense_id' => null]);
        });
        AuditLog::record('lab.unpaid', $o, ['cost' => $o->cost]);
    }

    public function render(BranchContext $context)
    {
        $orders = $this->query()
            ->when($this->filter === 'aktif', fn ($q) => $q->whereIn('status', ['dikirim', 'diterima']))
            ->when($this->filter === 'belum_bayar', fn ($q) => $q->whereNull('paid_at')->where('status', '!=', 'batal'))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('lab_name', 'like', '%'.trim($this->search).'%')
                ->orWhere('work', 'like', '%'.trim($this->search).'%')
                ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', '%'.trim($this->search).'%'))))
            ->with(['patient', 'doctor', 'branch'])
            ->latest('sent_at')->latest('id')
            ->limit(200)->get();

        $unpaid = $this->query()->whereNull('paid_at')->where('status', '!=', 'batal');

        return $this->page('lab', 'livewire.modules.order-lab', [
            'context' => $context,
            'orders' => $orders,
            'stats' => [
                'active' => $this->query()->whereIn('status', ['dikirim', 'diterima'])->count(),
                'overdue' => $this->query()->where('status', 'dikirim')->whereDate('due_at', '<', today())->count(),
                'unpaid' => (int) (clone $unpaid)->sum('cost'),
                'paidMonth' => (int) $this->query()->whereBetween('paid_at', [now()->startOfMonth()->toDateString(), today()->toDateString()])->sum('cost'),
            ],
            'unpaidByLab' => (clone $unpaid)->selectRaw('lab_name, SUM(cost) as total, COUNT(*) as n')->groupBy('lab_name')->orderByDesc('total')->get(),
            'labNames' => LabOrder::where('clinic_id', $this->clinicId())->distinct()->orderBy('lab_name')->pluck('lab_name'),
            'doctors' => Doctor::where('clinic_id', $this->clinicId())->where('is_active', true)->orderBy('name')->get(),
            'selectedPatient' => ($this->form['patient_id'] ?? null) ? Patient::find($this->form['patient_id']) : null,
            'patientResults' => mb_strlen(trim($this->patientSearch)) >= 2
                ? Patient::where('clinic_id', $this->clinicId())->search($this->patientSearch)->limit(6)->get()
                : collect(),
        ]);
    }
}
