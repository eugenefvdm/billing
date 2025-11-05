<?php

namespace Eugenefvdm\Billing\Concerns;

use Eugenefvdm\Billing\Customer;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

trait ManagesCustomer
{
    /**
     * Create a customer record for the billable model.
     *
     * @param  array  $attributes
     * @return Customer
     */
    public function createAsCustomer(array $attributes = []): Customer
    {
        return $this->customer()->create(array_merge([
            'name' => $this->name ?? null,
            'email' => $this->email ?? $this->payfastEmail(),
        ], $attributes));
    }

    /**
     * Get the customer related to the billable model.
     *
     * @return MorphOne
     */
    public function customer(): MorphOne
    {
        return $this->morphOne(Customer::class, 'billable');
    }

    /**
     * Get prices for a set of product ids for this billable model.
     *
     * @param  array|int  $products
     * @param  array  $options
     * @return Collection
     */
    // public function productPrices($products, array $options = [])
    // {
    //     $options = array_merge([
    //         'customer_country' => $this->paddleCountry(),
    //     ], $options);

    //     return Cashier::productPrices($products, $options);
    // }

    /**
     * Get the billable model's email address to associate with Payfast.
     *
     * @return string|null
     */
    public function payfastEmail(): ?string
    {
        return $this->email;
    }
        
    /**
     * Get available payment methods for this billable model.
     *
     * @return array
     */
    public function availablePaymentMethods(): array
    {
        if (!$this->customer) {
            return config('billing.default_payment_methods');
        }

        return $this->customer->getAvailablePaymentMethods();
    }

    /**
     * Determine if the billable model can use card payments.
     *
     * @return bool
     */
    public function canUseCard(): bool
    {
        if (!$this->customer) {
            return true; // Default for backward compatibility
        }

        return $this->customer->canUseCard();
    }

    /**
     * Determine if the billable model can use EFT payments.
     *
     * @return bool
     */
    public function canUseEft(): bool
    {
        if (!$this->customer) {
            return false; // Default for backward compatibility
        }

        return $this->customer->canUseEft();
    }
}
