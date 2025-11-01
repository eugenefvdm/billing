<?php

namespace Eugenefvdm\Billing;

use Carbon\Carbon;
use Eugenefvdm\Billing\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        $this->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $paidAt ?? now(),
        ]);
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
     * Recalculate the invoice total from items.
     */
    public function recalculateTotal(): void
    {
        $this->subtotal = $this->items->sum('line_total');
        $this->total = $this->subtotal - ($this->subtotal * $this->discount_percentage / 10000);
        $this->save();
    }
}

