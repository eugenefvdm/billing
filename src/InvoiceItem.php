<?php

namespace Eugenefvdm\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $guarded = [];

    /**
     * Get the invoice that owns the item.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Boot observer to auto-calculate and update invoice.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($item) {
            $item->line_total = $item->quantity * $item->unit_price;
        });

        static::saved(function ($item) {
            $item->invoice->recalculateTotal();
        });

        static::deleted(function ($item) {
            $item->invoice->recalculateTotal();
        });
    }
}

