<?php

uses(\Tests\Feature\FeatureTestCase::class);

use Carbon\Carbon;
use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Events\InvoicePaid;
use Eugenefvdm\Billing\Invoice;
use Eugenefvdm\Billing\InvoiceItem;
use Eugenefvdm\Billing\Services\InvoiceService;
use Eugenefvdm\Billing\Subscription;
use Illuminate\Support\Facades\Event;

test('advancing subscription period when paying invoice for current period that has ended', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription with period that ended yesterday
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::yesterday(),
    ]);

    // Create invoice for the period that just ended
    $invoice = InvoiceService::createSubscriptionInvoice($subscription);
    
    // Verify invoice was created with correct period
    expect($invoice->subscription_id)->toBe($subscription->id);
    
    $oldEndsAt = $subscription->ends_at->copy();
    
    // Mark invoice as paid - should advance subscription
    $invoice->markAsPaid();
    
    // Refresh subscription
    $subscription->refresh();
    
    // Subscription should have advanced by one month
    expect($subscription->ends_at->format('Y-m-d'))
        ->toBe($oldEndsAt->addMonth()->format('Y-m-d'));
});

test('not advancing subscription when paying invoice for future period', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription with period ending in the future
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::now()->addMonth(),
    ]);

    // Manually create invoice for a future period (Dec to Jan)
    $futureEnd = Carbon::now()->addMonths(2);
    $futureStart = $futureEnd->copy()->subMonth();
    
    $invoice = $user->invoices()->create([
        'subscription_id' => $subscription->id,
        'uuid' => \Illuminate\Support\Str::uuid(),
        'status' => InvoiceStatus::Unpaid,
        'issued_at' => now(),
        'due_at' => now()->addDays(7),
        'currency' => 'ZAR',
    ]);
    
    // Add invoice item with future period description
    $invoice->items()->create([
        'description' => "Startup Plan {$futureStart->format('Y-m-d')} to {$futureEnd->format('Y-m-d')}",
        'quantity' => 1,
        'unit_price' => 69000,
    ]);
    
    $originalEndsAt = $subscription->ends_at->copy();
    
    // Mark invoice as paid - should NOT advance subscription
    $invoice->markAsPaid();
    
    // Refresh subscription
    $subscription->refresh();
    
    // Subscription should NOT have advanced
    expect($subscription->ends_at->format('Y-m-d'))
        ->toBe($originalEndsAt->format('Y-m-d'));
});

test('not advancing subscription when paying invoice for current period that has not ended yet', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription with period ending in the future
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::now()->addDays(10), // Period hasn't ended yet
    ]);

    // Create invoice for current period (matches subscription period)
    $invoice = InvoiceService::createSubscriptionInvoice($subscription);
    
    $originalEndsAt = $subscription->ends_at->copy();
    
    // Mark invoice as paid - should NOT advance subscription (period hasn't ended)
    $invoice->markAsPaid();
    
    // Refresh subscription
    $subscription->refresh();
    
    // Subscription should NOT have advanced (period hasn't ended)
    expect($subscription->ends_at->format('Y-m-d'))
        ->toBe($originalEndsAt->format('Y-m-d'));
});

test('not advancing subscription when current period has not ended and invoice does not match', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription with period ending in the future
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::now()->addDays(10), // Period hasn't ended yet
    ]);

    // Create invoice for a different period (doesn't match)
    $invoice = $user->invoices()->create([
        'subscription_id' => $subscription->id,
        'uuid' => \Illuminate\Support\Str::uuid(),
        'status' => InvoiceStatus::Unpaid,
        'issued_at' => now(),
        'due_at' => now()->addDays(7),
        'currency' => 'ZAR',
    ]);
    
    // Add invoice item with period that doesn't match subscription
    $invoice->items()->create([
        'description' => "Startup Plan 2024-01-01 to 2024-02-01", // Different period
        'quantity' => 1,
        'unit_price' => 69000,
    ]);
    
    $originalEndsAt = $subscription->ends_at->copy();
    
    // Mark invoice as paid - should NOT advance subscription
    $invoice->markAsPaid();
    
    // Refresh subscription
    $subscription->refresh();
    
    // Subscription should NOT have advanced (period hasn't ended AND invoice doesn't match)
    expect($subscription->ends_at->format('Y-m-d'))
        ->toBe($originalEndsAt->format('Y-m-d'));
});

