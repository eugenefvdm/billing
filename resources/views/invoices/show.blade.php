<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $invoice->id }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mx-auto">
            <!-- Invoice Card -->
            <div class="bg-white shadow-sm rounded-lg overflow-hidden">
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
                    <!-- Status Badge -->
                    <div class="mb-6">
                        @if($invoice->isPaid())
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                ✓ Paid
                            </span>
                        @elseif($invoice->isOverdue())
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                                ⚠ Overdue ({{ $invoice->days_past_due }} {{ Str::plural('day', $invoice->days_past_due) }})
                            </span>
                        @else
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                                ⏱ Unpaid
                            </span>
                        @endif
                    </div>

                    <!-- Invoice Details -->
                    <div class="grid grid-cols-2 gap-6 mb-8">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Bill To</h3>
                            <p class="font-medium">{{ $invoice->billable->name }}</p>
                            <p class="text-gray-600">{{ $invoice->billable->email }}</p>
                        </div>
                        <div class="text-right">
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Invoice Date</h3>
                            <p class="font-medium">{{ $invoice->issued_at->format('F j, Y') }}</p>
                            <h3 class="text-sm font-medium text-gray-500 mt-4 mb-2">Due Date</h3>
                            <p class="font-medium {{ $invoice->isOverdue() ? 'text-red-600' : '' }}">
                                {{ $invoice->due_at->format('F j, Y') }}
                            </p>
                        </div>
                    </div>

                    <!-- Line Items -->
                    <div class="mb-8">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach($invoice->items as $item)
                                <tr>
                                    <td class="px-4 py-4 text-sm">{{ $item->description }}</td>
                                    <td class="px-4 py-4 text-sm text-center">{{ $item->quantity }}</td>
                                    <td class="px-4 py-4 text-sm text-right font-medium">
                                        R{{ number_format($item->line_total / 100, 2) }}
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals -->
                    <div class="border-t border-gray-200 pt-4">
                        <div class="flex justify-end">
                            <div class="w-64">
                                <div class="flex justify-between py-2 text-sm">
                                    <span class="text-gray-600">Subtotal</span>
                                    <span>R{{ number_format($invoice->subtotal / 100, 2) }}</span>
                                </div>
                                @if($invoice->tax > 0)
                                <div class="flex justify-between py-2 text-sm">
                                    <span class="text-gray-600">Tax</span>
                                    <span>R{{ number_format($invoice->tax / 100, 2) }}</span>
                                </div>
                                @endif
                                <div class="flex justify-between py-3 text-lg font-bold border-t border-gray-200">
                                    <span>Total</span>
                                    <span>R{{ number_format($invoice->total / 100, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Instructions -->
                    @if(!$invoice->isPaid() && $invoice->subscription && $invoice->subscription->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Eft)
                    <div class="mt-8 p-6 bg-blue-50 border border-blue-200 rounded-lg">
                        <h3 class="text-lg font-semibold text-blue-900 mb-4">Payment Instructions</h3>
                        <p class="text-sm text-blue-800 mb-4">Please make payment via EFT to:</p>
                        <div class="space-y-2 text-sm">
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900">Bank:</span>
                                <span class="text-blue-800">[Your Bank Name]</span>
                            </div>
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900">Account:</span>
                                <span class="text-blue-800">[Your Account Number]</span>
                            </div>
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900">Account Type:</span>
                                <span class="text-blue-800">[Checking/Savings]</span>
                            </div>
                            <div class="flex">
                                <span class="font-medium w-32 text-blue-900">Branch Code:</span>
                                <span class="text-blue-800">[Your Branch Code]</span>
                            </div>
                            <div class="flex items-center pt-2 border-t border-blue-200">
                                <span class="font-medium w-32 text-blue-900">Reference:</span>
                                <span class="text-blue-800 font-bold text-lg">INV-{{ $invoice->id }}</span>
                            </div>
                        </div>
                        <p class="mt-4 text-xs text-blue-700">
                            ⚠️ <strong>Important:</strong> Please use <strong>INV-{{ $invoice->id }}</strong> as your payment reference.
                        </p>
                    </div>
                    @endif

                    <!-- Download Button -->
                    <div class="mt-8 text-center">
                        <a href="{{ route('invoices.download', $invoice->uuid) }}" 
                           class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <svg class="mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Download PDF
                        </a>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="mt-8 text-center text-sm text-gray-500">
                <p>{{ config('app.name') }} | {{ config('app.url') }}</p>
                <p class="mt-2">Thank you for your business!</p>
            </div>
        </div>
    </div>
</body>
</html>

