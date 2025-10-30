<?php

namespace Eugenefvdm\Billing\Events;

use Eugenefvdm\Billing\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * The subscription instance.
     *
     * @var Subscription
     */
    public Subscription $subscription;

    /**
     * The webhook payload.
     *
     * @var array
     */
    public array $payload;

    /**
     * Create a new event instance.
     *
     * @param Subscription $subscription
     * @param  array  $payload
     * @return void
     */
    public function __construct(Subscription $subscription, array $payload)
    {
        $this->subscription = $subscription;
        $this->payload = $payload;
    }
}
