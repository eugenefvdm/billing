<?php

namespace Tests\Feature;

use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Eugenefvdm\Billing\Invoice;
use Eugenefvdm\Billing\Services\InvoiceService;
use Illuminate\Support\Str;
use Tests\Fixtures\User;
use Tests\Feature\FeatureTestCase;

uses(FeatureTestCase::class);

it('correctly identifies invoice due periods', function () {
    $billable = $this->createBillable();

    $invoice = Invoice::create([
        'billable_type' => User::class,
        'billable_id' => $billable->id,
        'uuid' => Str::uuid(),
        'due_at' => now()->subDays(1)->startOfDay(),
        'status' => InvoiceStatus::Unpaid,
    ]);

    expect($invoice->in_first_reminder_period)->toBeFalse();
    expect($invoice->in_second_reminder_period)->toBeFalse();

    $invoice->update(['due_at' => now()->startOfDay()]);
    $invoice->refresh();

    expect($invoice->in_first_reminder_period)->toBeFalse();
    expect($invoice->in_second_reminder_period)->toBeFalse();

    $invoice->update(['due_at' => now()->subDays(3)->startOfDay()]);
    $invoice->refresh();

    expect($invoice->isOverdue())->toBeTrue();
    expect($invoice->in_first_reminder_period)->toBeTrue();
    expect($invoice->in_second_reminder_period)->toBeFalse();

    $invoice->update(['due_at' => now()->subDays(6)->startOfDay()]);
    $invoice->refresh();

    expect($invoice->isOverdue())->toBeTrue();
    expect($invoice->in_first_reminder_period)->toBeFalse();
    expect($invoice->in_second_reminder_period)->toBeTrue();

    $invoice->update(['due_at' => now()->subDays(9)->startOfDay()]);
    $invoice->refresh();

    expect($invoice->isOverdue())->toBeTrue();
    expect($invoice->in_first_reminder_period)->toBeFalse();
    expect($invoice->in_second_reminder_period)->toBeFalse();
    expect($invoice->in_third_reminder_period)->toBeTrue();

    $invoice->update(['due_at' => now()->subDays(10)->startOfDay()]);
    $invoice->refresh();

    expect($invoice->isOverdue())->toBeTrue();
    expect($invoice->in_first_reminder_period)->toBeFalse();
    expect($invoice->in_second_reminder_period)->toBeFalse();
    expect($invoice->in_third_reminder_period)->toBeTrue();
});

it('sends overdue reminders at the correct times', function () {
    $billable = $this->createBillable();

    $invoice = Invoice::create([
        'billable_type' => User::class,
        'billable_id' => $billable->id,
        'uuid' => Str::uuid(),
        'due_at' => now()->startOfDay(),
        'status' => InvoiceStatus::Unpaid,
    ]);

    expect(InvoiceService::checkIfOverdueReminderMustBeSent($invoice))->toBeFalse();

    $invoice->update(['due_at' => now()->subDays(3)->startOfDay()]);
    $invoice->refresh();
    expect(InvoiceService::checkIfOverdueReminderMustBeSent($invoice))->toBe('first');

    $invoice->update(['due_at' => now()->subDays(6)->startOfDay()]);
    $invoice->refresh();
    expect(InvoiceService::checkIfOverdueReminderMustBeSent($invoice))->toBe('second');

    $invoice->update(['due_at' => now()->subDays(9)->startOfDay()]);
    $invoice->refresh();
    expect(InvoiceService::checkIfOverdueReminderMustBeSent($invoice))->toBe('third');
});
