<?php

namespace Eugenefvdm\Billing\Concerns;

use Eugenefvdm\Billing\Cashier;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait ManagesReceipts
{
    /**
     * Get all the receipts for the Billable model.
     *
     * @return MorphMany
     */
    public function receipts(): MorphMany
    {
        return $this->morphMany(Cashier::$receiptModel, 'billable')->orderByDesc('created_at');
    }
}
