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
            // $table->string('name')->default('default'); TODO Deprecate
            $table->string('type'); // Raw array based billing interval: '0|monthly', '0|yearly', etc.            
            // For Payfast: this is the subscription token and for EFT this will be null
            $table->string('provider_id')->unique()->nullable();            
            // Status (For Payfast: API status like 'ACTIVE', 'PAUSED', 'CANCELLED','UPSTREAM')
            $table->string('status');                    
            // Trial and lifecycle dates
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('next_bill_at')->nullable(); // Payfast specific next billing date sent in webbook return
            $table->timestamp('cancelled_at')->nullable(); // Vanity field for audit trail
            $table->string('payment_method')->nullable();
            
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