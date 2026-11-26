<?php

namespace App\Livewire\Auth;

use App\Enums\Role;
use App\Support\Access;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * FR-AUT-02: pilih peran & cabang aktif setelah masuk.
 * Dilewati otomatis untuk Owner (langsung "Semua cabang") dan bila pengguna hanya punya satu peran dan satu cabang.
 */
#[Layout('components.layouts.guest')]
#[Title('Pilih cabang')]
class SelectBranch extends Component
{
    public string $role = '';

    public string $branch = '';

    public function mount(BranchContext $context)
    {
        $user = auth()->user();
        $roles = $user->roleList();
        $branches = $context->options();

        $this->role = $context->role()?->value ?? $roles->first()?->value ?? '';
        $this->branch = (string) ($context->isAll() ? BranchContext::ALL : ($context->id() ?? $branches->first()?->id ?? ''));

        // Owner langsung masuk sebagai Owner di "Semua cabang"; cabang/peran bisa diganti dari topbar.
        if ($user->hasRole(Role::Owner) && ! $context->hasSelection()) {
            $context->set(Role::Owner, BranchContext::ALL);

            return $this->redirectRoute('dashboard');
        }

        if ($roles->count() === 1 && $branches->count() === 1 && ! $context->hasSelection()) {
            $context->set($roles->first(), $branches->first()->id);

            return $this->redirectRoute('dashboard');
        }
    }

    public function updatedRole(): void
    {
        if ($this->branch === BranchContext::ALL && $this->role !== Role::Owner->value) {
            $this->branch = (string) auth()->user()->accessibleBranches()->first()?->id;
        }
    }

    public function choose(BranchContext $context)
    {
        $this->validate([
            'role' => 'required',
            'branch' => 'required',
        ], attributes: ['role' => 'peran', 'branch' => 'cabang']);

        $context->set(Role::from($this->role), $this->branch);

        // Halaman terakhir yang teringat (mis. milik pengguna sebelumnya di browser yang sama)
        // hanya dipakai bila peran aktif boleh membukanya; selain itu ke Dashboard, bukan 403.
        if (! $this->intendedAllowed($context, session('url.intended'))) {
            session()->forget('url.intended');
        }

        return $this->redirectIntended(route('dashboard'), navigate: true);
    }

    /** Cek middleware "menu:" dan "role:" pada route tujuan untuk peran yang baru dipilih. */
    private function intendedAllowed(BranchContext $context, ?string $url): bool
    {
        if (! $url) {
            return true;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($url));
        } catch (\Throwable) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name, $params] = array_pad(explode(':', $middleware, 2), 2, '');

            if ($name === 'menu' && ! Access::canOpen($params)) {
                return false;
            }

            if ($name === 'role' && ! $context->is(...array_map(fn (string $r) => Role::from($r), explode(',', $params)))) {
                return false;
            }
        }

        return true;
    }

    public function render(BranchContext $context)
    {
        return view('livewire.auth.select-branch', [
            'roles' => auth()->user()->roleList(),
            'branches' => $context->options(),
        ]);
    }
}
