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
        $payslip = null;
        if ($id = Doctor::where('user_id', $user->id)->value('id')) {
            $payslip = ['type' => 'doctor', 'id' => $id];
        } elseif ($id = Employee::where('user_id', $user->id)->value('id')) {
            $payslip = ['type' => 'employee', 'id' => $id];
        }

        return view('livewire.branch-switcher', [
            'context' => $context,
            'branches' => $context->options(),
            'roles' => $user->roleList(),
            'payslip' => $payslip,
        ]);
    }
}
