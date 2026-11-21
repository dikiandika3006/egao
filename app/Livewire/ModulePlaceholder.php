<?php

namespace App\Livewire;

use App\Support\Navigation;
use Livewire\Component;

/**
 * Halaman sementara untuk modul yang belum dibangun. Ganti route-nya ke komponen asli saat modul siap.
 */
class ModulePlaceholder extends Component
{
    public string $module;

    public function mount(string $module): void
    {
        abort_unless(array_key_exists($module, Navigation::modules()), 404);
        $this->module = $module;
    }

    public function render()
    {
        $item = Navigation::item($module = $this->module);

        return view('livewire.module-placeholder', ['item' => $item])
            ->title($item['label'])
            ->layoutData(['breadcrumb' => $item['breadcrumb'], 'active' => $module]);
    }
}
