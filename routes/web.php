<?php

use Eugenefvdm\Billing\Components\Billing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/payfast/return', function() {
    Log::info("=== PAYFAST RETURN ROUTE HIT ===", [
        'full_url' => request()->fullUrl(),
        'query_params' => request()->query(),
        'all_params' => request()->all(),
        'referer' => request()->headers->get('referer'),
    ]);
    
    // Try return_to query parameter first (we append this to return_url)
    $returnUrl = request()->query('return_to');
    
    // Fallback: try custom_str4 (in case Payfast returns it)
    if (!$returnUrl) {
        $returnUrl = request()->query('custom_str4');
        Log::info("Trying custom_str4 fallback", ['custom_str4' => $returnUrl]);
    }
    
    if ($returnUrl) {
        $decodedUrl = urldecode($returnUrl);
        Log::info("Redirecting to return URL after successful payment", [
            'return_url' => $decodedUrl,
            'original_encoded' => $returnUrl,
        ]);
        return redirect($decodedUrl)->with('success', 'Payment completed successfully.');
    }
    
    Log::warning("No return URL found, redirecting to home", [
        'available_params' => request()->all(),
    ]);
    return redirect('/')->with('success', 'Payment completed successfully.');
});

Route::post('/payfast/notify', 'Eugenefvdm\Billing\Http\Controllers\WebhookController');

Route::post('/payfast/webhook', 'Eugenefvdm\Billing\Http\Controllers\WebhookController');

// Debug route to test if webhook endpoint is accessible
Route::get('/payfast/notify/test', function() {
    Log::info("=== PAYFAST WEBHOOK TEST ENDPOINT HIT ===", [
        'full_url' => request()->fullUrl(),
        'method' => request()->method(),
        'ip' => request()->ip(),
        'headers' => request()->headers->all(),
    ]);
    
    return response()->json([
        'status' => 'ok',
        'message' => 'Webhook endpoint is accessible',
        'url' => request()->fullUrl(),
        'test_mode_itn_url' => config('billing.payfast.test_mode_itn_url'),
        'test_mode' => config('billing.payfast.test_mode'),
        'expected_full_url' => (config('billing.payfast.test_mode') && config('billing.payfast.test_mode_itn_url'))
            ? config('billing.payfast.test_mode_itn_url') . '/payfast/notify'
            : config('app.url') . '/payfast/notify',
    ]);
});

Route::middleware(['auth','web'])
    ->get('/user/billing', Billing::class)
    ->name('user.billing');

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
        
        // Get return URL (where user came from)
        $returnUrl = request()->headers->get('referer', config('app.url'));
        
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
            // Custom fields - these get returned by Payfast to return_url/cancel_url
            'custom_str1' => $billable->getMorphClass(),
            'custom_int1' => $billable->getKey(),
            'custom_str3' => 'invoice:' . $invoice->uuid,
            'custom_str4' => $returnUrl, // Return URL to redirect back to
            'custom_str5' => $invoice->uuid, // Invoice UUID for easy access
        ];
        
        // Add URLs - all hardcoded to standard routes
        $baseUrl = config('app.url');
        $returnUrlParam = urlencode($returnUrl);
        $data['return_url'] = $baseUrl . '/payfast/return?return_to=' . $returnUrlParam;
        $data['cancel_url'] = route('invoices.cancel', $invoice->uuid);
        
        // ITN (webhook) auto-detects ngrok URL in test mode
        $testModeItnUrl = config('billing.payfast.test_mode_itn_url');
        $isTestMode = config('billing.payfast.test_mode');
        $itnUrl = ($isTestMode && !empty($testModeItnUrl))
            ? $testModeItnUrl
            : $baseUrl;
        $data['notify_url'] = $itnUrl . '/payfast/notify';
        
        Log::info("Invoice #{$invoice->id} payment URLs configured", [
            'return_url' => $data['return_url'],
            'cancel_url' => $data['cancel_url'],
            'notify_url' => $data['notify_url'],
            'test_mode' => $isTestMode,
            'test_mode_itn_url' => $testModeItnUrl,
            'itn_base_url' => $itnUrl,
            'app_url' => $baseUrl,
            'return_to_param' => $returnUrlParam,
            'original_return_url' => $returnUrl,
        ]);
        
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

Route::get('/invoices/{uuid}/cancel', function ($uuid) {
    Log::info("=== INVOICE PAYMENT CANCELLED ===", [
        'uuid' => $uuid,
        'url' => request()->fullUrl(),
        'ip' => request()->ip(),
        'query_params' => request()->query->all(),
    ]);
    
    try {
        $invoice = \Eugenefvdm\Billing\Invoice::where('uuid', $uuid)->firstOrFail();
        Log::info("Invoice found for cancellation", [
            'invoice_id' => $invoice->id,
            'uuid' => $invoice->uuid,
        ]);
        
        // Try to get return URL from query parameter (if Payfast passes it)
        // Otherwise fall back to billing page
        $returnUrl = request()->query('custom_str4');
        
        Log::info("Setting payment_cancelled flash message", [
            'return_url' => $returnUrl,
            'fallback_route' => route('user.billing'),
        ]);
        
        if ($returnUrl) {
            Log::info("Redirecting to return URL with payment_cancelled query param");
            // Append query parameter instead of using flash
            $separator = strpos($returnUrl, '?') !== false ? '&' : '?';
            return redirect($returnUrl . $separator . 'payment_cancelled=1')->with('payment_cancelled', 'Payment cancelled');
        }
        
        // Fallback: redirect to billing page
        Log::info("Redirecting to billing page with payment_cancelled query param");
        return redirect()->route('user.billing', ['payment_cancelled' => 1])->with('payment_cancelled', 'Payment cancelled');
    } catch (\Exception $e) {
        Log::error("Error handling invoice cancellation", [
            'uuid' => $uuid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        Log::info("Redirecting to billing page (error fallback) with payment_cancelled query param");
        return redirect()->route('user.billing', ['payment_cancelled' => 1])->with('payment_cancelled', 'Payment cancelled');
    }
})->name('invoices.cancel');
