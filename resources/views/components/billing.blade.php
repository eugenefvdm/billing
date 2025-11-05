<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Billing
        </h2>
    </x-slot>

    <div>
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">

            <!-- Subscriptions -->
            <div class="mt-10 sm:mt-0">
                @livewire('subscriptions', ['mergeFields' => [
                        'name_first' => Auth()->user()->first_name ?? Auth()->user()->name,
                        'name_last' => Auth()->user()->last_name ?? Auth()->user()->name,
                        'item_description' => config('app.name') . " Subscription",
                    ]] )
            </div>
            <!-- End Subscriptions -->

            @php
                $user = Auth()->user();
                $subscription = $user->subscription();
                $hasEftSubscription = $subscription?->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Eft;
                $hasCardSubscription = $subscription?->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Card;
                $hasReceipts = $user->receipts()->exists();
                $hasEftInvoices = $user->invoices()
                    ->whereHas('subscription', fn($q) => 
                        $q->where('payment_method', \Eugenefvdm\Billing\Enums\PaymentMethod::Eft)
                    )
                    ->exists();
                $showInvoices = $hasEftSubscription || $hasEftInvoices;
                $showReceipts = !$hasEftSubscription && ($hasReceipts || $hasCardSubscription);
            @endphp

            @if($showInvoices)
                <x-billing::section-border />

                <!-- Invoices -->
                <div class="mt-10 sm:mt-0">
                    @livewire('invoices')
                </div>
                <!-- End Invoices -->
            @endif

            @if($showReceipts)
                <x-billing::section-border />

                <!-- Receipts -->
                <div class="mt-10 sm:mt-0">
                    @livewire('receipts')
                </div>
                <!-- End Receipts -->
            @endif

        </div>
    </div>
</div>
