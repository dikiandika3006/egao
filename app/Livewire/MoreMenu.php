<?php

namespace App\Livewire;

use App\Support\BranchContext;
use App\Support\Navigation;
use Livewire\Component;

/** Menu "Lainnya" di bottom bar Owner (ponsel). */
class MoreMenu extends Component
{
    public function render(BranchContext $context)
    {
        return view('livewire.more-menu', ['items' => Navigation::visible($context->role())])
            ->title('Lainnya')
            ->layoutData(['breadcrumb' => ['Lainnya'], 'active' => 'lainnya']);
    }
}
