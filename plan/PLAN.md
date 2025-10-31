<!-- 8c09bfde-f94f-4df5-8597-a0cd977d09e4 5848f598-7cf3-4593-b4da-de0ba5f0ebde -->
# Build Billable Subscriptions Package

## Package Foundation

### 1. Initialize Package Structure

Create new package repository `eugenefvdm/billable-subscriptions`:

```
billable-subscriptions/
├── src/
│   ├── BillableServiceProvider.php
│   ├── Traits/
│   │   └── Billable.php
│   ├── Models/
│   │   ├── Subscription.php
│   │   ├── Invoice.php
│   │   └── InvoiceItem.php
│   ├── Services/
│   │   ├── InvoiceService.php
│   │   └── PayfastService.php
│   ├── Commands/
│   │   ├── SubscriptionForwardCommand.php
│   │   └── CheckOverdueInvoicesCommand.php
│   ├── Enums/
│   │   ├── PaymentMethod.php
│   │   ├── PlanInterval.php
│   │   ├── SubscriptionStatus.php
│   │   └── InvoiceStatus.php
│   ├── Mail/
│   │   ├── InvoiceCreated.php
│   │   └── InvoiceReminder.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── PayfastWebhookController.php
│   └── Builders/
│       ├── SubscriptionBuilder.php
│       └── EftSubscriptionBuilder.php
├── database/
│   └── migrations/
│       ├── create_subscriptions_table.php
│       ├── create_invoices_table.php
│       └── create_invoice_items_table.php
├── config/
│   └── billable.php
├── resources/
│   └── views/
│       ├── pdf/
│       │   └── invoice.blade.php
│       └── mail/
│           ├── invoice-created.blade.php
│           ├── invoice-reminder-first.blade.php
│           ├── invoice-reminder-second.blade.php
│           └── invoice-reminder-third.blade.php
├── tests/
│   ├── Unit/
│   └── Feature/
├── composer.json
└── README.md
```

**composer.json dependencies:**

- `laravel/framework: ^11.0|^12.0`
- `barryvdh/laravel-dompdf: ^3.0`
- `guzzlehttp/guzzle: ^7.0` (for Payfast API)

### 2. Create Base Configuration

**config/billable.php:**

```php
return [
    'payfast' => [
        'merchant_id' => env('PAYFAST_MERCHANT_ID'),
        'merchant_key' => env('PAYFAST_MERCHANT_KEY'),
        'passphrase' => env('PAYFAST_PASSPHRASE'),
        'test_mode' => env('PAYFAST_TEST_MODE', false),
        'return_url' => env('PAYFAST_RETURN_URL'),
        'cancel_url' => env('PAYFAST_CANCEL_URL'),
        'notify_url' => env('PAYFAST_NOTIFY_URL'),
    ],
    
    'invoice' => [
        'default_due_days' => env('INVOICE_DUE_DAYS', 7),
        'pdf_storage_path' => env('INVOICE_PDF_PATH', 'invoices'),
    ],
    
    'reminders' => [
        // Days after due_at to send each reminder
        'first_reminder_days' => env('REMINDER_FIRST_DAYS', 3),
        'second_reminder_days' => env('REMINDER_SECOND_DAYS', 7),
        'third_reminder_days' => env('REMINDER_THIRD_DAYS', 14),
    ],
    
    'forward' => [
        'schedule' => env('SUBSCRIPTION_FORWARD_SCHEDULE', 'hourly'),
    ],
    
    'billables' => [
        'user' => [
            'model' => \App\Models\User::class,
            'trial_days' => 14,
            'plans' => [
                [
                    'name' => 'Monthly',
                    'type' => 'monthly',
                    'price' => 9900, // cents
                    'interval' => 'monthly',
                    'payfast_plan_id' => env('PAYFAST_MONTHLY_PLAN'),
                ],
                [
                    'name' => 'Yearly',
                    'type' => 'yearly',
                    'price' => 99000, // cents
                    'interval' => 'yearly',
                    'payfast_plan_id' => env('PAYFAST_YEARLY_PLAN'),
                ],
            ],
        ],
    ],
];
```

## Database Layer

### 3. Create Migration: Subscriptions Table

**Key design decisions:**

