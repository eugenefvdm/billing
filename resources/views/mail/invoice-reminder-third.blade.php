@component('mail::message')
# Final Payment Notice

Hi {{ $invoice->billable->name }},

This is our final reminder. Invoice **#{{ $invoice->id }}** is {{ $daysPastDue }} days overdue.

**Amount Due:** R{{ number_format($amount / 100, 2) }}  
**Original Due Date:** {{ $dueDate->format('F j, Y') }}

⚠️ **Immediate Action Required**

Please settle this invoice immediately to avoid service suspension. If you're experiencing difficulties with payment, please contact us to discuss payment arrangements.

## Payment Instructions

Please make payment via EFT to:

- **Bank:** [Your Bank Name]
- **Account Number:** [Your Account Number]
- **Account Type:** [Checking/Savings]
- **Branch Code:** [Your Branch Code]
- **Reference:** INV-{{ $invoice->id }}

@component('mail::button', ['url' => route('invoices.show', $invoice->uuid)])
Urgent: Pay Now
@endcomponent

If payment has already been made, please disregard this notice and accept our apologies for any inconvenience.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

