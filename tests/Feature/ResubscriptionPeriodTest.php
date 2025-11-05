<?php

uses(\Tests\Feature\FeatureTestCase::class);

use Carbon\Carbon;
use Eugenefvdm\Billing\Components\Subscriptions;
use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Services\InvoiceService;
use Eugenefvdm\Billing\Subscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

test('creating EFT subscription after cancelling credit card subscription uses cancelled subscription end date', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create and cancel a credit card subscription ending in the future
    $cancelledCardSubscription = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::STATUS_CANCELED,
        'provider_id' => 'sub_123',
        'ends_at' => Carbon::now()->addDays(10), // Ends in 10 days
    ]);
    
    $expectedStartDate = $cancelledCardSubscription->ends_at->copy();
    $expectedEndDate = $expectedStartDate->copy()->addMonth();
    
    // Create EFT subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'eft';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    
    // Mock PDF generation and Mail
    $pdfMock = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdfMock->shouldReceive('save')->andReturnSelf();
    $pdfMock->shouldReceive('stream')->andReturnSelf();
    
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')
        ->andReturn($pdfMock);
    
    Mail::fake();
    
    $component->createEftSubscription();
    
    // Get the newly created EFT subscription
    $eftSubscription = $user->subscriptions()
        ->where('payment_method', PaymentMethod::Eft)
        ->where('id', '!=', $cancelledCardSubscription->id)
        ->latest()
        ->first();
    
    expect($eftSubscription)->not->toBeNull();
    expect($eftSubscription->ends_at->format('Y-m-d'))->toBe($expectedEndDate->format('Y-m-d'));
    
    // Verify invoice was created with correct period
    $invoice = $eftSubscription->invoices()->latest()->first();
    expect($invoice)->not->toBeNull();
    
    // Check invoice period matches subscription period
    $invoiceItem = $invoice->items()->first();
    expect($invoiceItem->description)->toContain($expectedStartDate->format('Y-m-d'));
    expect($invoiceItem->description)->toContain($expectedEndDate->format('Y-m-d'));
});

test('creating EFT subscription after cancelling credit card subscription uses most recent cancelled subscription', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create multiple cancelled credit card subscriptions
    $oldCancelled = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::STATUS_CANCELED,
        'provider_id' => 'sub_old',
        'ends_at' => Carbon::now()->addDays(5), // Ends in 5 days
        'created_at' => Carbon::now()->subDays(10),
    ]);
    
    $recentCancelled = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::STATUS_CANCELED,
        'provider_id' => 'sub_recent',
        'ends_at' => Carbon::now()->addDays(15), // Ends in 15 days (more recent)
        'created_at' => Carbon::now()->subDays(1),
    ]);
    
    $expectedStartDate = $recentCancelled->ends_at->copy();
    $expectedEndDate = $expectedStartDate->copy()->addMonth();
    
    // Create EFT subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'eft';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    
    // Mock PDF generation and Mail
    $pdfMock = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdfMock->shouldReceive('save')->andReturnSelf();
    $pdfMock->shouldReceive('stream')->andReturnSelf();
    
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')
        ->andReturn($pdfMock);
    
    Mail::fake();
    
    $component->createEftSubscription();
    
    // Get the newly created EFT subscription
    $eftSubscription = $user->subscriptions()
        ->where('payment_method', PaymentMethod::Eft)
        ->latest()
        ->first();
    
    expect($eftSubscription)->not->toBeNull();
    // Should use the most recent cancelled subscription's end date (15 days, not 5 days)
    expect($eftSubscription->ends_at->format('Y-m-d'))->toBe($expectedEndDate->format('Y-m-d'));
});

test('creating credit card subscription after cancelling EFT subscription uses cancelled subscription end date', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create and cancel an EFT subscription ending in the future
    $cancelledEftSubscription = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_CANCELED,
        'ends_at' => Carbon::now()->addDays(10), // Ends in 10 days
    ]);
    
    $expectedBillingDate = $cancelledEftSubscription->ends_at->copy()->addDay();
    
    // Mock Payfast facade to capture the billing date
    $billingDateCaptured = null;
    \Eugenefvdm\Billing\Facades\Payfast::shouldReceive('createOnsitePayment')
        ->once()
        ->andReturnUsing(function ($type, $billingDate, $mergeFields) use (&$billingDateCaptured) {
            $billingDateCaptured = $billingDate;
            return 'test_identifier_123';
        });
    
    // Create credit card subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'card';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    $component->displayCreateSubscription();
    
    // Verify billing date was set correctly
    expect($billingDateCaptured)->not->toBeNull();
    expect($billingDateCaptured)->toBe($expectedBillingDate->format('Y-m-d'));
});

