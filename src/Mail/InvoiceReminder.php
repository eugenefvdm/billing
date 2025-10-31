<?php

namespace Eugenefvdm\Billing\Mail;

use Eugenefvdm\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string $reminderType // 'first', 'second', 'third'
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->reminderType) {
            'first' => 'Payment Reminder - Invoice #' . $this->invoice->id,
            'second' => 'Second Payment Reminder - Invoice #' . $this->invoice->id,
            'third' => 'Final Payment Notice - Invoice #' . $this->invoice->id,
            default => 'Invoice Reminder',
        };

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $view = "billing::mail.invoice-reminder-{$this->reminderType}";

        return new Content(
            markdown: $view,
            with: [
                'daysPastDue' => $this->invoice->days_past_due,
                'dueDate' => $this->invoice->due_at,
                'amount' => $this->invoice->total,
            ]
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->invoice->pdfPath())
                ->as("Invoice-{$this->invoice->id}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}

