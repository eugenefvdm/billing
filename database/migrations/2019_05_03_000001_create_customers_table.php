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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            // The original field below in Cashier Paddle is paddle_id but we are going to use a more generic provider_id
            // Nullable because we might not have a provider customer ID yet (especially for EFT)
            $table->string('provider_id')->unique()->nullable();
            $table->string('name');
            $table->string('email');
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('available_payment_methods')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};