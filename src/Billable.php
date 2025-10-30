<?php

namespace Eugenefvdm\Billing;

use Eugenefvdm\Billing\Concerns\ManagesCustomer;
use Eugenefvdm\Billing\Concerns\ManagesReceipts;
use Eugenefvdm\Billing\Concerns\ManagesSubscriptions;
use Eugenefvdm\Billing\Concerns\PerformsCharges;

trait Billable
{
    use ManagesCustomer;
    use ManagesSubscriptions;
    use ManagesReceipts;
    use PerformsCharges;

    /**
     * Get the default Payfast API options for the current Billable model.
     *
     * @param  array  $options
     * @return array
     */
    public function payfastOptions(array $options = []): array
    {
        return Cashier::payfastOptions($options);
    }
}
