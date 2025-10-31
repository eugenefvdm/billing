<?php

namespace Eugenefvdm\Billing\Mail;

use Eugenefvdm\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice Created - Invoice #' . $this->invoice->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'billing::mail.invoice-created',
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

