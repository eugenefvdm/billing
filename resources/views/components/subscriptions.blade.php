<x-billing::action-section>
    <x-slot name="title">
        {{ __('Subscription Information') }}
    </x-slot>

    <x-slot name="description">
        {{ __('View/Update subscription information.') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">

            <!-- Check if the current logged in user is subscribed to a plan -->
            @if (!$user->subscribed('default'))
                {{-- Trial --}}
                @if ($user->onGenericTrial())
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        You are currently on trial till the {{ $user->trialEndsAt()->format('jS \o\f F Y') }}
                    </h3>
                    <div class="mt-3 max-w-xl text-sm text-gray-600 dark:text-gray-400">
                        <p>
                            If you subscribe now next payment will be due on the
                            {{ $this->afterTrialNextDueDate }}
                        </p>
                    </div>
                @elseif($user->hasExpiredGenericTrial())
                    {{-- Expired Generic Trial --}}
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Your trial has expired.
                    </h3>
                    <div class="mt-3 max-w-xl text-sm text-gray-600 dark:text-gray-400">
                        <p>
                            {{ __('Please select from our plans below:') }}
                        </p>
                    </div>
                @else
                    {{-- No Subscription --}}
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        You are not currently subscribed to a plan.
                    </h3>
                    <div class="mt-3 max-w-xl text-sm text-gray-600 dark:text-gray-400">
                        <p>
                            {{ __('Please select from our plans below:') }}
                        </p>
                    </div>
                @endif
            @else
                @php
                    $subscription = $user->subscription('default');
                    $isEft = $subscription->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Eft;
                    $isCancelled = !is_null($subscription->cancelled_at);
                    $isOnGracePeriod = $isCancelled && $subscription->onGracePeriod();
                @endphp
                @if ($isOnGracePeriod)
                    {{-- Grace period --}}
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Your subscription was cancelled
                        @if($subscription->cancelled_at)
                            {{ $subscription->cancelled_at->format('j F Y \a\t H:i:s') }}
                        @endif
                        .
                    </h3>
                    <div class="mt-3 max-w-xl text-sm text-gray-600 dark:text-gray-400">
                        @if (\Carbon\Carbon::now()->diffInDays($user->subscriptions()->active()->first()->ends_at->format('Y-m-d')) != 0)
                            <p>
                                There are
                                {{ (int) \Carbon\Carbon::now()->diffInDays($subscription->ends_at) }}
                                days left of your subscription and the last day is the
                                {{ $subscription->ends_at->format('jS \o\f F Y') }}.
                            </p>
                        @else
                            <p>
                                Today is the last day of your subscription.
                            </p>
                        @endif
                    </div>
                @else
                    {{-- Subscribed --}}
                    @php
                        $planName = $subscription->planName();
                        // Parse interval from type field (format: "0|monthly" or "1|yearly")
                        $interval = strpos($subscription->type, '|') !== false 
                            ? ucfirst(explode('|', $subscription->type)[1])
                            : ucfirst($subscription->type);
                    @endphp
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        You are subscribed to the {{ $planName }} {{ $interval }} plan
                        @if($isEft)
                            via EFT
                        @endif
                        .
                    </h3>
                    <div class="mt-3 max-w-xl text-sm text-gray-600 dark:text-gray-400">
                        @if($isEft)
                            <p>
                                Current period ends: {{ $subscription->ends_at->format('jS \o\f F Y') }}
                            </p>
                        @else
                            <p>
                                The next payment will go off on the
                                {{ $subscription->next_bill_at->format('jS \o\f F Y') }}.
                            </p>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        <!-- Subscription Action Buttons -->
        <div class="mt-5">
            @php
                $subscription = $user->subscription('default');
                $hasSubscription = $subscription !== null;
                $isOnGracePeriod = $hasSubscription && !is_null($subscription->cancelled_at) && $subscription->onGracePeriod();
                $isActiveSubscription = $hasSubscription && !$isOnGracePeriod && !$subscription->ended();
            @endphp
            @if ($isActiveSubscription)
                @php
                    $isEft = $subscription->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Eft;
                @endphp
                @if(!$isEft)
                    <x-billing::secondary-button wire:click="updateCard">
                        {{ __('Update Card Information') }}
                    </x-billing::secondary-button>
                @endif

                <x-billing::secondary-button wire:click="confirmCancelSubscription" wire:loading.attr="disabled">
                    {{ __('Cancel Subscription') }}
                </x-billing::secondary-button>
            @else
                @php
                    ray($user)->green();
                    $availableMethods = $user->availablePaymentMethods();
                    ray($availableMethods)->green();
                    $hasMultipleMethods = count($availableMethods) > 1;
                @endphp
                <div class="flex flex-col gap-3">
                    @if($hasMultipleMethods)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                {{ __('Payment Method') }}
                            </label>
                            <div class="flex gap-4">
                                @if(in_array('card', $availableMethods))
                                    <label class="flex items-center">
                                        <input type="radio" wire:model="paymentMethod" value="card" class="mr-2">
                                        <span>{{ __('Credit Card') }}</span>
                                    </label>
                                @endif
                                @if(in_array('eft', $availableMethods))
                                    <label class="flex items-center">
                                        <input type="radio" wire:model="paymentMethod" value="eft" class="mr-2">
                                        <span>{{ __('EFT') }}</span>
                                    </label>
                                @endif
                            </div>
                        </div>
                    @endif
                    <div class="flex">
                        <select wire:model="type" name="type"
                            class="mt-1 block pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600">
                            @foreach (config('billing.billables.user.plans') as $index => $plan)
                                <option value="{{ $index }}|monthly">{{ $plan['name'] }} Monthly -
                                    {{ config('billing.billables.user.currency_prefix') }}{{ number_format($plan['monthly']['recurring_amount'] / 100, 2) }}
                                </option>
                                <option value="{{ $index }}|yearly">{{ $plan['name'] }} Yearly -
                                    {{ config('billing.billables.user.currency_prefix') }}{{ number_format($plan['yearly']['recurring_amount'] / 100, 2) }}
                                </option>
                            @endforeach
                        </select>

                        {{-- This is the main button that gets clicked to subscribe to a plan. It calls displayCreateSubscription(). --}}
                        <x-billing::secondary-button class="ml-2 align-middle h-9 mt-2"
                            wire:click="displayCreateSubscription">
                            @if ($isOnGracePeriod)
                                {{ __('Resubscribe') }}
                            @else
                                {{ __('Subscribe') }}
                            @endif
                        </x-billing::secondary-button>

                        <div wire:loading class="ml-2 align-middle mt-3 text-gray-600 dark:text-gray-400">
                            Please wait...
                        </div>
                    </div>
                </div>
            @endif
        </div>
        <!-- End Subscription Action Buttons -->

        <!-- Launch Payfast Subscription Modal -->
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('launchPayfast', ({
                    identifier
                }) => {
                    console.log('Launching Payfast onsite payment modal');
                    console.log('identifier: ' + identifier)
                    window.payfast_do_onsite_payment({
                        uuid: identifier
                    });
                    window.addEventListener("message", refreshComponent);
                });
            });
        </script>

        @push('payfast-event-listener')
            <script>
                const refreshComponent = () => {
                    console.log('Refreshing subscription status by emitting a billingUpdated event')

                    window.Livewire.dispatch('billingUpdated')
                }
            </script>
        @endpush

        <!-- Start Cancel Subscription Confirmation Modal -->
        <x-billing::dialog-modal wire:model="confirmingCancelSubscription">

            <x-slot name="title">
                {{ __('Cancel Subscription') }}
            </x-slot>

            <x-slot name="content" class="mb-4">
                {{ __('Are you sure you want to cancel your subscription?') }}
            </x-slot>

            <x-slot name="footer">
                <div class="flex items-center justify-center gap-3">
                    <x-billing::secondary-button wire:click="$toggle('confirmingCancelSubscription')"
                        wire:loading.attr="disabled">
                        {{ __('Keep Subscription') }}
                    </x-billing::secondary-button>

                    <x-billing::secondary-button wire:click="cancelSubscription" wire:loading.attr="disabled"
                        class="!bg-red-600 dark:!bg-red-600 !text-white dark:!text-white hover:!bg-red-700 dark:hover:!bg-red-700 !border-red-600 dark:!border-red-600">
                        {{ __('Cancel Subscription') }}
                    </x-billing::secondary-button>
                </div>

                <div wire:loading class="text-center text-gray-600 dark:text-gray-400 mt-3">
                    Please wait...
                </div>
            </x-slot>

        </x-billing::dialog-modal>
        <!-- End Cancel Subscription Confirmation Modal -->

    </x-slot>
</x-billing::action-section>
