## Payfast and EFT subscriptions billing
![GitHub release (latest by date)](https://img.shields.io/github/v/release/eugenefvdm/billing) ![Tests](https://github.com/eugenefvdm/billing/actions/workflows/tests.yml/badge.svg)
 ![GitHub](https://img.shields.io/github/license/eugenefvdm/billing)
 [![Downloads](https://img.shields.io/packagist/dt/eugenefvdm/billing.svg)](https://packagist.org/packages/eugenefvdm/billing)

A billing package for Laravel to use for EFT and credit card payment using [Payfast Onsite Payments](https://developers.payfast.co.za/docs#onsite_payments). Includes UI components for [Livewire](https://livewire.laravel.com/).

Requirements:

- PHP 8.3
- Laravel 11.x or higher
- Livewire
- Optional: If you're using Payfast, a [Payfast account](https://www.payfast.co.za/registration)
- Optional: For testing Payfast, a free [Payfast sandbox account](https://sandbox.payfast.co.za/)

## Installation

Install the package via composer:

```bash
composer require eugenefvdm/billing
```

## Config file and views

Optional: Publish the config file:
```bash
php artisan vendor:publish --provider="Eugenefvdm\Billing\BillingServiceProvider" --tag="config"
```

Optional: Publish the views:
```bash
php artisan vendor:publish --provider="Eugenefvdm\Billing\BillingServiceProvider" --tag="views"
```

Add this to `/resources/css/app.css` to have the Tailwind CSS classes compiled properly:

```CSS
@source '../../vendor/eugenefvdm/billing/resources/views/**/*.blade.php';
```

## Setup

Add the `Billable()` trait to your user model.

```php
use Eugenefvdm\Payfast\Billable;

class User extends Authenticatable
{    
    use Billable;
```

## In your head section of your HTML you'll need this snippet:

```php
@payfastScripts
```

If you're using Flux, add this to `/resources/views/partials/head.blade.php` say like below `@fluxAppearance`.

## Migrations

A migration is needed to create Customers, Orders, Receipts and Subscriptions tables:

```bash
php artisan migrate
```

## Configuration

See `config/billing.php`.

For testing you'll need:

```bash
PAYFAST_TEST_MODE=true
PAYFAST_MERCHANT_ID_TEST=
PAYFAST_MERCHANT_KEY_TEST=
PAYFAST_PASSPHRASE_TEST=
```

For live you'll need:

```bash
PAYFAST_MERCHANT_ID=
PAYFAST_MERCHANT_KEY=
PAYFAST_PASSPHRASE=
```

For localhost testing and to receive the Payfast ITN, you'll need `Ngrok` or `Expose`, and then add it's URL:

```bash
PAYFAST_TEST_MODE_ITN_URL=https://your-ngrok-url.ngrok-free.app
```

Here is an example Ngrok command to run when working on localhost:

```bash
ngrok http https://myapp.test --host-header=rewrite --domain=ngrok-supplied-url.ngrok-free.app
```

## Pricing component

To include the pricing component on a page, do this:

In your header:
```php
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

In your view:

```php
@include('billing::components.pricing')
```

## Livewire setup

### Views

#### Flux

`/resources/views/components/layouts/app/sidebar.blade.php`

```html
<flux:menu.radio.group>
    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings1') }}</flux:menu.item>
    <flux:menu.item :href="route('settings.billing')" icon="cog" wire:navigate>{{ __('Billing') }}</flux:menu.item>                        
</flux:menu.radio.group>
```

The Livewire views are modelled to blend into a [Laravel Jetstream](https://jetstream.laravel.com) user profile page.

#### Adding the subscriptions and receipts views

When calling the Livewire component, you can override any [Payfast form field](https://developers.payfast.co.za/docs#step_1_form_fields) by specifying a `mergeFields` array.

Example modification Jetstream Livewire's `resources/views/profiles/show.php`:

Replace `$user->name` with your first name and last name fields.

```php
<!-- Subscriptions -->
<div class="mt-10 sm:mt-0">    
    @livewire('subscriptions', ['mergeFields' => [
            'name_first' => $user->name,
            'name_last' => $user->name,
            'item_description' => 'Subscription to Online Service'
        ]] )        
</div>

<x-billing::section-border />
<!-- End Subscriptions -->

<!-- Receipts -->
    <div class="mt-10 sm:mt-0">
        @livewire('receipts')
    </div>

<x-billing::section-border />
<!-- End Receipts -->
```

## Usage

### Examples

- Generate a payment link
- Create an adhoc token optionally specifying the amount
- Cancel a subscription
- Update a card

```php
use Eugenefvdm\Payfast\Facades\Payfast;

Route::get('/payment', function() {
    return Payfast::payment(5,'Order #1');
});

Route::get('/cancel-subscription', function() {
    return Payfast::cancelSubscription('73d2a218-695e-4bb5-9f62-383e53bef68f');
});

Route::get('/create-subscription', function() {
    return Payfast::createSubscription(
        Carbon::now()->addDay()->format('Y-m-d'),
        5, // Amount
        6 // Frequency (6 = annual, 3 = monthly)
    );
});

Route::get('/create-adhoc-token', function() {
    return Payfast::createAdhocToken(5);
});

Route::get('/fetch-subscription', function() {
    return Payfast::fetchSubscription('21189d52-12eb-4108-9c0e-53343c7ac692');
});

Route::get('/update-card', function() {
    return Payfast::updateCardLink('40ab3194-20f0-4814-8c89-4d2a6b5462ed');
});
```

## Workflows

### How to determine when a user's subscription ends

```php
$user->subscription('default')->ends_at = [date in the past]
```

## Testing

```bash
composer test
```

If you're developing against a project, you can use the `./switch` script to switch your project in and out of the repository.

This has the same effect as adding a repository and symlinking, and reversing it again when done.

Adding a repo manually instead of using `./switch`:

```json
"repositories": [
        {
            "type": "path",
            "url": "../billing"
        }
],
```

Symlinking manually:

```bash
composer require fintechsystems/payfast-onsite-subscriptions:dev-main
```

If you want to test trials, use this one-liner to activate a billable user and a trial using say Tinker or [Tinkerwell](https://tinkerwell.app/):

```php
$user = User::find(x)->createAsCustomer(['trial_ends_at' => now()->addDays(30)]);
```

## EFT Billing

This package supports both **Card payments** (via Payfast onsite) and **EFT (bank transfer) payments** with automated invoice generation and reminders.

### How EFT Billing Works

1. **Subscription Creation**: Create an EFT subscription with a start date
2. **Forward Mechanism**: A scheduled command "forwards" the subscription by one billing period
3. **Invoice Generation**: When forwarding, an invoice is automatically created with a PDF
4. **Email Delivery**: The invoice PDF is emailed to the customer
5. **Overdue Reminders**: Automated reminders are sent at configurable intervals

### Configuration

Add these to your `.env`:

```env
# Invoice Settings
INVOICE_DEFAULT_DUE_DAYS=7
INVOICE_PDF_PATH=invoices

# Overdue Notice Intervals (days after due date)
INVOICE_FIRST_OVERDUE_NOTICE=3
INVOICE_SECOND_OVERDUE_NOTICE=6
INVOICE_THIRD_OVERDUE_NOTICE=9
```

### Scheduled Commands

Add to your `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// Forward EFT subscriptions - creates invoices and moves subscription forward
Schedule::command('subscriptions:forward')->hourly();

// Send overdue reminders for unpaid EFT invoices
Schedule::command('invoices:check-overdue')->daily();
```

**Important**: The `subscriptions:forward` command will create invoices even when there are outstanding unpaid invoices. This is by design to ensure continuous billing cycles - subscriptions continue to advance and generate invoices regardless of payment status. Only manual resubscription via the UI will check for outstanding invoices before creating a new subscription.

### Creating an EFT Subscription

```php
use Eugenefvdm\Billing\Enums\PaymentMethod;

// Create EFT subscription
$subscription = $user->subscriptions()->create([
    'name' => 'default',
    'type' => 'monthly', // or 'yearly'
    'payment_method' => PaymentMethod::Eft,
    'status' => 'ACTIVE',
    'ends_at' => now()->addMonth(),
]);

// First invoice is created immediately
// Forward command will create subsequent invoices automatically
```

### Accessing Invoices

```php
// Get all invoices for a user
$invoices = $user->invoices;

// Find invoice by UUID (for guest viewing)
$invoice = $user->findInvoice($uuid);

// Check invoice status
if ($invoice->isPaid()) {
    // Invoice is paid
}

if ($invoice->isOverdue()) {
    $daysPastDue = $invoice->days_past_due;
}
```

### Guest Invoice Viewing

Invoices can be viewed without authentication using their UUID:

```
https://yourapp.com/invoices/{uuid}
https://yourapp.com/invoices/{uuid}/download
```

These URLs are included in email notifications and are secure (UUID is hard to guess).

### Manual Invoice Operations

```php
use Eugenefvdm\Billing\Services\InvoiceService;

// Manually create an invoice for a subscription
$invoice = InvoiceService::createSubscriptionInvoice($subscription);

// Generate PDF
InvoiceService::createPdf($invoice);

// Stream PDF to browser
InvoiceService::createPdf($invoice, stream: true);

// Mark as paid
$invoice->markAsPaid();
```

### Payment Instructions

Update the payment instructions in:
- `resources/views/vendor/billing/pdf/invoice.blade.php`
- `resources/views/vendor/billing/mail/invoice-created.blade.php`
- `resources/views/vendor/billing/invoices/show.blade.php`

Replace `[Your Bank Name]`, `[Your Account Number]`, etc. with your actual banking details.

### Reminder System

The reminder system is **notification only** - it never changes subscription status automatically:

1. **First Notice** (3 days overdue by default): Friendly reminder
2. **Second Notice** (6 days overdue): Firmer tone
3. **Third Notice** (9 days overdue): Final notice

Reminders are only sent once per period. The system tracks when each reminder was sent.

### Testing EFT Flow

```php
// In Tinker or a test
$user = User::first();

// Create EFT subscription
$subscription = $user->subscriptions()->create([
    'type' => 'monthly',
    'payment_method' => \Eugenefvdm\Billing\Enums\PaymentMethod::Eft,
    'status' => 'ACTIVE',    
    'ends_at' => now()->subDay(), // Period already ended
]);

// Run forward command
php artisan subscriptions:forward

// Check that invoice was created
$user->invoices; // Should have 1 invoice

// Check that subscription moved forward
$subscription->refresh();
$subscription->ends_at; // Should be ~1 month from now
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Eugene van der Merwe](https://github.com/eugenevdm) - Package author and maintainer
- [Taylor Otwell](https://github.com/taylorotwell) - Portions of this package were derived from [Laravel Cashier](https://github.com/laravel/cashier-paddle), particularly the Billable trait, subscription management, and customer handling patterns

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

This package includes code derived from Laravel Cashier (Paddle) which is also licensed under the MIT License. Copyright (c) Taylor Otwell.
