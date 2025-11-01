<?php

use Eugenefvdm\Billing\Components\Billing;
use Eugenefvdm\Billing\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/payfast/return', function() {
    return view('vendor.payfast.return');
});

Route::get('/payfast/cancel', function() {
    return view('vendor.payfast.cancel');
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
