<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Cabang & peran aktif pengguna, disimpan di session (FR-AUT-02/03).
 * branch_id null berarti "Semua cabang" dan hanya boleh untuk Owner.
 */
class BranchContext
{
    public const SESSION_BRANCH = 'active_branch_id';

    public const SESSION_ROLE = 'active_role';

    public const ALL = 'all';

    private ?Branch $branchCache = null;

    public function user(): ?User
    {
        return auth()->user();
    }

    public function role(): ?Role
    {
        $value = session(self::SESSION_ROLE);

        return $value ? Role::tryFrom($value) : null;
    }

    public function is(Role ...$roles): bool
    {
        return in_array($this->role(), $roles, true);
    }

    public function isAll(): bool
    {
        return session(self::SESSION_BRANCH) === self::ALL;
    }

    public function id(): ?int
    {
        $value = session(self::SESSION_BRANCH);

        return is_numeric($value) ? (int) $value : null;
    }

    public function branch(): ?Branch
    {
        $id = $this->id();

        if (! $id) {
            return null;
        }

        return $this->branchCache?->id === $id ? $this->branchCache : $this->branchCache = Branch::find($id);
    }

    public function hasSelection(): bool
    {
        return $this->role() !== null && ($this->isAll() || $this->id() !== null);
    }

    /** @return Collection<int, Branch> */
    public function options(): Collection
    {
        return $this->user()?->accessibleBranches() ?? collect();
    }

    public function label(): string
    {
        return $this->isAll() ? 'Semua cabang' : ($this->branch()?->name ?? '—');
    }

    public function set(Role $role, string|int $branch): void
    {
        $user = $this->user();

        abort_unless($user && $user->hasRole($role), 403);

        if ($branch === self::ALL) {
            abort_unless($role === Role::Owner, 403, 'Pilihan "Semua cabang" hanya untuk Owner.');
        } else {
            abort_unless($this->options()->contains('id', (int) $branch), 403);
            $branch = (int) $branch;
        }

        session([self::SESSION_ROLE => $role->value, self::SESSION_BRANCH => $branch]);
        $this->branchCache = null;
    }

    public function forget(): void
    {
        session()->forget([self::SESSION_ROLE, self::SESSION_BRANCH]);
    }
}
