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
            Log::debug("=== EFT SUBSCRIPTION CANCELLATION ===");
            Log::debug("Cancelling EFT subscription ID: {$subscription->id} for user {$this->user->id}");
            Log::debug("Current subscription ends_at date: " . ($subscription->ends_at ? $subscription->ends_at->format('jS \o\f F Y') : 'NULL'));
            Log::debug("Current date/time: " . now()->format('jS \o\f F Y \a\t H:i:s'));
            
            // For EFT subscriptions, cancel at the end of the current billing period
            $endsAt = $subscription->ends_at ?? now();
            
            Log::debug("Subscription will be cancelled with ends_at: {$endsAt->format('jS \o\f F Y')}");
            
            $subscription->forceFill([
                'status' => Subscription::Deleted,
                'ends_at' => $endsAt,
                'cancelled_at' => now(),
            ])->save();
            
            Log::debug("✓ Subscription cancelled successfully");
            Log::info("Cancelled EFT subscription {$subscription->id} for user {$this->user->id}");
        } else {
            // Handle Payfast subscriptions (existing flow)
            Payfast::debug('Cancelling subscription for ' . $subscription->provider_id, 'warning');
            $subscription->cancel();
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
     * When payment method changes, clear any errors
     */
    public function updatedPaymentMethod($value)
    {
        $this->resetErrorBag('paymentMethod');
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

        // Check for outstanding unpaid invoices
        $outstandingInvoices = $this->user->invoices()
            ->unpaid()
            ->whereHas('subscription', fn($q) => 
                $q->where('payment_method', PaymentMethod::Eft)
            )
            ->count();

        if ($outstandingInvoices > 0) {
            $this->addError('paymentMethod', 'You have an outstanding invoice.');
            Log::info("Attempted to create EFT subscription for user {$this->user->id} but there are {$outstandingInvoices} outstanding invoices");
            return;
        }

        // Check for existing EFT subscription
        // Only check EFT subscriptions - Payfast subscriptions are handled separately via webhooks
        $existingEftSubscription = $this->user->subscriptions()
            ->where('payment_method', PaymentMethod::Eft)
            ->where('name', 'default')
            ->first();
            
        if ($existingEftSubscription) {
            Log::debug("=== RESUBSCRIPTION DETECTED ===");
            Log::debug("User {$this->user->id} already has an EFT subscription ID: {$existingEftSubscription->id}");
            Log::debug("Existing EFT subscription status: {$existingEftSubscription->status}");
            Log::debug("Existing EFT subscription ends_at: " . ($existingEftSubscription->ends_at ? $existingEftSubscription->ends_at->format('jS \o\f F Y') : 'NULL'));
            Log::debug("Existing EFT subscription cancelled_at: " . ($existingEftSubscription->cancelled_at ? $existingEftSubscription->cancelled_at->format('jS \o\f F Y') : 'NULL'));
        } else {
            Log::debug("=== NEW SUBSCRIPTION ===");
            Log::debug("User {$this->user->id} does not have an existing EFT subscription");
        }

        // Calculate start date
        // If resubscribing EFT after cancellation, continue from where the old EFT subscription ended
        // This prevents creating duplicate periods for time already paid
        // Payfast subscriptions are handled separately and won't affect this logic
        $startsAt = now();
        if ($existingEftSubscription && $existingEftSubscription->ends_at && $existingEftSubscription->ends_at->isFuture()) {
            Log::debug("Resubscribing EFT: Found cancelled EFT subscription ending in the future");
            Log::debug("Old EFT subscription ends_at: {$existingEftSubscription->ends_at->format('jS \o\f F Y')}");
            Log::debug("Starting new EFT subscription from old subscription's end date to continue seamlessly");
            $startsAt = $existingEftSubscription->ends_at->copy();
        }
        
        $endsAt = $interval === 'monthly' 
            ? $startsAt->copy()->addMonth() 
            : $startsAt->copy()->addYear();

        Log::debug("=== EFT SUBSCRIPTION CREATION ===");
        Log::debug("A new EFT subscription is being created for user {$this->user->id}");
        Log::debug("Subscription period will be FROM: {$startsAt->format('jS \o\f F Y')} TO: {$endsAt->format('jS \o\f F Y')}");
        Log::debug("Current date/time: " . now()->format('jS \o\f F Y \a\t H:i:s'));

        // Create EFT subscription
        $subscription = $this->user->subscriptions()->create([
            'name' => 'default',
            'type' => $this->type, // Store full format "0|monthly" for consistency
            'payment_method' => PaymentMethod::Eft,
            'status' => Subscription::Active,
            'start_date' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        Log::debug("✓ New subscription created with ID: {$subscription->id}");
        Log::debug("✓ Subscription ends_at date: {$subscription->ends_at->format('jS \o\f F Y')}");

        // Create invoice for the subscription
        $invoice = InvoiceService::createSubscriptionInvoice($subscription);

        // Generate PDF
        InvoiceService::createPdf($invoice);

        // Email invoice
        \Illuminate\Support\Facades\Mail::to($this->user->email)
            ->send(new \Eugenefvdm\Billing\Mail\InvoiceCreated($invoice));

        Log::debug("✓ Invoice PDF generated and email sent");
        Log::debug("=== SUBSCRIPTION CREATION COMPLETE ===");
        Log::debug("Final subscription ID: {$subscription->id}");
        Log::debug("Final subscription ends_at: {$subscription->ends_at->format('jS \o\f F Y')}");
        Log::debug("Final invoice ID: {$invoice->id}");
        Log::debug("Final invoice period: {$invoice->items()->first()->description}");
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
     * Get outstanding invoices count for display
     */
    public function getHasOutstandingInvoicesProperty(): bool
    {
        if ($this->paymentMethod !== 'eft') {
            return false;
        }

        return $this->user->invoices()
            ->unpaid()
            ->whereHas('subscription', fn($q) => 
                $q->where('payment_method', PaymentMethod::Eft)
            )
            ->exists();
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
