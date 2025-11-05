<?php

namespace Eugenefvdm\Billing\Components;

use Eugenefvdm\Billing\Enums\PaymentMethod;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class Invoices extends Component
{
    public $user;

    public $invoices;

    #[Url(as: 'payment_cancelled', history: true, keep: false)]
    public $paymentCancelled = false;

    public $dismissedPaymentCancelled = false;

    protected $listeners = [
        'refreshComponent' => 'refreshInvoices',
        'billingUpdated' => 'refreshInvoices',
    ];

    public function mount()
    {
        $this->user = Auth::user();
        
        // Reset dismissal state when payment_cancelled URL parameter is present
        if ($this->paymentCancelled) {
            $this->dismissedPaymentCancelled = false;
        }
    }

    public function refreshInvoices()
    {
        $this->user->refresh();
    }

    /**
     * Dismiss payment cancelled message
     */
    public function dismissPaymentCancelled()
    {
        $this->dismissedPaymentCancelled = true;
        // Clear the URL parameter by setting paymentCancelled to false
        $this->paymentCancelled = false;
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        $this->invoices = $this->user->invoices()
            ->whereHas(
                'subscription',
                fn($q) =>
                $q->where('payment_method', PaymentMethod::Eft)
            )
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return view('billing::components.invoices');
    }
}
