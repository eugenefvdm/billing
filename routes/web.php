<?php

use Eugenefvdm\Billing\Components\Billing;
use Eugenefvdm\Billing\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/payfast/return', function() {
    return redirect()->route('settings.billing')
        ->with('success', 'Payment completed successfully.');
});

Route::get('/payfast/cancel', function() {
    return redirect()->route('settings.billing')
        ->with('info', 'Payment was cancelled.');
});

Route::post('/payfast/notify', 'Eugenefvdm\Billing\Http\Controllers\WebhookController');

Route::post('/payfast/webhook', 'Eugenefvdm\Billing\Http\Controllers\WebhookController');

Route::middleware(['auth','web'])
    ->get('/settings/billing', Billing::class)
    ->name('settings.billing');

// Invoice routes (no auth required - secured by UUID)
Route::get('/invoices/{uuid}', function ($uuid) {
    Log::info("Invoice show route hit", [
        'uuid' => $uuid,
        'url' => request()->fullUrl(),
        'ip' => request()->ip(),
    ]);
    
    try {
        $invoice = \Eugenefvdm\Billing\Invoice::where('uuid', $uuid)->firstOrFail();
        Log::info("Invoice found", [
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->uuid,
        ]);
        return view('billing::invoices.show', ['invoice' => $invoice]);
    } catch (\Exception $e) {
        Log::error("Error loading invoice", [
            'uuid' => $uuid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        throw $e;
    }
})->name('invoices.show');

Route::get('/invoices/{uuid}/download', function ($uuid) {
    Log::info("Invoice download route hit", [
        'uuid' => $uuid,
        'url' => request()->fullUrl(),
        'ip' => request()->ip(),
    ]);
    
    try {
        $invoice = \Eugenefvdm\Billing\Invoice::where('uuid', $uuid)->firstOrFail();
        Log::info("Invoice found for download", [
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->uuid,
        ]);
        
        $pdf = \Eugenefvdm\Billing\Services\InvoiceService::createPdf($invoice, true);
        Log::info("PDF generated successfully", [
            'invoice_id' => $invoice->id,
        ]);
        
        return $pdf;
    } catch (\Exception $e) {
        Log::error("Error generating PDF", [
            'uuid' => $uuid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        throw $e;
    }
})->name('invoices.download');

Route::get('/invoices/{uuid}/pay', function ($uuid) {
    Log::info("Invoice payment route hit", [
        'uuid' => $uuid,
        'url' => request()->fullUrl(),
        'ip' => request()->ip(),
    ]);
    
    try {
        $invoice = \Eugenefvdm\Billing\Invoice::where('uuid', $uuid)->firstOrFail();
        
        if ($invoice->isPaid()) {
            return redirect()->route('invoices.show', $invoice->uuid)
                ->with('error', 'This invoice has already been paid.');
        }
        
        Log::info("Invoice found for payment", [
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->uuid,
        ]);
        
        $billable = $invoice->billable;
        
        // Extract first and last name from billable name
        $nameParts = explode(' ', $billable->name ?? '', 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';
        
        $payfast = app('payfast');
        
        // Prepare payment data
        $amount = $invoice->total / 100; // Convert from cents to rands
        $item = [
            'name' => "Invoice #{$invoice->id}",
            'description' => $invoice->items->first()->description ?? "Invoice #{$invoice->id}",
        ];
        $user = [
            'email' => $billable->email ?? $billable->payfastEmail(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile_phone_number' => $billable->mobile_phone_number ?? '',
        ];
        
        // Generate payment ID (similar to Order::generate() but without requiring auth)
        $paymentId = $invoice->id . '-' . \Carbon\Carbon::now()->format('YmdHis');
        
        // Generate payment form data
        $data = [
            'merchant_id' => $payfast->merchantId(),
            'merchant_key' => $payfast->merchantKey(),
            'name_first' => $user['first_name'],
            'name_last' => $user['last_name'],
            'email_address' => $user['email'],
            'cell_number' => $user['mobile_phone_number'],
            'm_payment_id' => $paymentId,
            'amount' => $amount,
            'item_name' => $item['name'],
            'item_description' => $item['description'],
            // Add invoice identifier for webhook processing
            'custom_str1' => $billable->getMorphClass(),
            'custom_int1' => $billable->getKey(),
            'custom_str3' => 'invoice:' . $invoice->uuid, // Invoice identifier
        ];
        
        // Add return URLs
        // For traditional payments:
        // - return/cancel URLs should point to the application (APP_URL)
        // - notify URL (webhook) should point to the webhook URL (ngrok in test mode)
        $testMode = config('billing.payfast.test_mode');
        
        // Return and cancel URLs use the application URL
        $callbackUrl = $testMode 
            ? config('billing.payfast.test_mode_callback_url', config('app.url'))
            : config('billing.payfast.callback_url', config('app.url'));
        
        // Notify URL (webhook) uses the webhook URL (ngrok in test mode)
        $webhookUrl = $testMode
            ? config('billing.payfast.test_mode_webhook_url', config('app.url'))
            : config('billing.payfast.webhook_url', config('app.url'));
        
        $data['return_url'] = $callbackUrl . config('billing.payfast.return_url');
        $data['cancel_url'] = $callbackUrl . config('billing.payfast.cancel_url');
        $data['notify_url'] = $webhookUrl . config('billing.payfast.notify_url');
        
        // Generate signature
        $signature = $payfast->generateApiSignature($data, $payfast->passphrase());
        $data['signature'] = $signature;
        
        // Determine Payfast host
        $pfHost = $testMode ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
        $actionUrl = "https://{$pfHost}/eng/process";
        
        return view('billing::payfast.payment', [
            'invoice' => $invoice,
            'actionUrl' => $actionUrl,
            'formData' => $data,
        ]);
    } catch (\Exception $e) {
        Log::error("Error processing invoice payment", [
            'uuid' => $uuid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        throw $e;
    }
})->name('invoices.pay');
