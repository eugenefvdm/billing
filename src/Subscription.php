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

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_PAUSED = 'PAUSED';
    public const STATUS_CANCELED = 'CANCELLED';
    public const STATUS_UPSTREAM = 'UPSTREAM'; // Only applicable to Payfast

    public static function statusOptions()
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_TRIALING => 'Trialing',
            self::STATUS_PAST_DUE => 'Past Due',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_CANCELED => 'Cancelled',
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
        'trial_ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'ends_at' => 'datetime',
        'provider_id' => 'string', // The Payfast Token
        'type' => 'string', // Composition of the array plan
        'payment_method' => PaymentMethod::class,
        'next_bill_at' => 'datetime',
        'cancelled_at' => 'datetime',
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
     * Get all of the receipts for the Billable model. Only applicable on credit card transactions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function receipts()
    {
        return $this->hasMany(Cashier::$receiptModel, 'provider_id', 'provider_id')->orderByDesc('created_at');
    }

    /**
     * Get all invoices for this subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Determine if the subscription has a specific plan.
     * 
     * Checks the interval part of the type field (e.g., "monthly" from "0|monthly").
     *
     * @param  string  $plan The plan interval to check (e.g., "monthly", "yearly")
     * @return bool
     */
    public function hasPlan($plan)
    {
        // If type contains |, extract the interval part (e.g., "monthly" from "0|monthly")
        if (strpos($this->type, '|') !== false) {
            [, $interval] = explode('|', $this->type);
            return $interval === $plan;
        }
        
        // Backwards compatibility: exact match for old format (e.g., type = "monthly")
        return $this->type === $plan;
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
        // Check status-based conditions first (applies to both payment methods)
        if ($this->status === self::STATUS_PAUSED) {
            return false;
        }
        
        if (Cashier::$deactivatePastDue && $this->status === self::STATUS_PAST_DUE) {
            return false;
        }
        
        // Payment method-specific date logic
        if ($this->payment_method === PaymentMethod::Eft) {
            // EFT: Active if current date is within the billing period
            // Period is from (ends_at - interval) to ends_at
            if (!$this->ends_at) {
                return false; // EFT subscriptions must have ends_at
            }
            
            $startsAt = $this->starts_at; // Uses getStartsAtAttribute accessor
            return $startsAt <= now() && now() < $this->ends_at;
        } else {
            // Card: Active if ends_at is NULL or in grace period
            return is_null($this->ends_at) || $this->onGracePeriod() || $this->onPausedGracePeriod();
        }
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
            // Card subscriptions: ends_at is NULL or in grace period
            $query->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('payment_method')
                      ->orWhere('payment_method', '!=', PaymentMethod::Eft->value);
                })
                ->where(function ($q) {
                    $q->whereNull('ends_at')
                      ->orWhere(function ($q) {
                          $q->onGracePeriod();
                      })
                      ->orWhere(function ($q) {
                          $q->onPausedGracePeriod();
                      });
                });
            })
            // EFT subscriptions: now() is within starts_at/ends_at period
            // Period start = ends_at - interval, so we check: (ends_at - interval) <= now() < ends_at
            ->orWhere(function ($query) {
                $query->where('payment_method', PaymentMethod::Eft->value)
                      ->whereNotNull('ends_at')
                      ->where('ends_at', '>', Carbon::now())
                      ->where(function ($q) {
                          $now = Carbon::now();
                          // Check each interval type separately (MariaDB-compatible)
                          $q->where(function ($q) use ($now) {
                              $q->where('type', 'LIKE', '%|daily%')
                                ->whereRaw('DATE_SUB(ends_at, INTERVAL 1 DAY) <= ?', [$now]);
                          })
                          ->orWhere(function ($q) use ($now) {
                              $q->where('type', 'LIKE', '%|weekly%')
                                ->whereRaw('DATE_SUB(ends_at, INTERVAL 1 WEEK) <= ?', [$now]);
                          })
                          ->orWhere(function ($q) use ($now) {
                              $q->where('type', 'LIKE', '%|monthly%')
                                ->whereRaw('DATE_SUB(ends_at, INTERVAL 1 MONTH) <= ?', [$now]);
                          })
                          ->orWhere(function ($q) use ($now) {
                              $q->where('type', 'LIKE', '%|quarterly%')
                                ->whereRaw('DATE_SUB(ends_at, INTERVAL 3 MONTH) <= ?', [$now]);
                          })
                          ->orWhere(function ($q) use ($now) {
                              $q->where('type', 'LIKE', '%|yearly%')
                                ->whereRaw('DATE_SUB(ends_at, INTERVAL 1 YEAR) <= ?', [$now]);
                          });
                      });
            });
        })->where('status', '!=', self::STATUS_PAUSED);

        if (Cashier::$deactivatePastDue) {
            $query->where('status', '!=', self::STATUS_PAST_DUE);
        }
    }

    /**
     * Determine if the subscription is past due.
     *
     * @return bool
     */
    public function pastDue()
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    /**
     * Filter query by past due.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopePastDue($query)
    {
        $query->where('status', self::STATUS_PAST_DUE);
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
        return $this->status === self::STATUS_PAUSED;
    }

    /**
     * Filter query by paused.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopePaused($query)
    {
        $query->where('status', self::STATUS_PAUSED);
    }

    /**
     * Filter query by not paused.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeNotPaused($query)
    {
        $query->where('status', '!=', self::STATUS_PAUSED);
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
            'status' => self::STATUS_ACTIVE,
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

        if ($subscription->status == self::STATUS_CANCELED && ! $subscription->cancelled_at) {
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
            if ($this->onTrial()) {
                $endsAt = $this->trial_ends_at;
            } elseif ($runDate = $this->runDate()) {
                $endsAt = $runDate->date()->subDay(1);
            } else {
                // Fallback if no run_date available (e.g., EFT subscription or missing Payfast data)
                $endsAt = $this->next_bill_at ? $this->next_bill_at->subDay(1) : Carbon::now();
            }
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
        // Only cancel with Payfast if this is a card subscription with a provider_id
        if ($this->provider_id && $this->payment_method !== PaymentMethod::Eft) {
            Payfast::cancelSubscription($this->provider_id);
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELED,
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
    public function planConfig(): array
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
        return $this->planConfig()['name'];        
    }

    /**
     * Get the plan name with interval (e.g., "Startup Plan Monthly").
     * This is the consistent format used throughout the application.
     */
    public function planNameWithInterval(): string
    {
        $planName = $this->planName();
        
        // Parse interval from type field (format: "0|monthly" or "1|yearly")
        $interval = $this->type;
        if (strpos($interval, '|') !== false) {
            [, $interval] = explode('|', $interval);
        }
        
        return $planName . ' ' . ucfirst($interval);
    }

    /**
     * Get the plan name with date range for invoice descriptions.
     * Returns format: "Plan Name Period 2025-11-01 to 2025-12-01"
     */
    public function planNameWithPeriod(): string
    {
        $planNameWithInterval = $this->planNameWithInterval();
        
        return $planNameWithInterval . ' ' 
            . $this->starts_at->format('Y-m-d') 
            . ' to ' 
            . $this->ends_at->format('Y-m-d');
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
     * 
     * Note: This method will create invoices even when there are outstanding unpaid invoices.
     * This is by design to ensure continuous billing cycles.
     */
    public function forward(): void
    {
        Log::debug("=== SUBSCRIPTION FORWARD COMMAND ===");
        Log::debug("Forward command called for subscription ID: {$this->id}");
        Log::debug("Current subscription ends_at date: " . ($this->ends_at ? $this->ends_at->format('jS \o\f F Y') : 'NULL'));
        Log::debug("Current date/time: " . now()->format('jS \o\f F Y \a\t H:i:s'));
        
        // Only process EFT subscriptions
        if ($this->payment_method !== PaymentMethod::Eft) {
            Log::debug("✗ Skipping forward - subscription is not EFT");
            return;
        }

        // Only forward if current period has ended
        if ($this->starts_at >= now()) {
            Log::debug("✗ Skipping forward - period hasn't started yet");
            Log::debug("  Subscription starts_at: {$this->starts_at->format('jS \o\f F Y')}");
            return;
        }

        Log::debug("✓ Period has ended - proceeding with forward");

        // Create invoice for the period
        $invoice = \Eugenefvdm\Billing\Services\InvoiceService::createSubscriptionInvoice($this);

        // Generate PDF
        \Eugenefvdm\Billing\Services\InvoiceService::createPdf($invoice);

        // Email invoice
        \Illuminate\Support\Facades\Mail::to($this->billable->email)
            ->send(new \Eugenefvdm\Billing\Mail\InvoiceCreated($invoice));

        // Move ends_at forward by one interval
        $oldEndsAt = $this->ends_at->copy();
        $this->ends_at = self::calculateEndsAt(
            $this->ends_at,
            $this->intervalFromType()
        );

        $this->save();

        Log::debug("=== SUBSCRIPTION FORWARDED ===");
        Log::debug("Subscription ID: {$this->id} was forwarded");
        Log::debug("FROM: {$oldEndsAt->format('jS \o\f F Y')}");
        Log::debug("TO: {$this->ends_at->format('jS \o\f F Y')}");
        Log::debug("New invoice created: ID {$invoice->id}");
        Log::info("Forwarded EFT subscription {$this->id}, new ends_at: {$this->ends_at}");
    }

    /**
     * Advance the subscription period by one interval when an invoice is paid.
     * 
     * This method moves the subscription forward without creating a new invoice,
     * as the invoice has already been created and is now being paid.
     * 
     * CRITICAL RULE: Only advances when the subscription period has ENDED (ends_at is in the past).
     * 
     * When you subscribe:
     * - Subscription period: Nov 1 - Dec 1
     * - Invoice created: Nov 1 - Dec 1
     * - Paying invoice: You're paying for the period you're ABOUT TO USE
     * - Do NOT advance until Dec 1 passes
     * 
     * After period ends:
     * - Subscription period: Nov 1 - Dec 1 (Dec 1 is in the past)
     * - Pay invoice: Advances to Jan 1
     * 
     * @param \Eugenefvdm\Billing\Invoice|null $invoice The invoice being paid (optional, for period matching)
     */
    public function advancePeriod(?Invoice $invoice = null): void
    {
        Log::debug("=== SUBSCRIPTION ADVANCEMENT CHECK ===");
        Log::debug("Checking if subscription ID: {$this->id} should advance");
        Log::debug("Current subscription ends_at date: " . ($this->ends_at ? $this->ends_at->format('jS \o\f F Y') : 'NULL'));
        Log::debug("Current date/time: " . now()->format('jS \o\f F Y \a\t H:i:s'));
        
        // Only process EFT subscriptions
        if ($this->payment_method !== PaymentMethod::Eft) {
            Log::debug("✗ Skipping advance - subscription is not EFT (payment_method: {$this->payment_method->value})");
            return;
        }

        // Only advance if subscription has an ends_at date
        if (!$this->ends_at) {
            Log::debug("✗ Skipping advance - subscription has no ends_at date");
            return;
        }

        // CRITICAL: Only advance if the current period has ended (ends_at is in the past)
        // This ensures we don't advance when paying for periods that haven't started yet
        if ($this->ends_at->isFuture()) {
            Log::debug("✗ Skipping advance - period hasn't ended yet");
            Log::debug("  Subscription ends_at: {$this->ends_at->format('jS \o\f F Y')}");
            Log::debug("  Current date: " . now()->format('jS \o\f F Y'));
            Log::debug("  Days until period ends: " . now()->diffInDays($this->ends_at, false));
            Log::info("Skipping advance for subscription {$this->id} - period hasn't ended yet (ends_at: {$this->ends_at})");
            return;
        }

        Log::debug("✓ Period has ended - subscription ends_at is in the past");

        // If invoice is provided, verify it matches the current subscription period
        // This prevents advancing when paying invoices for wrong periods
        if ($invoice) {
            $invoicePeriodEnd = $invoice->getPeriodEndDate();
            if ($invoicePeriodEnd) {
                Log::debug("Invoice provided - checking period match");
                Log::debug("  Invoice period ends: {$invoicePeriodEnd->format('jS \o\f F Y')}");
                Log::debug("  Subscription period ends: {$this->ends_at->format('jS \o\f F Y')}");
                
                // Check if invoice period end matches subscription's current period end
                // Allow for small differences (1 day tolerance) due to date calculations
                $daysDiff = abs($this->ends_at->diffInDays($invoicePeriodEnd));
                Log::debug("  Date difference: {$daysDiff} days");
                
                if ($daysDiff > 1) {
                    Log::debug("✗ Skipping advance - invoice period doesn't match subscription period");
                    Log::info("Skipping advance for subscription {$this->id} - invoice period end ({$invoicePeriodEnd}) doesn't match subscription period end ({$this->ends_at}), diff: {$daysDiff} days");
                    return;
                }
                
                Log::debug("✓ Invoice period matches subscription period (within tolerance)");
            } else {
                Log::debug("⚠ Invoice provided but could not determine period end date");
            }
        } else {
            Log::debug("No invoice provided - advancing based on period end only");
        }

        // Move ends_at forward by one interval
        $oldEndsAt = $this->ends_at->copy();
        $this->ends_at = self::calculateEndsAt(
            $this->ends_at,
            $this->intervalFromType()
        );

        $this->save();

        Log::debug("=== SUBSCRIPTION ADVANCED ===");
        Log::debug("Subscription ID: {$this->id} was forwarded");
        Log::debug("FROM: {$oldEndsAt->format('jS \o\f F Y')}");
        Log::debug("TO: {$this->ends_at->format('jS \o\f F Y')}");
        Log::info("Advanced EFT subscription {$this->id} period from {$oldEndsAt} to {$this->ends_at}");
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

    /**
     * Get the "paid up to" date and message for display.
     * 
     * For EFT subscriptions: Returns the latest paid invoice's period end date.
     * For Card subscriptions: Returns the next_bill_at date.
     * 
     * @return array{date: Carbon|null, message: string}
     */
    public function getPaidUpToInfo(): array
    {
        if ($this->payment_method === PaymentMethod::Eft) {
            // For EFT, find the latest paid invoice and get its period end date
            $latestPaidInvoice = $this->invoices()
                ->where('status', \Eugenefvdm\Billing\Enums\InvoiceStatus::Paid)
                ->orderByDesc('paid_at')
                ->first();
            
            if ($latestPaidInvoice) {
                $periodEnd = $latestPaidInvoice->getPeriodEndDate();
                if ($periodEnd) {
                    return [
                        'date' => $periodEnd,
                        'message' => "You are paid up to: {$periodEnd->format('jS \o\f F Y')}"
                    ];
                }
            }
            
            // Fallback: if no paid invoices, show subscription ends_at
            if ($this->ends_at) {
                return [
                    'date' => $this->ends_at,
                    'message' => "Current period ends: {$this->ends_at->format('jS \o\f F Y')}"
                ];
            }
            
            return [
                'date' => null,
                'message' => 'No payment information available'
            ];
        } else {
            // For Card subscriptions, show next payment date
            if ($this->next_bill_at) {
                return [
                    'date' => $this->next_bill_at,
                    'message' => "The next payment will go off on the {$this->next_bill_at->format('jS \o\f F Y')}."
                ];
            }
            
            return [
                'date' => null,
                'message' => 'No payment information available'
            ];
        }
    }
}