test('creating credit card subscription after cancelling EFT subscription uses most recent cancelled subscription', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create multiple cancelled EFT subscriptions
    $oldCancelled = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_CANCELED,
        'ends_at' => Carbon::now()->addDays(5), // Ends in 5 days
        'created_at' => Carbon::now()->subDays(10),
    ]);
    
    $recentCancelled = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_CANCELED,
        'ends_at' => Carbon::now()->addDays(15), // Ends in 15 days (more recent)
        'created_at' => Carbon::now()->subDays(1),
    ]);
    
    $expectedBillingDate = $recentCancelled->ends_at->copy()->addDay();
    
    // Mock Payfast facade to capture the billing date
    $billingDateCaptured = null;
    \Eugenefvdm\Billing\Facades\Payfast::shouldReceive('createOnsitePayment')
        ->once()
        ->andReturnUsing(function ($type, $billingDate, $mergeFields) use (&$billingDateCaptured) {
            $billingDateCaptured = $billingDate;
            return 'test_identifier_123';
        });
    
    // Create credit card subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'card';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    $component->displayCreateSubscription();
    
    // Should use the most recent cancelled subscription's end date (15 days, not 5 days)
    expect($billingDateCaptured)->not->toBeNull();
    expect($billingDateCaptured)->toBe($expectedBillingDate->format('Y-m-d'));
});

test('creating EFT subscription without cancelled subscription starts from now', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // No cancelled subscriptions exist
    
    $now = Carbon::now();
    $expectedEndDate = $now->copy()->addMonth();
    
    // Create EFT subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'eft';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    
    // Mock PDF generation and Mail
    $pdfMock = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdfMock->shouldReceive('save')->andReturnSelf();
    $pdfMock->shouldReceive('stream')->andReturnSelf();
    
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')
        ->andReturn($pdfMock);
    
    Mail::fake();
    
    $component->createEftSubscription();
    
    // Get the newly created EFT subscription
    $eftSubscription = $user->subscriptions()
        ->where('payment_method', PaymentMethod::Eft)
        ->latest()
        ->first();
    
    expect($eftSubscription)->not->toBeNull();
    // Should start from now (within 1 second tolerance)
    $difference = abs($eftSubscription->ends_at->diffInSeconds($expectedEndDate));
    expect($difference)->toBeLessThan(2);
});

test('creating credit card subscription without cancelled subscription uses current date', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // No cancelled subscriptions exist
    
    $expectedBillingDate = Carbon::now()->format('Y-m-d');
    
    // Mock Payfast facade to capture the billing date
    $billingDateCaptured = null;
    \Eugenefvdm\Billing\Facades\Payfast::shouldReceive('createOnsitePayment')
        ->once()
        ->andReturnUsing(function ($type, $billingDate, $mergeFields) use (&$billingDateCaptured) {
            $billingDateCaptured = $billingDate;
            return 'test_identifier_123';
        });
    
    // Create credit card subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'card';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    $component->displayCreateSubscription();
    
    // Should use current date
    expect($billingDateCaptured)->not->toBeNull();
    expect($billingDateCaptured)->toBe($expectedBillingDate);
});

test('creating EFT subscription ignores cancelled subscription with past end date', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create a cancelled credit card subscription that already ended
    $cancelledCardSubscription = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Card,
        'status' => Subscription::STATUS_CANCELED,
        'provider_id' => 'sub_123',
        'ends_at' => Carbon::now()->subDays(5), // Ended 5 days ago
    ]);
    
    $now = Carbon::now();
    $expectedEndDate = $now->copy()->addMonth();
    
    // Create EFT subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'eft';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    
    // Mock PDF generation and Mail
    $pdfMock = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdfMock->shouldReceive('save')->andReturnSelf();
    $pdfMock->shouldReceive('stream')->andReturnSelf();
    
    \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')
        ->andReturn($pdfMock);
    
    Mail::fake();
    
    $component->createEftSubscription();
    
    // Get the newly created EFT subscription
    $eftSubscription = $user->subscriptions()
        ->where('payment_method', PaymentMethod::Eft)
        ->latest()
        ->first();
    
    expect($eftSubscription)->not->toBeNull();
    // Should start from now, not from the past cancelled subscription
    $difference = abs($eftSubscription->ends_at->diffInSeconds($expectedEndDate));
    expect($difference)->toBeLessThan(2);
});

test('creating credit card subscription ignores cancelled subscription with past end date', function () {
    $user = $this->createBillable();
    Auth::login($user);
    
    // Create a cancelled EFT subscription that already ended
    $cancelledEftSubscription = $user->subscriptions()->create([
        'type' => '0|monthly',
        'payment_method' => PaymentMethod::Eft,
        'status' => Subscription::STATUS_CANCELED,
        'ends_at' => Carbon::now()->subDays(5), // Ended 5 days ago
    ]);
    
    $expectedBillingDate = Carbon::now()->format('Y-m-d');
    
    // Mock Payfast facade to capture the billing date
    $billingDateCaptured = null;
    \Eugenefvdm\Billing\Facades\Payfast::shouldReceive('createOnsitePayment')
        ->once()
        ->andReturnUsing(function ($type, $billingDate, $mergeFields) use (&$billingDateCaptured) {
            $billingDateCaptured = $billingDate;
            return 'test_identifier_123';
        });
    
    // Create credit card subscription by directly calling the method
    $component = new Subscriptions();
    $component->user = $user;
    $component->paymentMethod = 'card';
    $component->type = '0|monthly';
    $component->mergeFields = [];
    $component->displayCreateSubscription();
    
    // Should use current date, not the past cancelled subscription
    expect($billingDateCaptured)->not->toBeNull();
    expect($billingDateCaptured)->toBe($expectedBillingDate);
});

