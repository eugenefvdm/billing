<x-payfast::action-section>

    <x-slot name="title">
        {{ __('Receipts') }}
    </x-slot>

    <x-slot name="description">
        {{ __('A list of transactions and receipts.') }}
    </x-slot>

    <x-slot name="content">
        <div class="text-gray-600 dark:text-gray-400">
            <table width="100%" class="table-auto">
                <thead class="bg-gray-50 dark:bg-zinc-700">
                    <tr>
                        <td class="py-1 px-1" nowrap><strong>ID</strong></th>
                        <td class="py-1 px-1"><strong>Item</strong></td>
                        <td class="py-1 px-1 text-right"><strong>Amount</strong></th>
                        <td class="py-1 px-1"><strong>Status</strong></th>
                        <td class="py-1 px-1"><strong>Date</strong></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($receipts as $receipt)
                        <tr class="odd:bg-white odd:dark:bg-zinc-800 even:bg-gray-50 even:dark:bg-zinc-700">
                            <td class="py-1 px-1">{{ $receipt->payfast_payment_id }}</td>
                            <td class="py-1 px-1">{{ $receipt->item_name }}</td>
                            <td class="py-1 px-1 text-right">R {{ $receipt->amount_gross }}</td>
                            <td class="py-1 px-1">{{ $receipt->payment_status }}</td>
                            <td class="py-1 px-1 whitespace-nowrap">{{ isset($receipt->billing_date) ? $receipt->billing_date->format('Y-m-d') : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-slot>

</x-payfast::action-section>
