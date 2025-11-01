<?php

namespace Eugenefvdm\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Eugenefvdm\Billing\Billable $billable
 */
class Customer extends Model
{
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
        'available_payment_methods' => 'array',
    ];

    /**
     * Get the billable model related to the customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function billable()
    {
        return $this->morphTo();
    }

    /**
     * Determine if the Payfast model is on a "generic" trial at the model level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the Payfast model has an expired "generic" trial at the model level.
     *
     * @return bool
     */
    public function hasExpiredGenericTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Get available payment methods for this customer.
     *
     * @return array
     */
    public function getAvailablePaymentMethods(): array
    {
        return $this->available_payment_methods ?? config('billing.default_payment_methods');
    }

    /**
     * Determine if the customer can use card payments.
     *
     * @return bool
     */
    public function canUseCard(): bool
    {
        return in_array('card', $this->getAvailablePaymentMethods());
    }

    /**
     * Determine if the customer can use EFT payments.
     *
     * @return bool
     */
    public function canUseEft(): bool
    {
        return in_array('eft', $this->getAvailablePaymentMethods());
    }
}
