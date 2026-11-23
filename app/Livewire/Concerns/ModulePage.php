<?php

namespace App\Livewire\Concerns;

use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Format;
use App\Support\Navigation;
use Illuminate\Validation\ValidationException;

/**
 * Pola bersama halaman modul: judul, breadcrumb, menu aktif dari Navigation, dan hak akses menu.
 *
 * Komponen mendeklarasikan `const MENU` (key Navigation) dan `const READ_ACTIONS` (method yang boleh
 * dipanggil tanpa izin Edit). Method lain dicek izin Edit oleh hook di AppServiceProvider.
 */
trait ModulePage
{
    protected function page(string $key, string $view, array $data = [])
    {
        $item = Navigation::item($key);
        $menu = $this->menuKey();

        // Angka rupiah di halaman ini disamarkan bila peran tidak boleh melihat keuangan menu tersebut.
        Format::mask(! Access::can($menu, 'keuangan'));

        return view($view, $data + ['item' => $item, 'canEdit' => Access::can($menu, 'edit'), 'canSeeMoney' => ! Format::masked()])
            ->title($item['label'])
            ->layoutData(['breadcrumb' => $item['breadcrumb'], 'active' => $key]);
    }

    /**
     * Key Navigation untuk hak akses. Tanpa $method = menu halaman yang sedang tampil (untuk angka & tombol);
     * dengan $method = menu pemilik aksi itu (untuk izin Edit). Komponen dengan tab pintasan menimpa method ini.
     * Jangan menentukan menu aksi dari properti publik (mis. $tab): nilainya bisa diubah dari browser.
     */
    protected function menuKey(?string $method = null): string
    {
        return static::MENU;
    }

    protected function canEditMenu(?string $method = null): bool
    {
        return Access::can($this->menuKey($method), 'edit');
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
