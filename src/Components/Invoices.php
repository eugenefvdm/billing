<?php

namespace Eugenefvdm\Billing\Components;

use Eugenefvdm\Billing\Enums\PaymentMethod;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Invoices extends Component
{
    public $user;

    public $invoices;

    protected $listeners = ['refreshComponent' => 'refreshInvoices'];

    public function mount()
    {
        $this->user = Auth::user();
    }

    public function refreshInvoices()
    {
        $this->user->refresh();
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        $this->invoices = $this->user->invoices()
            ->whereHas('subscription', fn($q) => 
                $q->where('payment_method', PaymentMethod::Eft)
            )
            ->orderByDesc('created_at')
            ->get();

        return view('billing::components.invoices');
    }
}

