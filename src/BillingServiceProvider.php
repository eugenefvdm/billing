<?php

namespace Eugenefvdm\Billing;

use Eugenefvdm\Billing\Components\Banner;
use Eugenefvdm\Billing\Components\Billing;
use Eugenefvdm\Billing\Components\Invoices;
use Eugenefvdm\Billing\Components\Receipts;
use Eugenefvdm\Billing\Components\Subscriptions;
use Eugenefvdm\Billing\Events\InvoicePaid;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/billing.php' => config_path('billing.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/billing'),
        ], 'views');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'billing');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Livewire::component('subscriptions', Subscriptions::class);

        Livewire::component('receipts', Receipts::class);

        Livewire::component('invoices', Invoices::class);

        Livewire::component('banner', Banner::class);

        Livewire::component('billing', Billing::class);

        // Listen for InvoicePaid events and forward subscription period
        Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
            $invoice = $event->invoice;

            Log::debug("=== INVOICE PAID EVENT LISTENER ===");
            Log::debug("InvoicePaid event received for invoice ID: {$invoice->id}");
            Log::debug("Invoice UUID: {$invoice->uuid}");
            Log::debug("Invoice subscription_id: " . ($invoice->subscription_id ?? 'NULL'));

            // Forward the subscription period if the invoice belongs to a subscription
            if ($invoice->subscription) {
                Log::debug("Invoice belongs to subscription ID: {$invoice->subscription->id}");
                Log::debug("Calling advancePeriod() on subscription...");
                $invoice->subscription->advancePeriod($invoice);
            } else {
                Log::debug("⚠ Invoice does not belong to a subscription - skipping advancement");
            }

            // Only dispatch Livewire events in web context (not console/tinker)
            if (app()->runningInConsole()) {
                return;
            }

            // Only dispatch if we have a valid HTTP request
            if (!request() || !request()->wantsJson()) {
                // Use session flash to trigger JavaScript event dispatch
                session()->flash('livewire_dispatch', [
                    'refreshComponent',
                    'billingUpdated'
                ]);
            }
        });

        Blade::directive('payfastScripts', function () {
            return "<?php if (config('billing.payfast.test_mode')): ?>
    <!-- Payfast Test Mode -->
    <script src=\"https://sandbox.payfast.co.za/onsite/engine.js\" defer></script>
<?php else: ?>
    <script src=\"https://www.payfast.co.za/onsite/engine.js\" defer></script>
<?php endif; ?>

<?php echo \$__env->yieldPushContent('payfast-event-listener'); ?>";
        });

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\SubscriptionForwardCommand::class,
                Commands\CheckOverdueInvoicesCommand::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/billing.php',
            'billing'
        );

        $this->app->bind('payfast', function () {
            return new Payfast([
                'merchant_id' => config('billing.payfast.merchant_id'),
                'merchant_key' => config('billing.payfast.merchant_key'),
                'passphrase' => config('billing.payfast.passphrase'),

                'test_mode' => config('billing.payfast.test_mode'),

                'merchant_id_test' => config('billing.payfast.merchant_id_test'),
                'merchant_key_test' => config('billing.payfast.merchant_key_test'),
                'passphrase_test' => config('billing.payfast.passphrase_test'),
            ]);
        });
    }
}

