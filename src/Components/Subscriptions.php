<?php

namespace Eugenefvdm\Billing\Components;

use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Facades\Payfast;
use Eugenefvdm\Billing\Services\InvoiceService;
use Eugenefvdm\Billing\Subscription;
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

    public $paymentMethod;

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
        $this->user->refresh();
        $this->dispatch('refreshComponent')->to('receipts');
        $this->dispatch('refreshComponent')->to('invoices');

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
        $subscription = $this->user->subscription('default');
        
        // Handle EFT subscriptions differently (no Payfast API call needed)
        if ($subscription->payment_method === PaymentMethod::Eft) {
            // For EFT subscriptions, cancel at the end of the current billing period
            $endsAt = $subscription->ends_at ?? now();
            
            $subscription->forceFill([
                'status' => Subscription::Deleted,
                'ends_at' => $endsAt,
                'cancelled_at' => now(),
            ])->save();
            
            Log::info("Cancelled EFT subscription {$subscription->id} for user {$this->user->id}");
        } else {
            // Handle Payfast subscriptions (existing flow)
            Payfast::debug('Cancelling subscription for ' . $subscription->provider_id, 'warning');
            $subscription->cancel2();
        }

        $this->user->refresh();
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
     * Displays the Payfast modal with all the correct form values or creates EFT subscription
     */
    public function displayCreateSubscription()
    {
        ray('displayCreateSubscription has been called');
        ray($this->type);
        ray($this->paymentMethod);

        // Validate payment method selection
        $availableMethods = $this->user->availablePaymentMethods();
        if (!in_array($this->paymentMethod, $availableMethods)) {
            $this->addError('paymentMethod', 'Selected payment method is not available.');
            return;
        }

        // Handle EFT subscription creation
        if ($this->paymentMethod === 'eft') {
            $this->createEftSubscription();
            return;
        }

        // Handle Card subscription (existing Payfast flow)
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

    /**
     * Create an EFT subscription and invoice
     */
    public function createEftSubscription()
    {
        // Extract plan index and interval from type (format: "0|monthly" or "1|yearly")
        [$planIndex, $interval] = explode('|', $this->type);
        $planIndex = (int) $planIndex;

        // Get plan config
        $plans = config('billing.billables.user.plans');
        if (!isset($plans[$planIndex])) {
            $this->addError('type', 'Invalid plan selected.');
            return;
        }

        $plan = $plans[$planIndex];
        if (!isset($plan[$interval])) {
            $this->addError('type', 'Invalid interval selected.');
            return;
        }

        // Calculate dates
        $startsAt = now();
        $endsAt = $interval === 'monthly' 
            ? $startsAt->copy()->addMonth() 
            : $startsAt->copy()->addYear();

        // Create EFT subscription
        $subscription = $this->user->subscriptions()->create([
            'name' => 'default',
            'type' => $this->type, // Store full format "0|monthly" for consistency
            'payment_method' => PaymentMethod::Eft,
            'status' => Subscription::Active,
            'start_date' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        // Create invoice for the subscription
        $invoice = InvoiceService::createSubscriptionInvoice($subscription);

        // Generate PDF
        InvoiceService::createPdf($invoice);

        // Email invoice
        \Illuminate\Support\Facades\Mail::to($this->user->email)
            ->send(new \Eugenefvdm\Billing\Mail\InvoiceCreated($invoice));

        Log::info("Created EFT subscription {$subscription->id} with invoice {$invoice->id} for user {$this->user->id}");

        // Refresh user to get latest subscription data
        $this->user->refresh();

        // Refresh components
        $this->dispatch('billingUpdated');
    }

    public function mount()
    {
        $this->user = Auth::user();

        // Set default payment method based on available methods
        $availableMethods = $this->user->availablePaymentMethods();
        if (count($availableMethods) === 1) {
            $this->paymentMethod = $availableMethods[0];
        } elseif (count($availableMethods) > 1) {
            // Find the first method from config that exists in available methods
            $defaultMethods = config('billing.default_payment_methods');
            foreach ($defaultMethods as $method) {
                if (in_array($method, $availableMethods)) {
                    $this->paymentMethod = $method;
                    break;
                }
            }
            // Fallback to first available if none match (shouldn't happen if config is correct)
            if (!in_array($this->paymentMethod, $availableMethods)) {
                $this->paymentMethod = $availableMethods[0];
            }
        } else {
            // Fallback to first config method if no available methods
            $defaultMethods = config('billing.default_payment_methods');
            $this->paymentMethod = $defaultMethods[0];
        }

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
        return view('billing::components.subscriptions');
    }
}
