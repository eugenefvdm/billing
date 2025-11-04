<?php

namespace Eugenefvdm\Billing\Concerns;

use Carbon\Carbon;
use Eugenefvdm\Billing\Cashier;
use Eugenefvdm\Billing\Subscription;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait ManagesSubscriptions
{
    /**
     * Get all the subscriptions for the Billable model.
     *
     * Important: Sorted by `created_at` meaning the latest subscription will always be returned.
     *
     * @return MorphMany
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Cashier::$subscriptionModel, 'billable')->orderByDesc('created_at');
    }

    public function subscription($type = 'default')
    {
        return $this->subscriptions->where('type', $type)->first();
    }

    /**
     * Determine if the Billable model is on trial.
     * 
     * We divert from the Cashier Paddle implementation by
     * looking for a plan rather than a price.
     *
     * @param  string  $type
     * @param  int|null  $plan
     * @return bool
     */
    public function onTrial($type = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->onTrial()) {
            return false;
        }

        return $plan ? $subscription->hasPlan($plan) : true;
    }

    /**
     * Determine if the Billable model's trial has ended.
     *
     * @param  string  $type
     * @param  int|null  $plan
     * @return bool
     */
    public function hasExpiredTrial($type = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->hasExpiredGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->hasExpiredTrial()) {
            return false;
        }

        return $plan ? $subscription->hasPlan($plan) : true;
    }

    /**
     * Determine if the Billable model is on a "generic" trial at the model level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        if (is_null($this->customer)) {
            return false;
        }

        return $this->customer->onGenericTrial();
    }

    /**
     * Determine if the Billable model's "generic" trial at the model level has expired.
     *
     * @return bool
     */
    public function hasExpiredGenericTrial()
    {
        if (is_null($this->customer)) {
            return false;
        }

        return $this->customer->hasExpiredGenericTrial();
    }

    /**
     * Get the ending date of the trial.
     *
     * @param string $type
     * @return \Illuminate\Support\Carbon|null
     */
    public function trialEndsAt(string $type = 'default'): ?\Illuminate\Support\Carbon
    {
        if ($subscription = $this->subscription($type)) {
            return $subscription->trial_ends_at;
        }

        return $this->customer->trial_ends_at;
    }

    /**
     * Get the ending date of the trial.
     *
     * @param  string  $type
     * @return \Illuminate\Support\Carbon|null
     */
    public function trialDaysLeft($type = 'default')
    {
        if ($subscription = $this->subscription($type)) {
            return - ($subscription->trial_ends_at->diffInDays(Carbon::now(), false));
        }

        return - ($this->customer->trial_ends_at->diffInDays(Carbon::now(), false));
    }

    /**
     * Determine if the Billable model has a given subscription.
     *
     * @param string $type
     * @param int|null $plan
     * @return bool
     */
    public function subscribed(string $type = 'default', int $plan = null): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $plan ? $subscription->hasPlan($plan) : true;
    }

    /**
     * Determine if the Billable model is actively subscribed to one of the given plans.
     *
     * @param string $plan
     * @param string $type
     * @return bool
     */
    public function subscribedToPlan(string $plan, string $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return $subscription->hasPlan($plan);
    }

    /**
     * Determine if the customer has a valid subscription on the given plan.
     *
     * @param  string  $type
     * @return bool
     */
    public function onPlan($interval)
    {
        return $this->subscriptions()
            ->where(function ($query) use ($interval) {
                // Match format "0|monthly", "1|monthly", etc.
                $query->where('type', 'LIKE', "%|{$interval}")
                    // Backwards compatibility: exact match for old format
                    ->orWhere('type', $interval);
            })
            ->get()
            ->first(fn(Subscription $subscription) => $subscription->valid()) !== null;
    }

    /**
     * Get all invoices for the Billable model.
     *
     * @return MorphMany
     */
    public function invoices(): MorphMany
    {
        return $this->morphMany(\Eugenefvdm\Billing\Invoice::class, 'billable')->orderByDesc('created_at');
    }

    /**
     * Find an invoice by UUID.
     *
     * @param  string  $uuid
     * @return \Eugenefvdm\Billing\Invoice|null
     */
    public function findInvoice(string $uuid): ?\Eugenefvdm\Billing\Invoice
    {
        return $this->invoices()->where('uuid', $uuid)->first();
    }
}
