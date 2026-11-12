<?php

namespace App\Livewire\Concerns;

use App\Support\BranchContext;
use App\Support\Navigation;
use Illuminate\Validation\ValidationException;

/**
 * Pola bersama halaman modul: judul, breadcrumb, dan menu aktif dari Navigation.
 */
trait ModulePage
{
    protected function page(string $key, string $view, array $data = [])
    {
        $item = Navigation::item($key);

        return view($view, $data + ['item' => $item])
            ->title($item['label'])
            ->layoutData(['breadcrumb' => $item['breadcrumb'], 'active' => $key]);
    }

    protected function toast(string $message): void
    {
        $this->dispatch('toast', message: $message);
    }

    /** Aksi tulis yang terikat cabang tidak boleh dilakukan saat "Semua cabang". */
    protected function requireBranch(BranchContext $context, string $field = 'branch'): int
    {
        if (! $context->id()) {
            throw ValidationException::withMessages([$field => 'Pilih cabang tertentu terlebih dulu (bukan "Semua cabang").']);
        }

        return $context->id();
    }

    protected function clinicId(): int
    {
        return (int) auth()->user()->clinic_id;
    }
}