- `payment_method` enum as primary discriminator
- `payfast_token` nullable (NULL = EFT, value = Card)
- `start_date` for EFT subscriptions
- Shared `ends_at` for both payment methods
```php
Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->morphs('billable'); // Support any billable model
    $table->string('name')->default('default');
    $table->string('type'); // 'monthly', 'yearly', etc.
    $table->enum('payment_method', ['card', 'eft'])->default('card');
    
    // Payfast-specific (nullable for EFT)
    $table->string('payfast_token')->nullable()->unique();
    $table->string('payfast_status')->nullable();
    
    // Dates (shared by both)
    $table->timestamp('start_date')->nullable();
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->timestamp('paused_from')->nullable();
    
    // Status
    $table->string('status')->default('active');
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index(['billable_id', 'billable_type', 'status']);
    $table->index('payment_method');
});
```


### 4. Create Migration: Invoices Table

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
    $table->morphs('billable');
    $table->uuid('uuid')->unique();
    
    $table->string('status')->default('unpaid');
    $table->text('description')->nullable();
    $table->unsignedBigInteger('subtotal')->default(0); // cents
    $table->unsignedBigInteger('tax')->default(0); // cents
    $table->unsignedBigInteger('total')->default(0); // cents
    $table->unsignedInteger('discount_percentage')->default(0);
    
    $table->string('currency', 3)->default('ZAR');
    
    $table->timestamp('issued_at')->nullable();
    $table->timestamp('due_at')->nullable();
    $table->timestamp('paid_at')->nullable();
    
    // Track reminder sending
    $table->timestamp('first_reminder_sent_at')->nullable();
    $table->timestamp('second_reminder_sent_at')->nullable();
    $table->timestamp('third_reminder_sent_at')->nullable();
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index('status');
    $table->index('due_at');
});
```

### 5. Create Migration: Invoice Items Table

```php
Schema::create('invoice_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
    
    $table->text('description');
    $table->unsignedInteger('quantity')->default(1);
    $table->unsignedBigInteger('unit_price')->default(0); // cents
    $table->unsignedBigInteger('line_total')->default(0); // cents
    $table->unsignedInteger('discount_percentage')->default(0);
    
    $table->timestamps();
});
```

## Core Models

### 6. Build Subscription Model

**Key features from original forward system:**

- `forward()` method for EFT subscriptions
- `starts_at` accessor (calculated from `ends_at`)
- `calculateEndsAt()` and `calculateStartsAt()` methods
- All Cashier-inspired helpers (active, onTrial, etc.)

**src/Models/Subscription.php:**

```php
namespace Eugenefvdm\BillableSubscriptions\Models;

class Subscription extends Model
{
    protected $guarded = [];
    
    protected $casts = [
        'start_date' => 'datetime',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'paused_from' => 'datetime',
        'status' => SubscriptionStatus::class,
        'payment_method' => PaymentMethod::class,
    ];
    
    // Relationships
    public function billable(): MorphTo
    public function invoices(): HasMany
    
    // Scopes
    public function scopeCard($query)
    public function scopeEft($query)
    public function scopeActive($query)
    public function scopeOnTrial($query)
    public function scopeOnGracePeriod($query)
    
    // State checkers (from Cashier)
    public function valid(): bool
    public function active(): bool
    public function onTrial(): bool
    public function onGracePeriod(): bool
    public function cancelled(): bool
    public function ended(): bool
    
    // EFT-specific: The Forward Method
    public function forward(): void
    {
        // Only process EFT subscriptions
        if ($this->payment_method !== PaymentMethod::Eft) {
            return;
        }
        
        // Only forward if current period has ended
        if ($this->starts_at >= now()) {
            return;
        }
        
        // Create invoice for the period
        $invoice = InvoiceService::createSubscriptionInvoice($this);
        
        // Generate PDF
        InvoiceService::createPdf($invoice);
        
        // Email invoice
        Mail::to($this->billable->email)
            ->send(new InvoiceCreated($invoice));
        
        // Move ends_at forward by one interval
        $this->ends_at = $this->calculateEndsAt(
            $this->ends_at,
            $this->intervalFromType()
        );
        
        $this->save();
        
        Log::info("Forwarded subscription {$this->id}, new ends_at: {$this->ends_at}");
    }
    
    // Accessors
    public function getStartsAtAttribute(): Carbon
    {
        if (!$this->ends_at) {
            throw new Exception("Cannot calculate starts_at without ends_at");
        }
        
        return $this->calculateStartsAt(
            $this->ends_at,
            $this->intervalFromType()
        );
    }
    
    public function getPeriodDescriptionAttribute(): string
    {
        $plan = $this->plan();
        return $plan['name'] . ' ' 
            . $this->starts_at->format('Y-m-d') 
            . ' to ' 
            . $this->ends_at->format('Y-m-d');
    }
    
