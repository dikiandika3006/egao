<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\Doctor;
use App\Models\Employee;
use App\Support\BranchContext;
use Livewire\Component;

/**
 * Pemilih cabang & peran di topbar (FR-AUT-03). Mengganti pilihan memuat ulang halaman aktif.
 */
class BranchSwitcher extends Component
{
    public bool $compact = false;

    public function switchBranch(string $branch, BranchContext $context)
    {
        $context->set($context->role(), $branch);

        return $this->redirect(url()->previous(route('dashboard')), navigate: true);
    }

    public function switchRole(string $role, BranchContext $context)
    {
        $role = Role::from($role);
        $branch = $context->isAll() && $role !== Role::Owner
            ? $context->options()->first()->id
            : ($context->isAll() ? BranchContext::ALL : $context->id());

        $context->set($role, $branch);

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(BranchContext $context)
    {
        $user = auth()->user();

        // Slip gaji milik sendiri: akun yang terhubung ke data dokter atau karyawan.
        // Hanya bulan sejak orang itu mulai tercatat (tanggal bergabung / dibuat di aplikasi), tidak menampilkan bulan sebelumnya.
        $payslip = null;
        $person = Doctor::where('user_id', $user->id)->first(['id', 'joined_at', 'created_at']);
        $type = 'doctor';
        if (! $person) {
            $person = Employee::where('user_id', $user->id)->first(['id', 'joined_at', 'created_at']);
            $type = 'employee';
        }
        if ($person) {
            $start = collect([$person->joined_at, $person->created_at])->filter()
                ->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->startOfMonth())->max();
            $months = collect([now()->subMonthNoOverflow(), now()])
                ->filter(fn ($m) => ! $start || $m->copy()->startOfMonth()->gte($start))->values();
            $payslip = ['type' => $type, 'id' => $person->id, 'months' => $months];
        }

        return view('livewire.branch-switcher', [
            'context' => $context,
            'branches' => $context->options(),
            'roles' => $user->roleList(),
            'payslip' => $payslip,
        ]);
    }
}
