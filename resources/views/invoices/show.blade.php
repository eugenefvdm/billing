<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $invoice->id }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'media',
        }
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <div class="min-h-screen py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mx-auto">
            <!-- Invoice Card -->
            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
                <!-- Header -->
                <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-8 text-white">
                    <div class="flex justify-between items-start">
                        <div>
                            <h1 class="text-3xl font-bold">Invoice</h1>
                            <p class="text-blue-100 mt-1">{{ config('app.name') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-blue-100">Invoice #</p>
                            <p class="text-2xl font-bold">{{ $invoice->id }}</p>
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-8">
                    <!-- Invoice Details -->
                    <div class="grid grid-cols-2 gap-6 mb-8">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Bill To</h3>
                            <p class="font-medium dark:text-gray-100">{{ $invoice->billable->name }}</p>
                            <p class="text-gray-600 dark:text-gray-300">{{ $invoice->billable->email }}</p>
                        </div>
                        <div class="text-right">
                            <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Invoice Date</h3>
                            <p class="font-medium dark:text-gray-100">{{ $invoice->issued_at->translatedFormat('F j, Y') }}</p>
                            <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mt-4 mb-2">Due Date</h3>
                            <p class="font-medium {{ $invoice->isOverdue() ? 'text-red-600 dark:text-red-400' : 'dark:text-gray-100' }}">
                                {{ $invoice->due_at->translatedFormat('F j, Y') }}
                            </p>
                            <div class="mt-4 flex justify-end">
                                @if($invoice->isPaid())
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">
                                        ✓ Paid
                                    </span>
                                @elseif($invoice->isOverdue())
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">
                                        ⚠ Overdue ({{ $invoice->days_past_due }} {{ Str::plural('day', $invoice->days_past_due) }})
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300">
                                        ⏱ Unpaid
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Line Items -->
                    <div class="mb-8">
                        <table class="w-full">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Description</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Qty</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach($invoice->items as $item)
                                <tr>
                                    <td class="px-4 py-4 text-sm dark:text-gray-200">{{ $item->description }}</td>
                                    <td class="px-4 py-4 text-sm text-center dark:text-gray-200">{{ $item->quantity }}</td>
                                    <td class="px-4 py-4 text-sm text-right font-medium dark:text-gray-200">
                                        R{{ number_format($item->line_total / 100, 2) }}
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals -->
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <div class="flex justify-end">
                            <div class="w-64">
                                <div class="flex justify-between py-2 text-sm">
                                    <span class="text-gray-600 dark:text-gray-300">Subtotal</span>
                                    <span class="dark:text-gray-200">R{{ number_format($invoice->subtotal / 100, 2) }}</span>
                                </div>
                                @if($invoice->tax > 0)
                                <div class="flex justify-between py-2 text-sm">
                                    <span class="text-gray-600 dark:text-gray-300">Tax</span>
                                    <span class="dark:text-gray-200">R{{ number_format($invoice->tax / 100, 2) }}</span>
                                </div>
                                @endif
                                <div class="flex justify-between py-3 text-lg font-bold border-t border-gray-200 dark:border-gray-700">
                                    <span class="dark:text-gray-100">Total</span>
                                    <span class="dark:text-gray-100">R{{ number_format($invoice->total / 100, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Instructions -->
                    @if(!$invoice->isPaid() && $invoice->subscription && $invoice->subscription->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Eft)
                    <div class="mt-8 p-6 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-4">Payment Instructions</h3>
                        <p class="text-sm text-blue-800 dark:text-blue-200 mb-4">Please make payment via EFT to:</p>
                        <div class="space-y-2 text-sm">
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900 dark:text-blue-100">Bank:</span>
                                <span class="text-blue-800 dark:text-blue-200">{{ config('billing.eft.bank_name') }}</span>
                            </div>
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900 dark:text-blue-100">Account:</span>
                                <span class="text-blue-800 dark:text-blue-200">{{ config('billing.eft.bank_account_number') }}</span>
                            </div>
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900 dark:text-blue-100">Account Type:</span>
                                <span class="text-blue-800 dark:text-blue-200">{{ config('billing.eft.bank_account_type') }}</span>
                            </div>
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900 dark:text-blue-100">Branch Code:</span>
                                <span class="text-blue-800 dark:text-blue-200">{{ config('billing.eft.bank_branch_code') }}</span>
                            </div>
                            <div class="flex items-center pt-2 border-t border-blue-200 dark:border-blue-800">
                                <span class="font-medium w-32 text-blue-900 dark:text-blue-100">Reference:</span>
                                <span class="text-blue-800 dark:text-blue-200 font-bold text-lg">INV-{{ $invoice->id }}</span>
                            </div>
                        </div>
                        <p class="mt-4 text-xs text-blue-700 dark:text-blue-300">
                            ⚠️ <strong>Important:</strong> Please use <strong>INV-{{ $invoice->id }}</strong> as your payment reference.
                        </p>
                    </div>
                    @endif

                    <!-- Download Button -->
                    <div class="mt-8 text-center">
                        <a href="{{ route('invoices.download', $invoice->uuid) }}" 
                           class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                            <svg class="mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Download
                        </a>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
                <p>{{ config('app.name') }} | {{ config('app.url') }}</p>
                <p class="mt-2">Thank you for your business!</p>
            </div>
        </div>
    </div>
</body>
</html>

