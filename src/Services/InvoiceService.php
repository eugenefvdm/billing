<?php

namespace Eugenefvdm\Billing\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Eugenefvdm\Billing\Invoice;
use Eugenefvdm\Billing\Subscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceService
{
    /**
     * Create an invoice for a subscription's billing period.
     */
    public static function createSubscriptionInvoice(Subscription $subscription): Invoice
    {
        $plan = $subscription->plan();
        
        // Extract interval from type (format: "0|monthly" or "1|yearly")
        $interval = $subscription->type;
        if (strpos($interval, '|') !== false) {
            [, $interval] = explode('|', $interval);
        }
        
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
        Log::info("InvoiceService::createPdf called", [
            'invoice_id' => $invoice->id,
            'invoice_uuid' => $invoice->uuid,
            'stream' => $stream,
        ]);

        try {
            $pdf = Pdf::loadView('billing::pdf.invoice', [
                'invoice' => $invoice,
            ]);
            
            Log::info("PDF loaded successfully", [
                'invoice_id' => $invoice->id,
            ]);

            if ($stream) {
                Log::info("Streaming PDF", [
                    'invoice_id' => $invoice->id,
                    'filename' => "Invoice-{$invoice->id}.pdf",
                ]);
                return $pdf->stream("Invoice-{$invoice->id}.pdf");
            }

            // Ensure storage directory exists
            $directory = dirname($invoice->pdfPath());
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
                Log::info("Created PDF directory", [
                    'directory' => $directory,
                ]);
            }

            $pdf->save($invoice->pdfPath());
            
            Log::info("PDF saved successfully", [
                'invoice_id' => $invoice->id,
                'path' => $invoice->pdfPath(),
            ]);

            return $pdf;
        } catch (\Exception $e) {
            Log::error("Error in InvoiceService::createPdf", [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if an overdue reminder must be sent for an invoice.
     * Returns 'first', 'second', 'third', or false if no reminder should be sent.
     */
    public static function checkIfOverdueReminderMustBeSent(Invoice $invoice): string|bool
    {
        if (!$invoice->isOverdue()) {
            return false;
        }

        if ($invoice->in_first_reminder_period) {
            return 'first';
        }

        if ($invoice->in_second_reminder_period) {
            return 'second';
        }

        if ($invoice->in_third_reminder_period) {
            return 'third';
        }

        return false;
    }
}

