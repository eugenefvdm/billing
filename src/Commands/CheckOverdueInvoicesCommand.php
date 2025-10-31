<?php

namespace Eugenefvdm\Billing\Commands;

use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Invoice;
use Eugenefvdm\Billing\Mail\InvoiceReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:check-overdue';

    protected $description = 'Send payment reminders for overdue EFT invoices';

    public function handle(): int
    {
        $invoices = Invoice::unpaid()
            ->whereHas('subscription', fn ($q) =>
                $q->where('payment_method', PaymentMethod::Eft)
            )
            ->where('due_at', '<', now())
            ->get();

        $remindersSent = 0;

        foreach ($invoices as $invoice) {
            // First reminder
            if ($invoice->in_first_reminder_period && !$invoice->first_reminder_sent_at) {
                Mail::to($invoice->billable->email)->send(
                    new InvoiceReminder($invoice, 'first')
                );
                $invoice->update(['first_reminder_sent_at' => now()]);
                $remindersSent++;
            }

            // Second reminder
            if ($invoice->in_second_reminder_period && !$invoice->second_reminder_sent_at) {
                Mail::to($invoice->billable->email)->send(
                    new InvoiceReminder($invoice, 'second')
                );
                $invoice->update(['second_reminder_sent_at' => now()]);
                $remindersSent++;
            }

            // Third reminder
            if ($invoice->in_third_reminder_period && !$invoice->third_reminder_sent_at) {
                Mail::to($invoice->billable->email)->send(
                    new InvoiceReminder($invoice, 'third')
                );
                $invoice->update(['third_reminder_sent_at' => now()]);
                $remindersSent++;
            }
        }

        Log::info("Sent {$remindersSent} reminders for {$invoices->count()} overdue invoices");

        return Command::SUCCESS;
    }
}

