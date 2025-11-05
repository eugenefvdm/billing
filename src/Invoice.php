<?php

namespace Eugenefvdm\Billing;

use Carbon\Carbon;
use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Eugenefvdm\Billing\Enums\PaymentMethod;
use Eugenefvdm\Billing\Events\InvoicePaid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Invoice extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'first_reminder_sent_at' => 'datetime',
        'second_reminder_sent_at' => 'datetime',
        'third_reminder_sent_at' => 'datetime',
        'status' => InvoiceStatus::class,
    ];

    /**
     * Get the billable model related to the invoice.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subscription that this invoice belongs to.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Cashier::$subscriptionModel);
    }

    /**
     * Get the invoice items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Scope a query to only include unpaid invoices.
     */
    public function scopeUnpaid($query)
    {
        return $query->where('status', InvoiceStatus::Unpaid);
    }

    /**
     * Determine if the invoice is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    /**
     * Determine if the invoice is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_at < now() && !$this->isPaid();
    }

    /**
     * Get the number of days past due.
     */
    public function getDaysPastDueAttribute(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return abs(now()->diffInDays($this->due_at));
    }

    /**
     * Check if invoice is in first reminder period.
     */
    public function getInFirstReminderPeriodAttribute(): bool
    {
        $first = config('billing.invoice.reminders.first_overdue_notice');
        $second = config('billing.invoice.reminders.second_overdue_notice');

        return $this->days_past_due >= $first
            && $this->days_past_due < $second;
    }

    /**
     * Check if invoice is in second reminder period.
     */
    public function getInSecondReminderPeriodAttribute(): bool
    {
        $second = config('billing.invoice.reminders.second_overdue_notice');
        $third = config('billing.invoice.reminders.third_overdue_notice');

        return $this->days_past_due >= $second
            && $this->days_past_due < $third;
    }

    /**
     * Check if invoice is in third reminder period.
     */
    public function getInThirdReminderPeriodAttribute(): bool
    {
        $third = config('billing.invoice.reminders.third_overdue_notice');
        return $this->days_past_due >= $third;
    }

    /**
     * Mark the invoice as paid.
     */
    public function markAsPaid(?Carbon $paidAt = null): void
    {
        $paidAtDate = $paidAt ?? now();
        
        Log::debug("=== INVOICE PAYMENT ===");
        Log::debug("Invoice ID: {$this->id} is being marked as paid");
        Log::debug("Invoice UUID: {$this->uuid}");
        
        // Get invoice period from description
        $invoicePeriodEnd = $this->getPeriodEndDate();
        if ($invoicePeriodEnd) {
            Log::debug("Invoice period ends on: {$invoicePeriodEnd->format('jS \o\f F Y')}");
        } else {
            Log::debug("⚠ Could not determine invoice period end date from description");
        }
        
        Log::debug("Invoice was paid at: {$paidAtDate->format('jS \o\f F Y \a\t H:i:s')}");
        Log::debug("Current date/time: " . now()->format('jS \o\f F Y \a\t H:i:s'));
        
        // Check if invoice has subscription
        $hasSubscription = $this->subscription_id !== null;
        if ($hasSubscription) {
            $this->loadMissing('subscription');
            $subscription = $this->subscription;
            Log::debug("Invoice belongs to subscription ID: {$subscription->id}");
            Log::debug("Subscription status: {$subscription->status}");
            Log::debug("Subscription payment_method: {$subscription->payment_method->value}");
            Log::debug("Subscription ends_at date: " . ($subscription->ends_at ? $subscription->ends_at->format('jS \o\f F Y') : 'NULL'));
            Log::debug("Subscription ends_at is in the future: " . ($subscription->ends_at && $subscription->ends_at->isFuture() ? 'YES' : 'NO'));
            Log::debug("Subscription ends_at is in the past: " . ($subscription->ends_at && $subscription->ends_at->isPast() ? 'YES' : 'NO'));
        } else {
            Log::debug("⚠ Invoice does not belong to a subscription");
        }

        $this->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $paidAtDate,
        ]);

        if (Auth::user()) {
            $message = "Invoice {$this->id} was marked as paid by " . Auth::user()->email;
        } else {
            $message = "Invoice {$this->id} was marked as paid";
        }

        Log::info($message);
        Log::debug("✓ Invoice status updated to: Paid");

        // Activate EFT subscription when payment is received
        if ($hasSubscription && $subscription->payment_method === PaymentMethod::Eft) {
            if ($subscription->status === Subscription::STATUS_PAUSED) {
                Log::debug("=== ACTIVATING EFT SUBSCRIPTION ===");
                Log::debug("Activating paused EFT subscription ID: {$subscription->id} after invoice payment");
                
                $subscription->update([
                    'status' => Subscription::STATUS_ACTIVE,
                ]);
                
                Log::debug("✓ EFT subscription activated - status changed to ACTIVE");
                Log::info("Activated EFT subscription {$subscription->id} after invoice {$this->id} payment");
            } else {
                Log::debug("Subscription is not paused (status: {$subscription->status}), skipping activation");
            }
        }

        // Dispatch event that Livewire components can listen to
        event(new InvoicePaid($this));
        
        Log::debug("✓ InvoicePaid event dispatched");
    }

    /**
     * Get the path to the PDF file.
     */
    public function pdfPath(): string
    {
        $path = config('billing.invoice.pdf_path');
        return storage_path("app/{$path}/invoice-{$this->id}.pdf");
    }

    /**
     * Get the billing period end date from the invoice description.
     * Parses the description format: "Startup Plan Monthly 2025-11-01 to 2025-12-01"
     * Returns the end date (Carbon) or null if not found/parseable.
     */
    public function getPeriodEndDate(): ?Carbon
    {
        // Get the first invoice item's description
        $firstItem = $this->items()->first();
        if (!$firstItem || !$firstItem->description) {
            return null;
        }

        // Parse format: "Plan Name Period 2025-11-01 to 2025-12-01"
        // Extract the end date (after "to ")
        if (preg_match('/to (\d{4}-\d{2}-\d{2})/', $firstItem->description, $matches)) {
            try {
                return Carbon::parse($matches[1]);
            } catch (\Exception $e) {
                Log::warning("Failed to parse period end date from invoice {$this->id} description: {$firstItem->description}");
                return null;
            }
        }

        return null;
    }

    /**
     * Recalculate the invoice total from items.
     */
    public function recalculateTotal(): void
    {
        $this->subtotal = $this->items->sum('line_total');
        $this->total = $this->subtotal - ($this->subtotal * $this->discount_percentage / 10000);
        $this->save();
    }
}
