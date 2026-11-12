<?php

namespace App\Livewire\Concerns;

use App\Support\BranchContext;
use Illuminate\Validation\ValidationException;

/**
 * Cabang tujuan untuk aksi tulis. Bila topbar = cabang tertentu, pakai itu.
 * Bila "Semua cabang" (Owner), form menampilkan pilihan cabang ($branchPick) agar tidak perlu pindah cabang dulu.
 */
trait PicksBranch
{
    public string $branchPick = '';

    protected function targetBranch(BranchContext $context, string $errorField = 'branchPick'): int
    {
        if ($context->id()) {
            return $context->id();
        }

        $id = (int) $this->branchPick;
        if (! $id || ! $context->options()->contains('id', $id)) {
            throw ValidationException::withMessages([$errorField => 'Pilih cabang terlebih dulu.']);
        }

        return $id;
    }

    /** Cabang yang sedang dipakai form (untuk menampilkan stok, dokter bertugas, dll); null bila belum dipilih. */
    protected function formBranchId(BranchContext $context): ?int
    {
        return $context->id() ?? ((int) $this->branchPick ?: null);
    }
}