    // Date calculations
    public static function calculateEndsAt(Carbon $start, PlanInterval $interval): Carbon
    {
        return match ($interval) {
            PlanInterval::Daily => $start->copy()->addDay(),
            PlanInterval::Weekly => $start->copy()->addWeek(),
            PlanInterval::Monthly => $start->copy()->addMonth(),
            PlanInterval::Quarterly => $start->copy()->addMonths(3),
            PlanInterval::Yearly => $start->copy()->addYear(),
        };
    }
    
    protected function calculateStartsAt(Carbon $end, PlanInterval $interval): Carbon
    {
        return match ($interval) {
            PlanInterval::Daily => $end->copy()->subDay(),
            PlanInterval::Weekly => $end->copy()->subWeek(),
            PlanInterval::Monthly => $end->copy()->subMonth(),
            PlanInterval::Quarterly => $end->copy()->subMonths(3),
            PlanInterval::Yearly => $end->copy()->subYear(),
        };
    }
    
    // Plan helpers
    public function plan(): array
    {
        $plans = config('billable.billables.user.plans');
        return collect($plans)->firstWhere('type', $this->type);
    }
    
    public function intervalFromType(): PlanInterval
    {
        $plan = $this->plan();
        return PlanInterval::from($plan['interval']);
    }
    
    // Cancellation
    public function cancel(): static
    {
        $this->update([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => $this->onTrial() ? $this->trial_ends_at : now(),
        ]);
        
        return $this;
    }
    
    public function cancelNow(): static
    {
        $this->update([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => now(),
        ]);
        
        return $this;
    }
}
```

### 7. Build Invoice Model

**Single source of truth: `due_at` date**

```php
namespace Eugenefvdm\BillableSubscriptions\Models;

class Invoice extends Model
{
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
    
    // Relationships
    public function billable(): MorphTo
    public function subscription(): BelongsTo
    public function items(): HasMany
    
    // Scopes
    public function scopeUnpaid($query)
    {
        return $query->where('status', InvoiceStatus::Unpaid);
    }
    
    // State checkers
    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }
    
    public function isOverdue(): bool
    {
        return $this->due_at < now() && !$this->isPaid();
    }
    
    // Overdue calculations (based on due_at - single source of truth)
    public function getDaysPastDueAttribute(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }
        return now()->diffInDays($this->due_at);
    }
    
    // Reminder period checks
    public function getInFirstReminderPeriodAttribute(): bool
    {
        $first = config('billable.reminders.first_reminder_days');
        $second = config('billable.reminders.second_reminder_days');
        
        return $this->days_past_due >= $first 
            && $this->days_past_due < $second;
    }
    
    public function getInSecondReminderPeriodAttribute(): bool
    {
        $second = config('billable.reminders.second_reminder_days');
        $third = config('billable.reminders.third_reminder_days');
        
        return $this->days_past_due >= $second 
            && $this->days_past_due < $third;
    }
    
    public function getInThirdReminderPeriodAttribute(): bool
    {
        $third = config('billable.reminders.third_reminder_days');
        return $this->days_past_due >= $third;
    }
    
    // Payment
    public function markAsPaid(Carbon $paidAt = null): void
    {
        $this->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $paidAt ?? now(),
        ]);
    }
    
    // PDF
    public function pdfPath(): string
    {
        $path = config('billable.invoice.pdf_storage_path');
        return storage_path("app/{$path}/invoice-{$this->id}.pdf");
    }
    
    // Auto-calculate total when items change
    public function recalculateTotal(): void
    {
        $this->subtotal = $this->items->sum('line_total');
        $this->total = $this->subtotal - ($this->subtotal * $this->discount_percentage / 10000);
        $this->save();
    }
}
```

### 8. Build InvoiceItem Model

```php
namespace Eugenefvdm\BillableSubscriptions\Models;

class InvoiceItem extends Model
{
    protected $guarded = [];
    
    // Relationships
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
    
    // Boot observer to auto-calculate and update invoice
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
```

## Enums

### 9. Create Enums

**PaymentMethod.php:**

```php
enum PaymentMethod: string
{
    case Card = 'card';
    case Eft = 'eft';
}
```

**PlanInterval.php:**

```php
enum PlanInterval: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
}
```

**SubscriptionStatus.php:**

```php
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Trialing = 'trialing';
    case Paused = 'paused';
}
```

**InvoiceStatus.php:**

```php
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Void = 'void';
}
```

## Billable Trait

### 10. Build Billable Trait

**Core API for both payment methods:**

```php
namespace Eugenefvdm\BillableSubscriptions\Traits;

