@component('mail::message')
# Invoice Created

Hi {{ $invoice->billable->name }},

Your invoice is ready!

**Invoice #{{ $invoice->id }}**  
**Amount:** R{{ number_format($invoice->total / 100, 2) }}  
**Due Date:** {{ $invoice->due_at->format('F j, Y') }}

@if($invoice->subscription && $invoice->subscription->payment_method === \Eugenefvdm\Billing\Enums\PaymentMethod::Eft)
## Payment Instructions

Please make payment via EFT to:

- **Bank:** [Your Bank Name]
- **Account Number:** [Your Account Number]
- **Account Type:** [Checking/Savings]
- **Branch Code:** [Your Branch Code]
- **Reference:** INV-{{ $invoice->id }}

⚠️ **Important:** Please use **INV-{{ $invoice->id }}** as your payment reference so we can match your payment.
@endif

@component('mail::button', ['url' => route('invoices.show', $invoice->uuid)])
View Invoice Online
@endcomponent

Your invoice PDF is attached to this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

