<?php

namespace App\Livewire;

use App\Support\BranchContext;
use App\Support\Navigation;
use Livewire\Component;

/** Halaman "Lainnya" (dulu dari bar bawah ponsel); kini menu layar sempit lewat hamburger, halaman ini tetap ada untuk tautan lama. */
class MoreMenu extends Component
{
    public function render(BranchContext $context)
    {
        return view('livewire.more-menu', ['items' => Navigation::visible($context->role())])
            ->title('Lainnya')
            ->layoutData(['breadcrumb' => ['Lainnya'], 'active' => 'lainnya']);
    }
}
