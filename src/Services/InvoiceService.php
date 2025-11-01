<?php

namespace Eugenefvdm\Billing\Services;

use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Eugenefvdm\Billing\Invoice;
use Eugenefvdm\Billing\Subscription;
use Illuminate\Support\Str;

class InvoiceService
{
    /**
     * Create an invoice for a subscription's billing period.
     */
    public static function createSubscriptionInvoice(Subscription $subscription): Invoice
    {
        $plan = $subscription->plan();
        $interval = $subscription->type; // 'monthly' or 'yearly'
        $amount = $plan[$interval]['recurring_amount']; // cents

        $dueAt = now()->addDays(config('billing.invoice.default_due_days', 7));

        $invoice = $subscription->billable->invoices()->create([
            'subscription_id' => $subscription->id,
            'uuid' => Str::uuid(),
            'status' => InvoiceStatus::Unpaid,
            'issued_at' => now(),
            'due_at' => $dueAt,
            'currency' => 'ZAR',
        ]);

        // Add line item
        $invoice->items()->create([
            'description' => $subscription->period_description,
            'quantity' => 1,
            'unit_price' => $amount,
        ]);

        return $invoice->fresh();
    }

    /**
     * Create a PDF for an invoice.
     */
    public static function createPdf(Invoice $invoice, bool $stream = false)
    {
        $pdf = app('dompdf.wrapper')->loadView('billing::pdf.invoice', [
            'invoice' => $invoice,
        ]);

        if ($stream) {
            return $pdf->stream("Invoice-{$invoice->id}.pdf");
        }

        // Ensure storage directory exists
        $directory = dirname($invoice->pdfPath());
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $pdf->save($invoice->pdfPath());

        return $pdf;
    }
}

