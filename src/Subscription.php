<?php

namespace Eugenefvdm\Billing;

use Carbon\Carbon;
use DateTimeInterface;
use Exception;
use Eugenefvdm\Billing\Concerns\Prorates;
use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Facades\Payfast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * @property Billable $billable
 */
class Subscription extends Model
{
    use Prorates;

    public $table = 'subscriptions';

    public const Active = 'ACTIVE';
    public const Trialing = 'trialing';
    public const PastDue = 'past_due';
    public const Paused = 'PAUSED';
    public const Deleted = 'CANCELLED';
    public const Upstream = 'UPSTREAM'; // Only applicable for Payfast

    public static function uiOptions()
    {
        return [
            self::Active => 'Active',
            self::Trialing => 'Trialing',
            self::PastDue => 'Past Due',
            self::Paused => 'Paused',
            self::Deleted => 'Cancelled',
        ];
    }

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'token' => 'string',
        'type' => 'string',
        'payment_method' => PaymentMethod::class,
        'next_bill_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * The cached Payfast info for the subscription.
     *
     * @var array
     */
    protected $payfastInfo;

    /**
     * Get the billable model related to the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function billable()
    {
        return $this->morphTo();
    }

    /**
     * Get all of the receipts for the Billable model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function receipts()
    {
        return $this->hasMany(Cashier::$receiptModel, 'provider_id', 'provider_id')->orderByDesc('created_at');
    }

    /**
     * Determine if the subscription has a specific type.
     *
     * @param  string  $type
     * @return bool
     */
    public function hasType($type)
    {
        return $this->type == $type;
    }

    /**
     * Determine if the subscription has a specific plan (alias for hasType).
     *
     * @param  string  $plan
     * @return bool
     * @deprecated Use hasType() instead
     */
    public function hasPlan($plan)
    {
        return $this->hasType($plan);
    }

    /**
     * Determine if the subscription is active, on trial, or within its grace period.
     *
     * @return bool
     */
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onPausedGracePeriod() || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active(): bool
    {
        return (is_null($this->ends_at) || $this->onGracePeriod() || $this->onPausedGracePeriod()) &&
            (! Cashier::$deactivatePastDue || $this->status !== self::PastDue) &&
            $this->status !== self::Paused;
    }

    /**
     * Filter query by active.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeActive($query)
    {
        $query->where(function ($query) {
            $query->whereNull('ends_at')
                ->orWhere(function ($query) {
                    $query->onGracePeriod();
                })
                ->orWhere(function ($query) {
                    $query->onPausedGracePeriod();
                });
        })->where('status', '!=', self::Paused);

        if (Cashier::$deactivatePastDue) {
            $query->where('status', '!=', self::PastDue);
        }
    }

    /**
     * Determine if the subscription is past due.
     *
     * @return bool
     */
    public function pastDue()
    {
        return $this->status === self::PastDue;
    }

    /**
     * Filter query by past due.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopePastDue($query)
    {
        $query->where('status', self::PastDue);
    }

    /**
     * Determine if the subscription is recurring and not on trial.
     *
     * @return bool
     */
    public function recurring()
    {
        return ! $this->onTrial() && ! $this->paused() && ! $this->onPausedGracePeriod() && ! $this->cancelled();
    }

    /**
     * Filter query by recurring.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeRecurring($query)
    {
        $query->notOnTrial()->notCancelled();
    }

    /**
     * Determine if the subscription is paused.
     *
     * @return bool
     */
    public function paused()
    {
        return $this->status === self::Paused;
    }

    /**
     * Filter query by paused.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopePaused($query)
    {
        $query->where('status', self::Paused);
    }

    /**
     * Filter query by not paused.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeNotPaused($query)
    {
        $query->where('status', '!=', self::Paused);
    }

    /**
     * Determine if the subscription is within its grace period after being paused.
     *
     * @return bool
     */
    public function onPausedGracePeriod()
    {
        return $this->paused_at && $this->paused_at->isFuture();
    }

    /**
     * Filter query by on trial grace period.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeOnPausedGracePeriod($query)
    {
        $query->whereNotNull('paused_at')->where('paused_at', '>', Carbon::now());
    }

    /**
     * Filter query by not on trial grace period.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeNotOnPausedGracePeriod($query)
    {
        $query->whereNull('paused_at')->orWhere('paused_at', '<=', Carbon::now());
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Filter query by cancelled.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeCancelled($query)
    {
        $query->whereNotNull('ends_at');
    }

    /**
     * Filter query by not cancelled.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeNotCancelled($query)
    {
        $query->whereNull('ends_at');
    }

    /**
     * Determine if the subscription has ended and the grace period has expired.
     *
     * @return bool
     */
    public function ended()
    {
        return $this->cancelled() && ! $this->onGracePeriod();
    }

