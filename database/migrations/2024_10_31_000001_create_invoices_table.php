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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable();
            $table->morphs('billable');
            $table->uuid('uuid')->unique();
            
            $table->string('status')->default('draft'); // InvoiceStatus::Draft
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
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

