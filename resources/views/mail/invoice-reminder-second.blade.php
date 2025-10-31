@component('mail::message')
# Payment Reminder - Action Required

Hi {{ $invoice->billable->name }},

Invoice **#{{ $invoice->id }}** is now {{ $daysPastDue }} days overdue.

**Amount Due:** R{{ number_format($amount / 100, 2) }}  
**Original Due Date:** {{ $dueDate->format('F j, Y') }}

Please settle this invoice to maintain your subscription and avoid any service interruptions.

## Payment Instructions

Please make payment via EFT to:

- **Bank:** [Your Bank Name]
- **Account Number:** [Your Account Number]
- **Account Type:** [Checking/Savings]
- **Branch Code:** [Your Branch Code]
- **Reference:** INV-{{ $invoice->id }}

@component('mail::button', ['url' => route('invoices.show', $invoice->uuid)])
Pay Now
@endcomponent

If you have any questions or concerns, please contact us immediately.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

