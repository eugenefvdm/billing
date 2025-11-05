<?php

uses(\Tests\Feature\FeatureTestCase::class);

use Carbon\Carbon;
use Eugenefvdm\Billing\Subscription;

test('cannot swap while on trial', function () {
    $subscription = new Subscription(['trial_ends_at' => now()->addDay()]);

    $this->expectExceptionObject(new LogicException('Cannot swap plans while on trial.'));

    $subscription->swap(123);
});

test('customers can perform subscription checks', function () {
    $billable = $this->createBillable();

    $subscription = $billable->subscriptions()->create([
        'type' => '0|monthly',
        'provider_id' => "sub_123456789",
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    expect($billable->subscribed())->toBeTrue();
    expect($billable->subscribedToPlan('yearly'))->toBeFalse();
    expect($billable->subscribedToPlan('monthly'))->toBeTrue();
    expect($billable->onPlan('monthly'))->toBeTrue();
    expect($billable->onPlan('yearly'))->toBeFalse();
    expect($billable->onTrial())->toBeFalse();
    expect($billable->onGenericTrial())->toBeFalse();

    expect($subscription->valid())->toBeTrue();
    expect($subscription->active())->toBeTrue();
    expect($subscription->onTrial())->toBeFalse();
    expect($subscription->paused())->toBeFalse();
    expect($subscription->cancelled())->toBeFalse();
    expect($subscription->onGracePeriod())->toBeFalse();
    expect($subscription->recurring())->toBeTrue();
    expect($subscription->ended())->toBeFalse();
});

test('customers can check if they are on a generic trial', function () {
    $billable = $this->createBillable('taylor', ['trial_ends_at' => Carbon::tomorrow()]);

    expect($billable->onGenericTrial())->toBeTrue();
    expect($billable->onTrial())->toBeTrue();
    expect(Carbon::tomorrow())->toEqual($billable->trialEndsAt());
});

test('customers can check if their subscription is on trial', function () {
    $billable = $this->createBillable('taylor');

    $subscription = $billable->subscriptions()->create([
        'type' => '0|monthly',    
        'provider_id' => "sub_123456789",
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => Carbon::tomorrow(),
    ]);

    expect($billable->subscribed())->toBeTrue();
    expect($billable->subscribedToPlan('yearly'))->toBeFalse();
    expect($billable->subscribedToPlan('monthly'))->toBeTrue();
    expect($billable->onPlan('monthly'))->toBeTrue();
    expect($billable->onPlan('yearly'))->toBeFalse();
    expect($billable->onTrial())->toBeTrue();
    expect($billable->onGenericTrial())->toBeFalse();
    expect(Carbon::tomorrow())->toEqual($billable->trialEndsAt());

    expect($subscription->valid())->toBeTrue();
    expect($subscription->active())->toBeTrue();
    expect($subscription->onTrial())->toBeTrue();
    expect($subscription->paused())->toBeFalse();
    expect($subscription->cancelled())->toBeFalse();
    expect($subscription->onGracePeriod())->toBeFalse();
    expect($subscription->recurring())->toBeFalse();
    expect($subscription->ended())->toBeFalse();
});

test('customers can check if their subscription is cancelled', function () {
    $billable = $this->createBillable('taylor');

    $subscription = $billable->subscriptions()->create([
        'type' => 'main',
        'provider_id' => "sub_123456789",        
        'status' => Subscription::STATUS_CANCELED,
        'ends_at' => Carbon::tomorrow(),
    ]);

    expect($subscription->valid())->toBeTrue();
    expect($subscription->active())->toBeTrue();
    expect($subscription->onTrial())->toBeFalse();
    expect($subscription->paused())->toBeFalse();
    expect($subscription->cancelled())->toBeTrue();
    expect($subscription->onGracePeriod())->toBeTrue();
    expect($subscription->recurring())->toBeFalse();
    expect($subscription->ended())->toBeFalse();
});

test('customers can check if the grace period is over', function () {
    $billable = $this->createBillable('taylor');

    $subscription = $billable->subscriptions()->create([
        'type' => 'main',
        'provider_id' => "sub_123456789",
        'status' => Subscription::STATUS_CANCELED,
        'ends_at' => Carbon::yesterday(),
    ]);

    expect($subscription->valid())->toBeFalse();
    expect($subscription->active())->toBeFalse();
    expect($subscription->onTrial())->toBeFalse();
    expect($subscription->paused())->toBeFalse();
    expect($subscription->cancelled())->toBeTrue();
    expect($subscription->onGracePeriod())->toBeFalse();
});

test('customers can check if the subscription is paused', function () {
    $billable = $this->createBillable('taylor');

    $subscription = $billable->subscriptions()->create([
        'type' => 'main',
        'provider_id' => "sub_123456789",
        'status' => Subscription::STATUS_PAUSED,
    ]);

    expect($subscription->valid())->toBeFalse();
    expect($subscription->active())->toBeFalse();
    expect($subscription->onTrial())->toBeFalse();
    expect($subscription->paused())->toBeTrue();
    expect($subscription->cancelled())->toBeFalse();
    expect($subscription->onGracePeriod())->toBeFalse();
    expect($subscription->recurring())->toBeFalse();
    expect($subscription->ended())->toBeFalse();
});

test('subscriptions can be on a paused grace period', function () {
    $billable = $this->createBillable('taylor');

    $subscription = $billable->subscriptions()->create([
        'type' => 'main',
        'provider_id' => "sub_123456789",
        'status' => Subscription::STATUS_ACTIVE,
        'paused_at' => Carbon::tomorrow(),
    ]);

    expect($subscription->valid())->toBeTrue();
    expect($subscription->active())->toBeTrue();
    expect($subscription->onTrial())->toBeFalse();
    expect($subscription->paused())->toBeFalse();
    expect($subscription->cancelled())->toBeFalse();
    expect($subscription->onGracePeriod())->toBeFalse();
    expect($subscription->recurring())->toBeFalse();
    expect($subscription->ended())->toBeFalse();
});
