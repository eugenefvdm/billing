## About Payfast Onsite Subscriptions
![GitHub release (latest by date)](https://img.shields.io/github/v/release/fintech-systems/payfast-onsite-subscriptions) ![Tests](https://github.com/fintech-systems/payfast-onsite-subscriptions/actions/workflows/tests.yml/badge.svg)
 ![GitHub](https://img.shields.io/github/license/fintech-systems/payfast-onsite-subscriptions)
 [![Downloads](https://img.shields.io/packagist/dt/fintechsystems/payfast-onsite-subscriptions.svg)](https://packagist.org/packages/fintechsystems/payfast-onsite-subscriptions)

A [Payfast Onsite Payments](https://developers.payfast.co.za/docs#onsite_payments) implementation for Laravel designed to ease subscription billing. [Livewire](https://laravel-livewire.com/) views are included.

Requirements:

- PHP 8.3
- Laravel 11.x or higher
- A [Payfast Sandbox account](https://sandbox.payfast.co.za/)
- A [Payfast account](https://www.payfast.co.za/registration)
- Sanctum

## Installation

Install the package via composer:

```bash
composer require fintechsystems/payfast-onsite-subscriptions
```

If you don't have Sanctum already:

```bash
php artisan install:api
```

## Publish Configuration and Views

Publish the config file with:
```bash
php artisan vendor:publish --provider="Eugenefvdm\Billing\BillingServiceProvider" --tag="config"
```

Publish the Success and Cancelled views and the Livewire components for subscriptions and receipts.
```bash
php artisan vendor:publish --provider="Eugenefvdm\Billing\BillingServiceProvider" --tag="views"
```

These files are:
```bash
banner.blade.php
billing.blade.php
cancel.blade.php
pricing.blade.php
receipts.blade.php
subscriptions.blade.php
success.blade.php
```

## Setup

Add the `Billable()` trait to your user model.

```php
use Eugenefvdm\Payfast\Billable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use Billable;
```

## In your header

```php
@if (config('billing.payfast.test_mode'))
    <!-- Payfast Test Mode -->
    <script src="https://sandbox.payfast.co.za/onsite/engine.js" defer></script>
@else
    <script src="https://www.payfast.co.za/onsite/engine.js" defer></script>
@endif

@stack('payfast-event-listener')
```

To include the pricing component on a page, do this:

In your header:
```php
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

In your view:

```php
@include('payfast::components.pricing')
```

## Migrations

A migration is needed to create Customers, Orders, Receipts and Subscriptions tables:

```bash
php artisan migrate
```

## Configuration

See `config/billing.php`.

The top part of `billing.php` is based on Laravel Spark's config.

The second part of `billing.php` is the Payfast specific code.

## Livewire setup

### Views

The Livewire views are modelled to blend into a [Laravel Jetstream](https://jetstream.laravel.com) user profile page.

#### Adding a billing menu

In `app.blade.php` below in the Account Management sections (e.g., below profile):

```html
<x-dropdown-link href="/user/billing">
    Billing
</x-dropdown-link>
```

Also look for the responsive part and add this:

```html
<x-responsive-nav-link href="/user/billing" :active="request()->routeIs('profile.billing')">
    Billing
</x-responsive-nav-link>
```

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

<x-payfast::section-border />
<!-- End Subscriptions -->

<!-- Receipts -->
    <div class="mt-10 sm:mt-0">
        @livewire('receipts')
    </div>

<x-payfast::section-border />
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

## Testing

### How to determine when a user's subscription ends

$user->subscription('default')->ends_at = [date in the past]

```bash
composer test
```

In your main project, add this:

```
"repositories": [
        {
            "type": "path",
            "url": "../payfast-onsite-subscriptions"
        }
],
```

Then do this to symlink the library:

```
composer require fintechsystems/payfast-onsite-subscriptions:dev-main
```

If you want to test trials, use this one-liner to activate a billable user and a trial using Tinker:

```php
$user = User::find(x)->createAsCustomer(['trial_ends_at' => now()->addDays(30)]);
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Eugene van der Merwe](https://github.com/eugenevdm) - Package author and maintainer
- [Taylor Otwell](https://github.com/taylorotwell) - Portions of this package were derived from [Laravel Cashier](https://github.com/laravel/cashier-paddle), particularly the Billable trait, subscription management, and customer handling patterns

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

This package includes code derived from Laravel Cashier (Paddle) which is also licensed under the MIT License. Copyright (c) Taylor Otwell.
