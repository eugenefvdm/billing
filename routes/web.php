<?php

use Eugenefvdm\Billing\Components\Billing;
use Eugenefvdm\Billing\Http\Controllers\WebhookController;
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
