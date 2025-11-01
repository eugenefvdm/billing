<x-payfast::action-section>

    <x-slot name="title">
        {{ __('Invoices') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Your EFT invoices and payment status.') }}
    </x-slot>

    <x-slot name="content">
        @if($invoices->isEmpty())
            <div class="text-gray-600 dark:text-gray-400">
                <p>{{ __('No invoices found.') }}</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($invoices as $invoice)
                    <div class="border rounded-lg p-4 
                        {{ $invoice->isOverdue() ? 'border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-800' : '' }}
                        {{ $invoice->isPaid() ? 'border-green-300 bg-green-50 dark:bg-green-900/20 dark:border-green-800' : 'border-gray-200 dark:border-gray-700' }}">
                        
                        <div class="flex justify-between items-start">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100">
                                    Invoice #{{ $invoice->id }}
                                    @if($invoice->isOverdue())
                                        <span class="text-red-600 dark:text-red-400 text-sm font-normal">
                                            ({{ $invoice->days_past_due }} {{ __('days overdue') }})
                                        </span>
                                    @endif
                                    @if($invoice->isPaid())
                                        <span class="text-green-600 dark:text-green-400 text-sm font-normal">
                                            ({{ __('Paid') }})
                                        </span>
                                    @endif
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    {{ __('Due') }}: {{ $invoice->due_at->format('F j, Y') }}
                                </p>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">
                                    {{ config('billing.billables.user.currency_prefix') }}{{ number_format($invoice->total / 100, 2) }}
                                </p>
                            </div>
                            
                            <div class="flex gap-2">
                                <x-payfast::secondary-button 
                                    href="{{ route('invoices.show', $invoice->uuid) }}"
                                    target="_blank"
                                    class="text-sm">
                                    {{ __('View') }}
                                </x-payfast::secondary-button>
                                
                                <x-payfast::secondary-button 
                                    href="{{ route('invoices.download', $invoice->uuid) }}"
                                    class="text-sm">
                                    {{ __('Download PDF') }}
                                </x-payfast::secondary-button>
                            </div>
                        </div>
                        
                        @if(!$invoice->isPaid())
                            <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded text-sm">
                                <p class="font-medium text-blue-900 dark:text-blue-100 mb-1">
                                    {{ __('Payment Reference') }}: <span class="font-mono">INV-{{ $invoice->id }}</span>
                                </p>
                                <p class="text-blue-800 dark:text-blue-200 text-xs">
                                    {{ __('Use this reference when making your EFT payment.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-slot>

</x-payfast::action-section>

