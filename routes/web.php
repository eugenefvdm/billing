<?php

use Eugenefvdm\Billing\Components\Billing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/payfast/return', function() {
    Log::debug("Payfast return route hit");
    // If we have an invoice UUID in the session, redirect to it
    $invoiceUuid = session('invoice_uuid');
    $returnUrl = session('payment_return_url', '/');
    
    if ($invoiceUuid) {
        session()->forget('invoice_uuid');
        return redirect()->route('invoices.show', $invoiceUuid)
            ->with('success', 'Payment completed successfully.');
    }
    
    session()->forget('payment_return_url');
    return redirect($returnUrl)->with('success', 'Payment completed successfully.');
});

Route::get('/payfast/cancel', function() {
    // If we have an invoice UUID in the session, redirect back to it
    $invoiceUuid = session('invoice_uuid');
    $returnUrl = session('payment_return_url', '/');
    
    if ($invoiceUuid) {
        session()->forget('invoice_uuid');
        return redirect()->route('invoices.show', $invoiceUuid)
            ->with('info', 'Payment was cancelled.');
    }
    
    session()->forget('payment_return_url');
    return redirect($returnUrl)->with('info', 'Payment was cancelled.');
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
        
        // Store invoice UUID and return URL in session for return/cancel redirects
        session([
            'invoice_uuid' => $invoice->uuid,
            'payment_return_url' => request()->headers->get('referer', '/'),
        ]);
        
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
            'custom_str3' => 'invoice:' . $invoice->uuid,
        ];
        
        // Add URLs
        $baseUrl = config('app.url');
        $data['return_url'] = $baseUrl . config('billing.payfast.return_url');
        $data['cancel_url'] = $baseUrl . config('billing.payfast.cancel_url');
        
        // ITN (webhook) needs ngrok URL in test mode
        $itnUrl = config('billing.payfast.test_mode') && config('billing.payfast.test_mode_itn_url')
            ? config('billing.payfast.test_mode_itn_url')
            : $baseUrl;
        $data['notify_url'] = $itnUrl . config('billing.payfast.notify_url');
        
        // Generate signature
        $signature = $payfast->generateApiSignature($data, $payfast->passphrase());
        $data['signature'] = $signature;
        
        // Determine Payfast host
        $pfHost = config('billing.payfast.test_mode') ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
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