    /**
     * Filter query by ended.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeEnded($query)
    {
        $query->cancelled()->notOnGracePeriod();
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Filter query by on trial.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeOnTrial($query)
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
    }

    /**
     * Determine if the subscription's trial has expired.
     *
     * @return bool
     */
    public function hasExpiredTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Filter query by expired trial.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeExpiredTrial($query)
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', Carbon::now());
    }

    /**
     * Filter query by not on trial.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeNotOnTrial($query)
    {
        $query->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', Carbon::now());
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Filter query by on grace period.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeOnGracePeriod($query)
    {
        $query->whereNotNull('ends_at')->where('ends_at', '>', Carbon::now());
    }

    /**
     * Filter query by not on grace period.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeNotOnGracePeriod($query)
    {
        $query->whereNull('ends_at')->orWhere('ends_at', '<=', Carbon::now());
    }


    /**
     * Increment the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function incrementQuantity($count = 1)
    {
        $this->updateQuantity($this->quantity + $count);

        return $this;
    }

    /**
     *  Increment the quantity of the subscription, and invoice immediately.
     *
     * @param  int  $count
     * @return $this
     */
    public function incrementAndInvoice($count = 1)
    {
        $this->updateQuantity($this->quantity + $count, [
            'bill_immediately' => true,
        ]);

        return $this;
    }

    /**
     * Decrement the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function decrementQuantity($count = 1)
    {
        return $this->updateQuantity(max(1, $this->quantity - $count));
    }

    /**
     * Update the quantity of the subscription.
     *
     * @param  int  $quantity
     * @param  array  $options
     * @return $this
     */
    public function updateQuantity($quantity, array $options = [])
    {
        $this->guardAgainstUpdates('update quantities');

        $this->forceFill([
            'quantity' => $quantity,
        ])->save();

        $this->payfastInfo = null;

        return $this;
    }

    /**
     * Swap the subscription to a new type.
     *
     * @param  string  $type
     * @param  array  $options
     * @return $this
     */
    public function swap($type, array $options = [])
    {
        $this->guardAgainstUpdates('swap plans');

        $this->forceFill([
            'type' => $type,
        ])->save();

        $this->payfastInfo = null;

        return $this;
    }

    /**
     * Swap the subscription to a new type, and invoice immediately.
     *
     * @param  string  $type
     * @param  array  $options
     * @return $this
     */
    public function swapAndInvoice($type, array $options = [])
    {
        return $this->swap($type, array_merge($options, [
            'bill_immediately' => true,
        ]));
    }

    /**
     * Pause the subscription.
     *
     * @return $this
     */
    public function pause()
    {
        $info = $this->payfastInfo();

        $this->forceFill([
            'status' => $info['state'],
            'paused_at' => Carbon::createFromFormat('Y-m-d H:i:s', $info['paused_from'], 'UTC'),
        ])->save();

        $this->payfastInfo = null;

        return $this;
    }

    /**
     * Resume a paused subscription.
     *
     * @return $this
     */
    public function unpause()
    {
        $this->forceFill([
            'status' => self::Active,
            'ends_at' => null,
            'paused_at' => null,
        ])->save();

        $this->payfastInfo = null;

        return $this;
    }

    /**
     * Update the underlying Payfast subscription information for the model.
     *
     * The important item here is the "run_date" which is the date of the next payment.
     *
     * TODO Check how similar this code is to Override Status
     */
    public function updatePayfastSubscription(array $result)
    {
        if ($result['status'] !== 'success') {
            $message = 'Unable to update Payfast subscription because API result !== success';

            Log::error($message);

            $message = 'Result will follow';

            Log::error($message);

            Log::debug($result);
        }

        $subscription = Subscription::where(
            'provider_id',
            $result['data']['response']['token']
        )->firstOrFail();

        Log::debug("status/status_text: ", [$result['data']['response']['status_text']]);
        Log::debug("run_date: ", [$result['data']['response']['run_date']]);

        $subscription->status = $result['data']['response']['status_text'];
        $subscription->next_bill_at = $result['data']['response']['run_date'];

        if ($subscription->status == self::Deleted && ! $subscription->cancelled_at) {
            $message = ("Subscription status at Payfast is cancelled but no cancelled_at exists. Adding now() as cancellation date.");

            Log::warning($message);

            ray($message)->orange();

            $subscription->cancelled_at = now();

            $subscription->ends_at = now();
        }

        $subscription->save();
    }


    /**
     * Cancel the subscription at the end of the current billing period.
     *
     * @return $this
     */
    public function cancel()
    {
        if ($this->onGracePeriod()) {
            return $this;
        }

        if ($this->onPausedGracePeriod() || $this->paused()) {
            $endsAt = $this->paused_at->isFuture()
                ? $this->paused_at
                : Carbon::now();
        } else {
            $endsAt = $this->onTrial()
                ? $this->trial_ends_at
                : $this->runDate()->date()->subDay(1);
        }

        return $this->cancelAt($endsAt);
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        return $this->cancelAt(Carbon::now());
    }

    /**
     * Cancel the subscription at a specific moment in time.
     *
     * @param  \DateTimeInterface  $endsAt
     * @return $this
     */
    public function cancelAt(DateTimeInterface $endsAt)
    {
        Payfast::cancelSubscription($this->provider_id);

        $this->forceFill([
            'status' => self::Deleted,
            'ends_at' => $endsAt,
            'cancelled_at' => now(),
        ])->save();

        $this->payfastInfo = null;

        return $this;
    }

    /**
     * Get the last payment for the subscription.
     *
     * @return \Eugenefvdm\Billing\Payment
     */
    public function lastPayment()
    {
        $payment = $this->payfastInfo()['last_payment'];

        return new Payment($payment['amount'], $payment['currency'], $payment['date']);
    }

    /**
     * Get the next payment for the subscription.
     *
     * Fixes the currency to ZAR and strips the date of the time portion which is
     * normally returned like this: 2022-11-01T00:00:00+02:00 for use in Payment date() method
     *
     * @return \Eugenefvdm\Billing\Payment|null
     */
    public function runDate()
    {
        if (! isset($this->payfastInfo()['run_date'])) {
            return;
        }

        $payment['date'] = $this->payfastInfo()['run_date'];
        $payment['currency'] = 'ZAR';
        $payment['amount'] = $this->payfastInfo()['amount'];

        return new Payment($payment['amount'], $payment['currency'], $payment['date']);
    }

    /**
     * Get the email address of the customer associated to this subscription.
     *
     * @return string
     */
    public function payfastEmail()
    {
        return (string) $this->payfastInfo()['user_email'];
    }

    /**
     * Get the payment method type from the subscription.
     *
     * @return string
     */
    public function paymentMethod()
    {
        return (string) ($this->payfastInfo()['payment_information']['payment_method'] ?? '');
    }

    /**
     * Get the card brand from the subscription.
     *
     * @return string
     */
    public function cardBrand()
    {
        return (string) ($this->payfastInfo()['payment_information']['card_type'] ?? '');
    }

    /**
     * Get the last four digits from the subscription if it's a credit card.
     *
     * @return string
     */
    public function cardLastFour()
    {
        return (string) ($this->payfastInfo()['payment_information']['last_four_digits'] ?? '');
    }

    /**
     * Get the card expiration date.
     *
     * @return string
     */
    public function cardExpirationDate()
    {
        return (string) ($this->payfastInfo()['payment_information']['expiry_date'] ?? '');
    }

    /**
     * Get raw information about the subscription from Payfast.
     *
     * Calls the Payfast API and returns the 'response' array in the 'data' array of the response object.
     * This will contain pertinent information about the subscription on record at Payfast.
     *
     * @return array
     */
    public function payfastInfo()
    {
        if ($this->payfastInfo) {
            return $this->payfastInfo;
        }

        $payfastInfo = Payfast::fetchSubscription($this->provider_id)['data']['response'];

        return $this->payfastInfo = $payfastInfo;
    }

    /**
     * Perform a guard check to prevent change for a specific action.
     *
     * @param  string  $action
     * @return void
     *
     * @throws \LogicException
     */
    public function guardAgainstUpdates($action): void
    {
        if ($this->onTrial()) {
            throw new LogicException("Cannot $action while on trial.");
        }

        if ($this->paused() || $this->onPausedGracePeriod()) {
            throw new LogicException("Cannot $action for paused subscriptions.");
        }

        if ($this->cancelled() || $this->onGracePeriod()) {
            throw new LogicException("Cannot $action for cancelled subscriptions.");
        }

        if ($this->pastDue()) {
            throw new LogicException("Cannot $action for past due subscriptions.");
        }
    }

    /**
     * Payfast frequencies - required for subscriptions
     *
     * See https://developers.payfast.co.za/docs#subscriptions
     */
    public static function frequencies($frequency): string
    {
        return match ($frequency) {
            1 => 'Daily',
            2 => 'Weekly',
            3 => 'Monthly',
            4 => 'Quarterly',
            5 => 'Biannually',
            6 => 'Annual',
        };
    }

    /**
     * EFT BILLING METHODS
     */

    /**
     * Calculate the end date based on start date and interval.
     */
    public static function calculateEndsAt(Carbon $start, \Eugenefvdm\Billing\Enums\PlanInterval $interval): Carbon
    {
        return match ($interval) {
            \Eugenefvdm\Billing\Enums\PlanInterval::Daily => $start->copy()->addDay(),
            \Eugenefvdm\Billing\Enums\PlanInterval::Weekly => $start->copy()->addWeek(),
            \Eugenefvdm\Billing\Enums\PlanInterval::Monthly => $start->copy()->addMonth(),
            \Eugenefvdm\Billing\Enums\PlanInterval::Quarterly => $start->copy()->addMonths(3),
            \Eugenefvdm\Billing\Enums\PlanInterval::Yearly => $start->copy()->addYear(),
        };
    }

    /**
     * Calculate the start date based on end date and interval.
     */
    protected function calculateStartsAt(Carbon $end, \Eugenefvdm\Billing\Enums\PlanInterval $interval): Carbon
    {
        return match ($interval) {
            \Eugenefvdm\Billing\Enums\PlanInterval::Daily => $end->copy()->subDay(),
            \Eugenefvdm\Billing\Enums\PlanInterval::Weekly => $end->copy()->subWeek(),
            \Eugenefvdm\Billing\Enums\PlanInterval::Monthly => $end->copy()->subMonth(),
            \Eugenefvdm\Billing\Enums\PlanInterval::Quarterly => $end->copy()->subMonths(3),
            \Eugenefvdm\Billing\Enums\PlanInterval::Yearly => $end->copy()->subYear(),
        };
    }

    /**
     * Get the starts_at date (calculated from ends_at).
     */
    public function getStartsAtAttribute(): Carbon
    {
        if (!$this->ends_at) {
            throw new Exception("Cannot calculate starts_at without ends_at");
        }

        return $this->calculateStartsAt($this->ends_at, $this->intervalFromType());
    }

    /**
     * Get a human-readable period description.
     */
    public function getPeriodDescriptionAttribute(): string
    {
        return $this->planName() . ' ' 
            . $this->starts_at->format('Y-m-d') 
            . ' to ' 
            . $this->ends_at->format('Y-m-d');
    }

    /**
     * Get the plan configuration from config.
     */
    public function plan(): array
    {
        $plans = config('billing.billables.user.plans');
        
        // Parse type field which contains format like "0|monthly" or "1|yearly"
        // Extract plan index and interval
        if (strpos($this->type, '|') !== false) {
            [$planIndex, $interval] = explode('|', $this->type);
            $planIndex = (int) $planIndex;
            
            // Get the plan at the specified index
            if (isset($plans[$planIndex])) {
                return $plans[$planIndex];
            }
        }
        
        // Fallback: try to find plan by matching type directly (for backwards compatibility)
        return collect($plans)->first(function ($plan) {
            return isset($plan[$this->type]);
        }) ?? [];
    }

    /**
     * Get the plan name.
     */
    public function planName(): string
    {
        return $this->plan()['name'] ?? 'Unknown';
    }

    /**
     * Get the interval enum from the subscription type.
     */
    public function intervalFromType(): \Eugenefvdm\Billing\Enums\PlanInterval
    {
        // Parse type field which contains format like "0|monthly" or "1|yearly"
        // Extract interval (monthly or yearly)
        if (strpos($this->type, '|') !== false) {
            [$planIndex, $interval] = explode('|', $this->type);
            return \Eugenefvdm\Billing\Enums\PlanInterval::from($interval);
        }
        
        // Fallback: try to use type directly as interval (for backwards compatibility)
        return \Eugenefvdm\Billing\Enums\PlanInterval::from($this->type);
    }

    /**
     * Forward the EFT subscription to the next billing period.
     * 
     * This is the core of EFT billing. It:
     * 1. Creates an invoice for the current period
     * 2. Generates and emails the PDF
     * 3. Moves the subscription forward by one interval
     */
    public function forward(): void
    {
        // Only process EFT subscriptions
        if ($this->payment_method !== PaymentMethod::Eft) {
            return;
        }

        // Only forward if current period has ended
        if ($this->starts_at >= now()) {
            return;
        }

        // Create invoice for the period
        $invoice = \Eugenefvdm\Billing\Services\InvoiceService::createSubscriptionInvoice($this);

        // Generate PDF
        \Eugenefvdm\Billing\Services\InvoiceService::createPdf($invoice);

        // Email invoice
        \Illuminate\Support\Facades\Mail::to($this->billable->email)
            ->send(new \Eugenefvdm\Billing\Mail\InvoiceCreated($invoice));

        // Move ends_at forward by one interval
        $this->ends_at = self::calculateEndsAt(
            $this->ends_at,
            $this->intervalFromType()
        );

        $this->save();

        Log::info("Forwarded EFT subscription {$this->id}, new ends_at: {$this->ends_at}");
    }

    /**
     * Scope a query to only include EFT subscriptions.
     */
    public function scopeEft($query)
    {
        return $query->where('payment_method', PaymentMethod::Eft);
    }

    /**
     * Scope a query to only include Card subscriptions.
     */
    public function scopeCard($query)
    {
        return $query->where('payment_method', PaymentMethod::Card);
    }
}