trait Billable
{
    // Relationships
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable');
    }
    
    public function invoices(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'billable');
    }
    
    // Card Subscriptions (Payfast)
    public function newSubscription(string $name, string $type): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $name, $type);
    }
    
    public function subscription(string $name = 'default'): ?Subscription
    {
        return $this->subscriptions()
            ->where('name', $name)
            ->where('payment_method', 'card')
            ->first();
    }
    
    public function subscribed(string $name = 'default'): bool
    {
        $subscription = $this->subscription($name);
        return $subscription && $subscription->valid();
    }
    
    // EFT Subscriptions
    public function newEftSubscription(string $name, string $type): EftSubscriptionBuilder
    {
        return new EftSubscriptionBuilder($this, $name, $type);
    }
    
    public function eftSubscription(string $name = 'default'): ?Subscription
    {
        return $this->subscriptions()
            ->where('name', $name)
            ->where('payment_method', 'eft')
            ->first();
    }
    
    // Trials
    public function onTrial(string $name = 'default'): bool
    {
        $subscription = $this->subscription($name) ?? $this->eftSubscription($name);
        return $subscription && $subscription->onTrial();
    }
    
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }
    
    public function trialEndsAt(): ?Carbon
    {
        return $this->trial_ends_at;
    }
    
    // Invoice helpers
    public function findInvoice(string $uuid): ?Invoice
    {
        return $this->invoices()->where('uuid', $uuid)->first();
    }
}
```

## Service Layer

### 11. Build InvoiceService

**Migrate from original application:**

```php
namespace Eugenefvdm\BillableSubscriptions\Services;

class InvoiceService
{
    public static function createSubscriptionInvoice(Subscription $subscription): Invoice
    {
        $plan = $subscription->plan();
        $dueAt = now()->addDays(config('billable.invoice.default_due_days', 7));
        
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
            'unit_price' => $plan['price'],
        ]);
        
        return $invoice->fresh();
    }
    
    public static function createPdf(Invoice $invoice, bool $stream = false)
    {
        $pdf = Pdf::loadView('billable::pdf.invoice', [
            'invoice' => $invoice,
        ]);
        
        if ($stream) {
            return $pdf->stream("Invoice-{$invoice->id}.pdf");
        }
        
        $pdf->save($invoice->pdfPath());
        
        return $pdf;
    }
    
    public static function sendInvoiceEmail(Invoice $invoice): void
    {
        Mail::to($invoice->billable->email)
            ->send(new InvoiceCreated($invoice));
    }
}
```

### 12. Build PayfastService

**Handle Payfast API interactions:**

```php
namespace Eugenefvdm\BillableSubscriptions\Services;

class PayfastService
{
    public function createOnsitePayment(array $data): string
    {
        // Generate Payfast onsite payment identifier
        // Call Payfast API
        // Return identifier for modal
    }
    
    public function validateWebhook(Request $request): bool
    {
        // Validate Payfast signature
        // Verify source IP
        // Return true if valid
    }
    
    public function cancelSubscription(string $token): bool
    {
        // Call Payfast API to cancel subscription
        // Return success status
    }
}
```

## Commands

### 13. Build SubscriptionForwardCommand

**The heart of EFT billing:**

```php
namespace Eugenefvdm\BillableSubscriptions\Commands;

class SubscriptionForwardCommand extends Command
{
    protected $signature = 'subscriptions:forward';
    protected $description = 'Forward EFT subscriptions and generate invoices';
    
    public function handle(): int
    {
        $subscriptions = Subscription::eft()
            ->where('status', 'active')
            ->get();
        
        $forwarded = 0;
        
        foreach ($subscriptions as $subscription) {
            $subscription->forward();
            $forwarded++;
        }
        
        $this->info("Forwarded {$forwarded} EFT subscriptions");
        
        return Command::SUCCESS;
    }
}
```

### 14. Build CheckOverdueInvoicesCommand

**Simple reminder system - no automatic status changes:**

```php
namespace Eugenefvdm\BillableSubscriptions\Commands;

class CheckOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:check-overdue';
    protected $description = 'Send payment reminders for overdue EFT invoices';
    
    public function handle(): int
    {
        $invoices = Invoice::unpaid()
            ->whereHas('subscription', fn($q) => $q->where('payment_method', 'eft'))
            ->where('due_at', '<', now())
            ->get();
        
        $remindersSent = 0;
        
        foreach ($invoices as $invoice) {
            // First reminder
            if ($invoice->in_first_reminder_period && !$invoice->first_reminder_sent_at) {
                Mail::to($invoice->billable->email)->send(new InvoiceReminder($invoice, 'first'));
                $invoice->update(['first_reminder_sent_at' => now()]);
                $remindersSent++;
            }
            
            // Second reminder
            if ($invoice->in_second_reminder_period && !$invoice->second_reminder_sent_at) {
                Mail::to($invoice->billable->email)->send(new InvoiceReminder($invoice, 'second'));
                $invoice->update(['second_reminder_sent_at' => now()]);
                $remindersSent++;
            }
            
            // Third reminder
            if ($invoice->in_third_reminder_period && !$invoice->third_reminder_sent_at) {
                Mail::to($invoice->billable->email)->send(new InvoiceReminder($invoice, 'third'));
                $invoice->update(['third_reminder_sent_at' => now()]);
                $remindersSent++;
            }
        }
        
        $this->info("Sent {$remindersSent} reminders for {$invoices->count()} overdue invoices");
        
        return Command::SUCCESS;
    }
}
```

## Builders

### 15. Build SubscriptionBuilder (Card)

```php
namespace Eugenefvdm\BillableSubscriptions\Builders;

class SubscriptionBuilder
{
    protected int $trialDays = 0;
    protected bool $skipTrial = false;
    
    public function __construct(
        protected Model $billable,
        protected string $name,
        protected string $type,
    ) {}
    
    public function trialDays(int $days): self
    {
        $this->trialDays = $days;
        return $this;
    }
    
    public function skipTrial(): self
    {
        $this->skipTrial = true;
        return $this;
    }
    
    public function create(): Subscription
    {
        $trialEndsAt = null;
        if (!$this->skipTrial && $this->trialDays > 0) {
            $trialEndsAt = now()->addDays($this->trialDays);
        }
        
        // Create subscription record
        $subscription = $this->billable->subscriptions()->create([
            'name' => $this->name,
            'type' => $this->type,
            'payment_method' => PaymentMethod::Card,
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => $trialEndsAt,
        ]);
        
        // Initialize Payfast payment flow
        // This would redirect to Payfast modal
        // Webhook will update subscription with token and ends_at
        
        return $subscription;
    }
}
```

### 16. Build EftSubscriptionBuilder

```php
namespace Eugenefvdm\BillableSubscriptions\Builders;

class EftSubscriptionBuilder
{
    protected int $trialDays = 0;
    protected bool $skipTrial = false;
    protected ?Carbon $startDate = null;
    
    public function __construct(
        protected Model $billable,
        protected string $name,
        protected string $type,
    ) {}
    
    public function trialDays(int $days): self
    {
        $this->trialDays = $days;
        return $this;
    }
    
    public function skipTrial(): self
    {
        $this->skipTrial = true;
        return $this;
    }
    
    public function startDate(Carbon $date): self
    {
        $this->startDate = $date;
        return $this;
    }
    
    public function create(): Subscription
    {
        $startDate = $this->startDate ?? now();
        $plan = collect(config('billable.billables.user.plans'))
            ->firstWhere('type', $this->type);
        
        $interval = PlanInterval::from($plan['interval']);
        $endsAt = Subscription::calculateEndsAt($startDate, $interval);
        
        $trialEndsAt = null;
        if (!$this->skipTrial && $this->trialDays > 0) {
            $trialEndsAt = now()->addDays($this->trialDays);
        }
        
        // Create EFT subscription
        $subscription = $this->billable->subscriptions()->create([
            'name' => $this->name,
            'type' => $this->type,
            'payment_method' => PaymentMethod::Eft,
            'status' => SubscriptionStatus::Active,
            'start_date' => $startDate,
            'ends_at' => $endsAt,
            'trial_ends_at' => $trialEndsAt,
        ]);
        
        // Create first invoice immediately
        $invoice = InvoiceService::createSubscriptionInvoice($subscription);
        InvoiceService::createPdf($invoice);
        InvoiceService::sendInvoiceEmail($invoice);
        
        return $subscription;
    }
}
```

## HTTP Layer

### 17. Build PayfastWebhookController

```php
namespace Eugenefvdm\BillableSubscriptions\Http\Controllers;

class PayfastWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        // Validate signature
        if (!PayfastService::validateWebhook($request)) {
            return response('Invalid signature', 403);
        }
        
        // Handle different webhook types
        $eventType = $request->input('event_type');
        
        match($eventType) {
            'subscription.created' => $this->handleSubscriptionCreated($request),
            'subscription.updated' => $this->handleSubscriptionUpdated($request),
            'subscription.cancelled' => $this->handleSubscriptionCancelled($request),
            'payment.success' => $this->handlePaymentSuccess($request),
            'payment.failed' => $this->handlePaymentFailed($request),
            default => Log::warning("Unknown Payfast event: {$eventType}"),
        };
        
        return response('Webhook handled', 200);
    }
    
    protected function handleSubscriptionCreated(Request $request)
    {
        // Find subscription by billable and update with Payfast data
    }
    
    protected function handlePaymentSuccess(Request $request)
    {
        // Create invoice record for successful payment
    }
    
    // ... other handlers
}
```

## Mail Layer

### 18. Build Mailables

**InvoiceCreated.php:**

```php
class InvoiceCreated extends Mailable
{
    public function __construct(public Invoice $invoice) {}
    
    public function content(): Content
    {
        return new Content(
            markdown: 'billable::mail.invoice-created',
        );
    }
    
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->invoice->pdfPath())
                ->as("Invoice-{$this->invoice->id}.pdf"),
        ];
    }
}
```

**InvoiceReminder.php:**

```php
class InvoiceReminder extends Mailable
{
    public function __construct(
        public Invoice $invoice,
        public string $reminderType // 'first', 'second', 'third'
    ) {}
    
    public function content(): Content
    {
        $view = "billable::mail.invoice-reminder-{$this->reminderType}";
        
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
                ->as("Invoice-{$this->invoice->id}.pdf"),
        ];
    }
}
```

## Views

### 19. Create PDF Invoice Template

**resources/views/pdf/invoice.blade.php:**

Migrate from original application with:

- Company details from config
- Billable details
- Invoice items table
- Totals with tax/discount
- Payment instructions (bank details for EFT)
- Professional styling

### 20. Create Email Templates

**resources/views/mail/invoice-created.blade.php:**

```blade
# Invoice Created

Hi {{ $invoice->billable->name }},

Your invoice is ready!

**Invoice #{{ $invoice->id }}**
**Amount:** R{{ number_format($invoice->total / 100, 2) }}
**Due Date:** {{ $invoice->due_at->format('F j, Y') }}

@if($invoice->subscription->payment_method === 'eft')
Please make payment via EFT to:
- Bank: Example Bank
- Account: 1234567890
- Reference: INV-{{ $invoice->id }}
@endif

[View Invoice]({{ route('invoices.show', $invoice->uuid) }})

Thanks!
```

**resources/views/mail/invoice-reminder-first.blade.php:**

```blade
# Friendly Payment Reminder

Hi {{ $invoice->billable->name }},

Just a friendly reminder that invoice **#{{ $invoice->id }}** is now {{ $daysPastDue }} days overdue.

**Amount Due:** R{{ number_format($amount / 100, 2) }}
**Due Date:** {{ $dueDate->format('F j, Y') }}

Please process your payment at your earliest convenience.

[View Invoice]({{ route('invoices.show', $invoice->uuid) }})
```

**resources/views/mail/invoice-reminder-second.blade.php:**

```blade
# Payment Reminder - Action Required

Hi {{ $invoice->billable->name }},

Invoice **#{{ $invoice->id }}** is now {{ $daysPastDue }} days overdue.

**Amount Due:** R{{ number_format($amount / 100, 2) }}
**Due Date:** {{ $dueDate->format('F j, Y') }}

Please settle this invoice to maintain your subscription.

[Pay Now]({{ route('invoices.show', $invoice->uuid) }})
```

**resources/views/mail/invoice-reminder-third.blade.php:**

```blade
# Final Payment Notice

Hi {{ $invoice->billable->name }},

This is our final reminder. Invoice **#{{ $invoice->id }}** is {{ $daysPastDue }} days overdue.

**Amount Due:** R{{ number_format($amount / 100, 2) }}
**Due Date:** {{ $dueDate->format('F j, Y') }}

Please contact us if you need to discuss payment arrangements.

[Urgent: Pay Now]({{ route('invoices.show', $invoice->uuid) }})
```

## Service Provider

### 21. Build BillableServiceProvider

```php
namespace Eugenefvdm\BillableSubscriptions;

class BillableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billable.php', 'billable');
    }
    
    public function boot(): void
    {
        // Migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'billable');
        
        // Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\SubscriptionForwardCommand::class,
                Commands\CheckOverdueInvoicesCommand::class,
            ]);
            
            // Publishables
            $this->publishes([
                __DIR__.'/../config/billable.php' => config_path('billable.php'),
            ], 'billable-config');
            
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'billable-migrations');
            
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/billable'),
            ], 'billable-views');
        }
        
        // Schedule commands
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            
            // Forward subscriptions
            $frequency = config('billable.forward.schedule', 'hourly');
            $schedule->command('subscriptions:forward')->$frequency();
            
            // Check overdue invoices daily
            $schedule->command('invoices:check-overdue')->daily();
        });
        
        // Routes (webhooks)
        Route::post('/payfast/webhook', [PayfastWebhookController::class, 'handleWebhook'])
            ->name('payfast.webhook');
    }
}
```

## Testing Strategy

### 22. Unit Tests

**tests/Unit/SubscriptionTest.php:**

- ✓ Test `calculateEndsAt()` for all intervals
- ✓ Test `calculateStartsAt()` for all intervals
- ✓ Test `starts_at` accessor calculates correctly
- ✓ Test `period_description` generates correct format
- ✓ Test status checkers (active, cancelled, onTrial, etc.)
- ✓ Test EFT scope filters only EFT subscriptions
- ✓ Test Card scope filters only card subscriptions

**tests/Unit/InvoiceTest.php:**

- ✓ Test invoice total calculation from items
- ✓ Test discount percentage applied correctly
- ✓ Test isPaid() status checker
- ✓ Test isOverdue() date logic
- ✓ Test days_past_due calculation
- ✓ Test in_first_reminder_period logic
- ✓ Test in_second_reminder_period logic
- ✓ Test in_third_reminder_period logic

**tests/Unit/InvoiceItemTest.php:**

- ✓ Test line_total calculation (quantity × unit_price)
- ✓ Test observer updates invoice total on save

### 23. Feature Tests: EFT Subscriptions

**tests/Feature/EftSubscriptionTest.php:**

```php
✓ test_user_can_create_eft_subscription()
  - Create EFT subscription
  - Assert payment_method = 'eft'
  - Assert payfast_token is NULL
  - Assert status = 'active'
  - Assert ends_at set correctly based on interval

✓ test_eft_subscription_creates_initial_invoice()
  - Create EFT subscription
  - Assert invoice created immediately
  - Assert invoice has correct period description
  - Assert invoice amount matches plan price
  - Assert PDF generated
  - Assert email sent

✓ test_forward_command_processes_eft_subscriptions()
  - Create EFT subscription with ends_at in past
  - Run forward command
  - Assert new invoice created
  - Assert ends_at moved forward by one interval
  - Assert email sent

✓ test_forward_command_skips_card_subscriptions()
  - Create card subscription
  - Run forward command
  - Assert no changes to card subscription

✓ test_forward_only_processes_when_period_ends()
  - Create EFT subscription with ends_at in future
  - Run forward command
  - Assert no invoice created
  - Assert ends_at unchanged

✓ test_eft_subscription_calculates_starts_at_correctly()
  - Create EFT subscription
  - Assert starts_at accessor returns correct date (ends_at - interval)

✓ test_cancelled_eft_subscription_not_forwarded()
  - Create and cancel EFT subscription
  - Run forward command
  - Assert no new invoices
  - Assert ends_at unchanged
```

### 24. Feature Tests: Reminder System

**tests/Feature/InvoiceReminderTest.php:**

```php
✓ test_first_reminder_sent_when_in_first_period()
  - Create EFT invoice 3 days overdue
  - Run check-overdue command
  - Assert first_reminder_sent_at is set
  - Assert email sent
  - Assert only first reminder sent

✓ test_second_reminder_sent_when_in_second_period()
  - Create EFT invoice 7 days overdue
  - Mark first reminder as sent
  - Run command
  - Assert second_reminder_sent_at is set
  - Assert second email sent

✓ test_third_reminder_sent_when_in_third_period()
  - Create EFT invoice 14 days overdue
  - Mark first/second reminders sent
  - Run command
  - Assert third_reminder_sent_at is set
  - Assert third email sent

✓ test_reminders_not_sent_twice()
  - Create overdue invoice
  - Run command (sends first reminder)
  - Run command again
  - Assert email sent only once
  - Assert first_reminder_sent_at unchanged

✓ test_card_subscriptions_ignored_by_reminder_command()
  - Create card subscription with unpaid invoice
  - Run check-overdue command
  - Assert no reminders sent

✓ test_paid_invoices_skipped()
  - Create EFT invoice, mark as paid
  - Run command
  - Assert no reminders sent

✓ test_no_reminder_if_not_overdue()
  - Create EFT invoice due tomorrow
  - Run command
  - Assert no reminders sent
```

### 25. Feature Tests: Card Subscriptions

**tests/Feature/CardSubscriptionTest.php:**

```php
✓ test_user_can_initiate_card_subscription()
  - Initiate card subscription
  - Assert payment_method = 'card'
  - Assert subscription created with active status

