<?php

uses(\Tests\Feature\FeatureTestCase::class);

use Carbon\Carbon;
use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Invoice;
use Eugenefvdm\Billing\InvoiceItem;
use Eugenefvdm\Billing\Services\InvoiceService;
use Eugenefvdm\Billing\Subscription;

it('shows the paid up to date from the latest paid invoice for an EFT subscription', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::parse('2025-12-01'),
    ]);

    // Create and pay first invoice (Nov 1 - Dec 1)
    $invoice1 = InvoiceService::createSubscriptionInvoice($subscription);
    $invoice1->update([
        'status' => InvoiceStatus::Paid,
        'paid_at' => Carbon::parse('2025-11-01'),
    ]);
    
    // Create second invoice (Dec 1 - Jan 1) but don't pay it
    $subscription->ends_at = Carbon::parse('2026-01-01');
    $subscription->save();
    $invoice2 = InvoiceService::createSubscriptionInvoice($subscription);
    
    $paidUpToInfo = $subscription->getPaidUpToInfo();
    
    // Should show paid up to Dec 1 (from paid invoice #1), not Jan 1 (from unpaid invoice #2)
    expect($paidUpToInfo['date']->format('Y-m-d'))->toBe('2025-12-01');
    expect($paidUpToInfo['message'])->toBe('You are paid up to: 1st of December 2025');
});

it('shows the paid up to date from the most recent paid invoice when multiple paid invoices exist for an EFT subscription', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::parse('2025-12-01'),
    ]);

    // Create and pay first invoice (Nov 1 - Dec 1)
    $invoice1 = InvoiceService::createSubscriptionInvoice($subscription);
    $invoice1->update([
        'status' => InvoiceStatus::Paid,
        'paid_at' => Carbon::parse('2025-11-01'),
    ]);
    
    // Advance subscription and create second invoice (Dec 1 - Jan 1)
    $subscription->ends_at = Carbon::parse('2026-01-01');
    $subscription->save();
    $invoice2 = InvoiceService::createSubscriptionInvoice($subscription);
    $invoice2->update([
        'status' => InvoiceStatus::Paid,
        'paid_at' => Carbon::parse('2025-12-01'),
    ]);
    
    // Create third invoice (Jan 1 - Feb 1) but don't pay it
    $subscription->ends_at = Carbon::parse('2026-02-01');
    $subscription->save();
    $invoice3 = InvoiceService::createSubscriptionInvoice($subscription);
    
    $paidUpToInfo = $subscription->getPaidUpToInfo();
    
    // Should show paid up to Jan 1 (from most recent paid invoice #2), not Feb 1
    expect($paidUpToInfo['date']->format('Y-m-d'))->toBe('2026-01-01');
    expect($paidUpToInfo['message'])->toBe('You are paid up to: 1st of January 2026');
});

it('shows the current period end when no invoices are paid for an EFT subscription', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::parse('2025-12-01'),
    ]);

    // Create invoice but don't pay it
    $invoice = InvoiceService::createSubscriptionInvoice($subscription);
    
    $paidUpToInfo = $subscription->getPaidUpToInfo();
    
    // Should fallback to subscription ends_at when no paid invoices
    expect($paidUpToInfo['date']->format('Y-m-d'))->toBe('2025-12-01');
    expect($paidUpToInfo['message'])->toBe('Current period ends: 1st of December 2025');
});

it('shows the next payment date for a card subscription', function () {
    $user = $this->createBillable();
    
    // Create Card subscription
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::Active,
        'next_bill_at' => Carbon::parse('2025-12-01'),
    ]);
    
    $paidUpToInfo = $subscription->getPaidUpToInfo();
    
    // Should show next payment date for Card subscriptions
    expect($paidUpToInfo['date']->format('Y-m-d'))->toBe('2025-12-01');
    expect($paidUpToInfo['message'])->toBe('The next payment will go off on the 1st of December 2025.');
});

it('uses the correct date format in the paid up to message for an EFT subscription', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::parse('2025-12-15'),
    ]);

    // Create and pay invoice
    $invoice = InvoiceService::createSubscriptionInvoice($subscription);
    $invoice->update([
        'status' => InvoiceStatus::Paid,
        'paid_at' => Carbon::parse('2025-11-15'),
    ]);
    
    $paidUpToInfo = $subscription->getPaidUpToInfo();
    
    // Should format date correctly (15th not 15st)
    expect($paidUpToInfo['message'])->toBe('You are paid up to: 15th of December 2025');
});

