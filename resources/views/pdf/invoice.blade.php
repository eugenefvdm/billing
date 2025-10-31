<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoice->id }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #333;
        }
        .header {
            margin-bottom: 40px;
        }
        .header h1 {
            margin: 0;
            font-size: 32px;
            color: #2c3e50;
        }
        .company-details {
            margin-top: 10px;
            font-size: 11px;
            color: #666;
        }
        .invoice-details {
            margin-bottom: 30px;
        }
        .invoice-details table {
            width: 100%;
        }
        .invoice-details td {
            padding: 5px 0;
        }
        .bill-to {
            margin-bottom: 30px;
        }
        .bill-to h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #2c3e50;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.items th {
            background-color: #2c3e50;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        table.items td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        .totals {
            float: right;
            width: 300px;
        }
        .totals table {
            width: 100%;
        }
        .totals td {
            padding: 5px 10px;
        }
        .totals .total-row {
            font-weight: bold;
            font-size: 14px;
            background-color: #f8f9fa;
        }
        .payment-instructions {
            clear: both;
            margin-top: 40px;
            padding: 20px;
            background-color: #f8f9fa;
            border-left: 4px solid #2c3e50;
        }
        .payment-instructions h3 {
            margin-top: 0;
            color: #2c3e50;
        }
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #2c3e50;
            text-align: center;
            font-size: 10px;
            color: #999;
        }
        .due-date {
            font-size: 16px;
            color: #e74c3c;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>INVOICE</h1>
        <div class="company-details">
            <strong>{{ config('app.name') }}</strong><br>
            [Your Company Address]<br>
            [City, Province, Postal Code]<br>
            [Phone Number] | [Email Address]
        </div>
    </div>

    <div class="invoice-details">
        <table>
            <tr>
                <td style="width: 50%;">
                    <strong>Invoice #:</strong> {{ $invoice->id }}<br>
                    <strong>Issued:</strong> {{ $invoice->issued_at->format('F j, Y') }}
                </td>
                <td style="width: 50%; text-align: right;">
                    <strong>Due Date:</strong><br>
                    <span class="due-date">{{ $invoice->due_at->format('F j, Y') }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="bill-to">
        <h3>Bill To:</h3>
        <strong>{{ $invoice->billable->name }}</strong><br>
        {{ $invoice->billable->email }}<br>
        @if($invoice->subscription)
            Subscription: {{ $invoice->subscription->planName() }} ({{ ucfirst($invoice->subscription->type) }})
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th style="width: 15%; text-align: center;">Quantity</th>
                <th style="width: 20%; text-align: right;">Unit Price</th>
                <th style="width: 20%; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td style="text-align: center;">{{ $item->quantity }}</td>
                <td style="text-align: right;">R{{ number_format($item->unit_price / 100, 2) }}</td>
                <td style="text-align: right;">R{{ number_format($item->line_total / 100, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td>Subtotal:</td>
                <td style="text-align: right;">R{{ number_format($invoice->subtotal / 100, 2) }}</td>
            </tr>
            @if($invoice->tax > 0)
            <tr>
                <td>Tax:</td>
                <td style="text-align: right;">R{{ number_format($invoice->tax / 100, 2) }}</td>
            </tr>
            @endif
            @if($invoice->discount_percentage > 0)
            <tr>
                <td>Discount ({{ $invoice->discount_percentage / 100 }}%):</td>
                <td style="text-align: right;">-R{{ number_format(($invoice->subtotal * $invoice->discount_percentage / 10000) / 100, 2) }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>Total Due:</td>
                <td style="text-align: right;">R{{ number_format($invoice->total / 100, 2) }}</td>
            </tr>
        </table>
    </div>

    @if($invoice->subscription && $invoice->subscription->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Eft)
    <div class="payment-instructions">
        <h3>Payment Instructions</h3>
        <p>Please make payment via EFT to the following account:</p>
        <table style="width: 100%; margin-top: 10px;">
            <tr>
                <td><strong>Bank:</strong></td>
                <td>[Your Bank Name]</td>
            </tr>
            <tr>
                <td><strong>Account Number:</strong></td>
                <td>[Your Account Number]</td>
            </tr>
            <tr>
                <td><strong>Account Type:</strong></td>
                <td>[Checking/Savings]</td>
            </tr>
            <tr>
                <td><strong>Branch Code:</strong></td>
                <td>[Your Branch Code]</td>
            </tr>
            <tr>
                <td><strong>Payment Reference:</strong></td>
                <td><strong style="color: #e74c3c;">INV-{{ $invoice->id }}</strong></td>
            </tr>
        </table>
        <p style="margin-top: 10px; font-size: 11px; color: #666;">
            <strong>Important:</strong> Please use <strong>INV-{{ $invoice->id }}</strong> as your payment reference.
        </p>
    </div>
    @endif

    <div class="footer">
        Thank you for your business!<br>
        {{ config('app.name') }} | {{ config('app.url') }}
    </div>
</body>
</html>