✓ test_webhook_updates_card_subscription()
  - Create card subscription
  - Simulate Payfast webhook
  - Assert payfast_token set
  - Assert ends_at updated from webhook

✓ test_forward_command_ignores_card_subscriptions()
  - Create card subscription
  - Run forward command
  - Assert subscription unchanged
```

### 26. Integration Tests

**tests/Feature/FullEftBillingCycleTest.php:**

```php
✓ test_complete_eft_billing_cycle()
  - User creates monthly EFT subscription
  - Assert initial invoice created and emailed
  - Fast-forward 1 month
  - Run forward command
  - Assert second invoice created
  - Assert ends_at moved to next month
  - Fast-forward 1 month
  - Run forward command again
  - Assert third invoice created
  - Assert subscription still active

✓ test_eft_subscription_with_late_payment_and_reminders()
  - Create monthly EFT subscription
  - Invoice created (due in 7 days)
  - Fast-forward 10 days (3 days overdue)
  - Run check-overdue command
  - Assert first reminder sent
  - Fast-forward to 14 days overdue
  - Run command again
  - Assert second reminder sent
  - Mark invoice as paid
  - Fast-forward 1 month
  - Run forward command
  - Assert new invoice created
  - Assert subscription continues normally
```

## Documentation

### 27. Write README.md

Sections:

- Installation
- Configuration
- Usage Examples
        - Card subscriptions (Payfast)
        - EFT subscriptions
        - Invoice management
        - Payment reminders
- Scheduling commands
- Webhook setup
- Testing
- Migration from old package
- API reference

### 28. Write UPGRADE.md

For users migrating from `fintechsystems/payfast-onsite-subscriptions`:

- Breaking changes
- New features
- Migration steps
- Data migration script examples

## Final Steps

### 29. Package Publishing

- Set up GitHub Actions for tests
- Configure Packagist auto-update
- Tag v1.0.0 release
- Archive old package with deprecation notice

### 30. Example Application

Create `billable-demo` repository:

- Fresh Laravel install
- Install package
- Seed with example users and subscriptions
- Demo both card and EFT flows
- Useful for testing and showcasing

---

## Key Design Principles

1. **Single Source of Truth**: Invoice `due_at` determines everything about overdue status
2. **No Automatic Status Changes**: Subscription status only changes via explicit actions (cancel, pause)
3. **Simple Reminder System**: Just send emails based on days past due - no complex business logic
4. **Payment Method Discriminator**: `payment_method` field clearly separates card vs EFT logic
5. **Forward-Driven Billing**: EFT subscriptions move forward in time, creating invoices as they go
6. **Webhook-Driven for Cards**: Payfast manages card subscriptions via webhooks

### To-dos

- [ ] Initialize package structure, composer.json, and base configuration file
- [ ] Create database migrations for subscriptions, invoices (with reminder fields), and invoice_items tables
- [ ] Create all enums (PaymentMethod, PlanInterval, SubscriptionStatus, InvoiceStatus)
- [ ] Build Subscription model with forward() method, date calculations, and Cashier-inspired helpers
- [ ] Build Invoice model with overdue logic based on due_at date and reminder period accessors
- [ ] Build InvoiceItem model with observer for auto-calculating totals
- [ ] Create Billable trait with unified API for card and EFT subscriptions
- [ ] Build SubscriptionBuilder and EftSubscriptionBuilder for fluent subscription creation
- [ ] Create InvoiceService with PDF generation, email sending, and subscription invoice creation
- [ ] Create PayfastService for API interactions and webhook validation
- [ ] Build SubscriptionForwardCommand to process EFT subscriptions on schedule
- [ ] Build CheckOverdueInvoicesCommand with simple reminder logic (no status changes)
- [ ] Build PayfastWebhookController to handle card subscription webhooks
- [ ] Create InvoiceCreated and InvoiceReminder (with 3 templates) mailables
- [ ] Create PDF invoice template and email templates (invoice-created, 3 reminder levels)
- [ ] Build BillableServiceProvider with migrations, views, commands, routes, and scheduled tasks
- [ ] Write unit tests for Subscription calculations, Invoice overdue logic, and InvoiceItem
- [ ] Write feature tests for EFT subscription creation, forwarding, and billing cycle
- [ ] Write feature tests for reminder system (no status changes, just emails)
- [ ] Write integration tests for complete EFT lifecycle with late payments
- [ ] Write feature tests for card subscriptions, webhooks, and Payfast integration
- [ ] Write comprehensive README.md and UPGRADE.md
- [ ] Set up GitHub Actions, publish to Packagist, and tag v1.0.0 release