<x-billing::action-section>

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
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h4 class="font-semibold text-gray-900 dark:text-gray-100">
                                        Invoice #{{ $invoice->id }}
                                        @if($invoice->isOverdue())
                                            <span class="text-red-600 dark:text-red-400 text-sm font-normal">
                                                ({{ $invoice->days_past_due }} {{ __('days overdue') }})
                                            </span>
                                        @endif                                    
                                    </h4>
                                    @if($invoice->isPaid())
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">
                                            Paid: {{ $invoice->paid_at->translatedFormat('j F Y') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300">
                                            Due: {{ $invoice->due_at->translatedFormat('j F Y') }}
                                        </span>
                                    @endif
                                </div>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">
                                    {{ config('billing.billables.user.currency_prefix') }}{{ number_format($invoice->total / 100, 2) }}
                                </p>
                            </div>
                            
                            <div class="flex gap-2">
                                <x-billing::secondary-button 
                                    href="{{ route('invoices.show', $invoice->uuid) }}"
                                    target="_blank"
                                    class="text-sm"
                                    onclick="console.log('View button clicked', {uuid: '{{ $invoice->uuid }}', href: '{{ route('invoices.show', $invoice->uuid) }}'}); window.open('{{ route('invoices.show', $invoice->uuid) }}', '_blank'); return false;">
                                    {{ __('View') }}
                                </x-billing::secondary-button>
                                
                                <x-billing::secondary-button 
                                    href="{{ route('invoices.download', $invoice->uuid) }}"
                                    class="text-sm"
                                    onclick="console.log('Download button clicked', {uuid: '{{ $invoice->uuid }}', href: '{{ route('invoices.download', $invoice->uuid) }}'}); window.location.href = '{{ route('invoices.download', $invoice->uuid) }}'; return false;">
                                    {{ __('Download') }}
                                </x-billing::secondary-button>
                            </div>
                            
                            <script>
                                console.log('Invoice buttons rendered', {
                                    viewUrl: '{{ route('invoices.show', $invoice->uuid) }}',
                                    downloadUrl: '{{ route('invoices.download', $invoice->uuid) }}',
                                    invoiceId: {{ $invoice->id }},
                                    invoiceUuid: '{{ $invoice->uuid }}'
                                });
                            </script>
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

    @if(session()->has('livewire_dispatch'))
        @push('scripts')
        <script>
            document.addEventListener('livewire:init', () => {
                @foreach(session()->get('livewire_dispatch', []) as $event)
                    Livewire.dispatch('{{ $event }}');
                @endforeach
            });
        </script>
        @endpush
    @endif

</x-billing::action-section>

