<?php

uses(\Tests\Feature\FeatureTestCase::class);

use Carbon\Carbon;
use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Subscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

it('shows receipts when user has receipts regardless of subscription payment method', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create a receipt (from previous card payment)
    $user->receipts()->create([
        'payfast_payment_id' => '12345',
        'payment_status' => 'COMPLETE',
        'item_name' => 'Test Subscription',
        'amount_gross' => '690.00',
        'amount_fee' => '0.00',
        'amount_net' => '690.00',
        'received_at' => now(),
    ]);
    
    // Create EFT subscription (switched from card)
    $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_ACTIVE,
        'ends_at' => Carbon::now()->addMonth(),
    ]);
    
    // Test the logic directly by evaluating the view's PHP block
    $subscription = $user->subscription();
    $hasEftSubscription = $subscription?->payment_method === PaymentMethod::Eft;
    $hasReceipts = $user->receipts()->exists();
    $showReceipts = $hasReceipts;
    
    // Receipts should be visible even though subscription is EFT
    expect($showReceipts)->toBeTrue();
    expect($hasReceipts)->toBeTrue();
    expect($hasEftSubscription)->toBeTrue();
});

it('shows invoices when user has EFT subscription', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create EFT subscription
    $subscription = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_ACTIVE,
        'ends_at' => Carbon::now()->addMonth(),
    ]);
    
    // Test the logic directly
    $subscription = $user->subscription();
    $hasEftSubscription = $subscription?->payment_method === PaymentMethod::Eft;
    $hasEftInvoices = $user->invoices()
        ->whereHas('subscription', fn($q) => 
            $q->where('payment_method', PaymentMethod::Eft)
        )
        ->exists();
    $showInvoices = $hasEftSubscription || $hasEftInvoices;
    
    // Invoices should be visible for EFT subscription
    expect($showInvoices)->toBeTrue();
    expect($hasEftSubscription)->toBeTrue();
});

it('shows invoices when user has EFT invoices even without active EFT subscription', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create a cancelled EFT subscription
    $subscription = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_CANCELED,
        'ends_at' => Carbon::now()->subDay(),
    ]);
    
    // Create an invoice for the EFT subscription
    $user->invoices()->create([
        'subscription_id' => $subscription->id,
        'uuid' => Str::uuid(),
        'status' => InvoiceStatus::Unpaid,
        'total' => 69000,
        'issued_at' => now(),
        'due_at' => now()->addDays(7),
        'currency' => 'ZAR',
    ]);
    
    // Test the logic directly
    $currentSubscription = $user->subscription();
    $hasEftSubscription = $currentSubscription?->payment_method === PaymentMethod::Eft;
    $hasEftInvoices = $user->invoices()
        ->whereHas('subscription', fn($q) => 
            $q->where('payment_method', PaymentMethod::Eft)
        )
        ->exists();
    $showInvoices = $hasEftSubscription || $hasEftInvoices;
    
    // Invoices should be visible because there are EFT invoices
    expect($showInvoices)->toBeTrue();
    expect($hasEftInvoices)->toBeTrue();
});

it('shows both receipts and invoices when user has receipts and EFT subscription', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create a receipt (from previous card payment)
    $user->receipts()->create([
        'payfast_payment_id' => '12345',
        'payment_status' => 'COMPLETE',
        'item_name' => 'Test Subscription',
        'amount_gross' => '690.00',
        'amount_fee' => '0.00',
        'amount_net' => '690.00',
        'received_at' => now(),
    ]);
    
    // Create EFT subscription
    $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_ACTIVE,
        'ends_at' => Carbon::now()->addMonth(),
    ]);
    
    // Test the logic directly
    $subscription = $user->subscription();
    $hasEftSubscription = $subscription?->payment_method === PaymentMethod::Eft;
    $hasReceipts = $user->receipts()->exists();
    $hasEftInvoices = $user->invoices()
        ->whereHas('subscription', fn($q) => 
            $q->where('payment_method', PaymentMethod::Eft)
        )
        ->exists();
    $showInvoices = $hasEftSubscription || $hasEftInvoices;
    $showReceipts = $hasReceipts;
    
    // Both receipts and invoices should be visible
    expect($showReceipts)->toBeTrue();
    expect($showInvoices)->toBeTrue();
});

it('does not show receipts when user has no receipts', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create Card subscription but no receipts
    $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::STATUS_ACTIVE,
    ]);
    
    // Test the logic directly
    $hasReceipts = $user->receipts()->exists();
    $showReceipts = $hasReceipts;
    
    // Receipts should not be visible when there are no receipts
    expect($showReceipts)->toBeFalse();
    expect($hasReceipts)->toBeFalse();
});

it('shows receipts when user has card subscription with receipts', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create Card subscription
    $subscription = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::STATUS_ACTIVE,
        'provider_id' => 'sub_123',
    ]);
    
    // Create a receipt for the card subscription
    $user->receipts()->create([
        'provider_id' => 'sub_123',
        'payfast_payment_id' => '12345',
        'payment_status' => 'COMPLETE',
        'item_name' => 'Test Subscription',
        'amount_gross' => '690.00',
        'amount_fee' => '0.00',
        'amount_net' => '690.00',
        'received_at' => now(),
    ]);
    
    // Test the logic directly
    $hasReceipts = $user->receipts()->exists();
    $showReceipts = $hasReceipts;
    
    // Receipts should be visible
    expect($showReceipts)->toBeTrue();
    expect($hasReceipts)->toBeTrue();
});

