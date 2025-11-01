<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('name')->default('default');
            $table->string('type'); // Billing interval: 'monthly', 'yearly', etc.
            $table->string('payment_method')->nullable();
            
            // Provider subscription token (For Payfast: this is the subscription token)
            $table->string('provider_id')->unique()->nullable();
            
            // Status (For Payfast: API status like 'ACTIVE', 'PAUSED', 'CANCELLED','UPSTREAM')
            $table->string('status');
            
            // EFT-specific: when the current billing period started
            $table->timestamp('start_date')->nullable();
            
            // Trial and lifecycle dates
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('next_bill_at')->nullable(); // Payfast-specific: next billing date
            $table->timestamp('cancelled_at')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};