<?php

namespace Eugenefvdm\Billing\Commands;

use Eugenefvdm\Billing\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SubscriptionForwardCommand extends Command
{
    protected $signature = 'subscriptions:forward';

    protected $description = 'Forward EFT subscriptions and generate invoices';

    public function handle(): int
    {
        $subscriptions = Subscription::eft()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->get();

        $forwarded = 0;

        foreach ($subscriptions as $subscription) {
            $subscription->forward();
            $forwarded++;
        }

        Log::info("Forwarded {$forwarded} EFT subscriptions");

        return Command::SUCCESS;
    }
}

