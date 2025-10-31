<?php

namespace Eugenefvdm\Billing\Components;

use Eugenefvdm\Billing\Facades\Payfast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class Subscriptions extends Component
{
    public $user;

    public $confirmingCancelSubscription = false;

    public $displayingCreateSubscription = false;

    public $type = '0|monthly';

    public $identifier;

    public $updateCardLink;

    public $mergeFields;

    public $afterTrialNextDueDate;

    private $password;

    protected $listeners = [
        'billingUpdated' => 'billingWasUpdated',
    ];

    /**
     * After billing is updated, that means when Payfast onsite subscription modal goes
     * away, the front-end must reflect the changes that could be a new subscription
     * or the receipt that was updated when a paying also came in.
     */
    public function billingWasUpdated()
    {
        $this->dispatch('refreshComponent')->to('receipts');

        $this->displayingCreateSubscription = false;
    }

    public function confirmCancelSubscription()
    {
        $this->resetErrorBag();

        $this->password = '';

        $this->dispatch('confirming-cancel-subscription');

        $this->confirmingCancelSubscription = true;
    }

    public function cancelSubscription(): void
    {
        Payfast::debug('Cancelling subscription for ' . $this->user->subscriptions()->active()->first()->provider_id, 'warning');

        $this->user->subscription('default')->cancel2();

        $this->dispatch('billingUpdated');

        $this->confirmingCancelSubscription = false;
    }

    /**
     * Update card
     */
    public function updateCard()
    {
        $provider_id = $this->user->subscription('default')->provider_id;

        ray("updateCard has been called with this token: $provider_id");

        $url = Payfast::url() . "/recurring/update/$provider_id?return=" . Payfast::updateCardCallbackUrl() . "/user/profile?card_updated=true";

        $message = "updateCard is going to redirect()->to this URL: " . $url;

        Log::debug($message);

        Log::debug($url);

        ray($message);

        ray($url);

        return redirect()->to($url);
    }

    /**
     * When the selected plan changes, refresh the Payfast identifier's signature
     * and UI value which indicates when the plan will be payable next. The next
     * payable date depends on if the user has chosen a monthly or yearly sub.
     */
    public function updatedType($type)
    {
        ray($type);

        $this->type = $type;

        if ($this->user->onGenericTrial()) {
            if ($type === 'monthly') {
                $this->afterTrialNextDueDate = $this->user->trialEndsAt()->addMonth()->addDay()->format('jS \o\f F Y');
            }

            if ($type === 'yearly') {
                $this->afterTrialNextDueDate = $this->user->trialEndsAt()->addYear()->addDay()->format('jS \o\f F Y');
            }
        }
    }

    /**
     * Displays the Payfast modal with all the correct form values
     */
    public function displayCreateSubscription()
    {
        ray('displayCreateSubscription has been called');
        ray($this->type);

        // User's trial has been activated but they have never been a subscriber
        if ($this->user->onGenericTrial() && ! $this->user->subscribed('default')) {
            $billingDate = $this->user->trialEndsAt()->addDay();

            if ($this->type === 'monthly') {
                $billingDate = $billingDate->addMonth();
            }

            if ($this->type === 'yearly') {
                $billingDate = $billingDate->addYear();
            }

            $billingDate = $billingDate->format('Y-m-d');
        }

        // User has or has had an active subscription but is still in a trial period
        if ($this->user->subscribed('default') && $this->user->subscription('default')->onGracePeriod()) {
            $billingDate = $this->user->subscription('default')->ends_at->addDay()->format('Y-m-d');
        }

        if (! isset($billingDate)) {
            $billingDate = \Carbon\Carbon::now()->format('Y-m-d');
        }

        if ($this->user->subscribed('default') && $this->user->subscription('default')->onGracePeriod()) {
            $this->mergeFields = array_merge($this->mergeFields, ['amount' => 0]);
        }

        $this->identifier = Payfast::createOnsitePayment(
            $this->type,
            $billingDate,
            $this->mergeFields
        );

        $this->displayingCreateSubscription = true;
        $this->dispatch('launchPayfast', identifier: $this->identifier);
    }

    public function mount()
    {
        $this->user = Auth::user();

        if ($this->user->onGenericTrial()) {
            $this->afterTrialNextDueDate = $this->user->trialEndsAt()->addMonth()->addDay()->format('jS \o\f F Y');
        }
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('payfast::components.subscriptions');
    }
}
