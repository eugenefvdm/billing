@component('mail::message')
# Friendly Payment Reminder

Hi {{ $invoice->billable->name }},

Just a friendly reminder that invoice **#{{ $invoice->id }}** is now {{ $daysPastDue }} {{ Str::plural('day', $daysPastDue) }} overdue.

**Amount Due:** R{{ number_format($amount / 100, 2) }}  
**Original Due Date:** {{ $dueDate->format('F j, Y') }}

We understand things can slip through the cracks. Please process your payment at your earliest convenience.

## Payment Instructions

Please make payment via EFT to:

- **Bank:** [Your Bank Name]
- **Account Number:** [Your Account Number]
- **Account Type:** [Checking/Savings]
- **Branch Code:** [Your Branch Code]
- **Reference:** INV-{{ $invoice->id }}

@component('mail::button', ['url' => route('invoices.show', $invoice->uuid)])
View Invoice Online
@endcomponent

Your invoice PDF is attached to this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

