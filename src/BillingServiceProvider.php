<?php

namespace Eugenefvdm\Billing;

use Eugenefvdm\Billing\Components\Banner;
use Eugenefvdm\Billing\Components\Billing;
use Eugenefvdm\Billing\Components\Invoices;
use Eugenefvdm\Billing\Components\Receipts;
use Eugenefvdm\Billing\Components\Subscriptions;
use Illuminate\Support\Facades\Blade;
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
            __DIR__ . '/../resources/views' => resource_path('views/vendor/payfast'),
        ], 'views');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'payfast');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'billing');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Livewire::component('subscriptions', Subscriptions::class);

        Livewire::component('receipts', Receipts::class);

        Livewire::component('invoices', Invoices::class);

        Livewire::component('banner', Banner::class);

        Livewire::component('billing', Billing::class);

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

                'return_url' => config('billing.payfast.return_url'),
                'cancel_url' => config('billing.payfast.cancel_url'),
                'notify_url' => config('billing.payfast.notify_url'),
            ]);
        });
    }
}