test('not advancing subscription when invoice period does not match subscription period', function () {
    $user = $this->createBillable();
    
    // Create EFT subscription with period ending Nov 1 (in the past)
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => Carbon::parse('2025-11-01'),
    ]);

    // Manually create invoice for a completely different period (Oct to Nov)
    // But the subscription period is Nov 1, and invoice period ends Nov 1, so they match!
    // We need an invoice that ends at a different date
    $invoice = $user->invoices()->create([
        'subscription_id' => $subscription->id,
        'uuid' => \Illuminate\Support\Str::uuid(),
        'status' => InvoiceStatus::Unpaid,
        'issued_at' => now(),
        'due_at' => now()->addDays(7),
        'currency' => 'ZAR',
    ]);
    
    // Add invoice item with period that doesn't match subscription
    // Invoice ends Oct 15, but subscription ends Nov 1 - they don't match
    $invoice->items()->create([
        'description' => "Startup Plan 2025-09-15 to 2025-10-15", // Different period end
        'quantity' => 1,
        'unit_price' => 69000,
    ]);
    
    // Mark invoice as paid - should NOT advance subscription (period mismatch)
    $invoice->markAsPaid();
    
    // Refresh subscription
    $subscription->refresh();
    
    // Subscription should NOT have advanced (period doesn't match)
    expect($subscription->ends_at->format('Y-m-d'))
        ->toBe('2025-11-01');
});

test('not advancing non-EFT subscriptions', function () {
    $user = $this->createBillable();
    
    // Create Card subscription
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::Active,
        'ends_at' => Carbon::yesterday(),
    ]);

    $invoice = InvoiceService::createSubscriptionInvoice($subscription);
    
    $originalEndsAt = $subscription->ends_at->copy();
    
    // Mark invoice as paid - should NOT advance subscription (not EFT)
    $invoice->markAsPaid();
    
    // Refresh subscription
    $subscription->refresh();
    
    // Subscription should NOT have advanced (not EFT)
    expect($subscription->ends_at->format('Y-m-d'))
        ->toBe($originalEndsAt->format('Y-m-d'));
});

test('advancing multiple times when paying invoices in sequence', function () {
    $user = $this->createBillable();
    
    // Use a fixed past date to ensure it's always in the past
    $pastDate = Carbon::parse('2024-01-01');
    
    // Create EFT subscription with period ending in the past
    $subscription = $user->subscriptions()->create([
        'name' => 'default',
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::Active,
        'ends_at' => $pastDate->copy(),
    ]);

    // Create and pay first invoice
    // This will create invoice for period ending at $pastDate, matching subscription
    $invoice1 = InvoiceService::createSubscriptionInvoice($subscription);
    $invoice1->markAsPaid();
    
    $subscription->refresh();
    // Should advance by one month from Jan 1 to Feb 1
    $expectedDate1 = $pastDate->copy()->addMonth();
    expect($subscription->ends_at->format('Y-m-d'))->toBe($expectedDate1->format('Y-m-d'));
    
    // Set period to past again (Feb 1 minus 1 day = Jan 31)
    $subscription->update(['ends_at' => $expectedDate1->copy()->subDay()]);
    
    // Create and pay second invoice
    // This will create invoice for period ending Feb 1, matching subscription's Jan 31 (within tolerance)
    $invoice2 = InvoiceService::createSubscriptionInvoice($subscription);
    $invoice2->markAsPaid();
    
    $subscription->refresh();
    // Should advance from Jan 31 to Feb 29 (one month, accounting for February)
    $expectedDate2 = $expectedDate1->copy()->subDay()->addMonth();
    expect($subscription->ends_at->format('Y-m-d'))->toBe($expectedDate2->format('Y-m-d'));
});

test('invoice getPeriodEndDate extracts end date correctly', function () {
    $user = $this->createBillable();
    
    $invoice = $user->invoices()->create([
        'uuid' => \Illuminate\Support\Str::uuid(),
        'status' => InvoiceStatus::Unpaid,
        'issued_at' => now(),
        'due_at' => now()->addDays(7),
        'currency' => 'ZAR',
    ]);
    
    $invoice->items()->create([
        'description' => 'Startup Plan 2025-11-01 to 2025-12-01',
        'quantity' => 1,
        'unit_price' => 69000,
    ]);
    
    $periodEnd = $invoice->getPeriodEndDate();
    
    expect($periodEnd)->not->toBeNull();
    expect($periodEnd->format('Y-m-d'))->toBe('2025-12-01');
});

test('invoice getPeriodEndDate returns null for invalid description', function () {
    $user = $this->createBillable();
    
    $invoice = $user->invoices()->create([
        'uuid' => \Illuminate\Support\Str::uuid(),
        'status' => InvoiceStatus::Unpaid,
        'issued_at' => now(),
        'due_at' => now()->addDays(7),
        'currency' => 'ZAR',
    ]);
    
    $invoice->items()->create([
        'description' => 'Invalid description format',
        'quantity' => 1,
        'unit_price' => 69000,
    ]);
    
    $periodEnd = $invoice->getPeriodEndDate();
    
    expect($periodEnd)->toBeNull();
});

