<?php

namespace Eugenefvdm\Billing\Components;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Billing extends Component
{
    public function render(): View
    {
        return view('billing::components.billing');
    }
}
