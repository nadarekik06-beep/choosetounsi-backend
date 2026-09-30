<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual (WhatsApp) payment requests: a seller asks to top up the ad wallet or
 * to move to a paid plan, pays by D17 / bank transfer outside the app, and an
 * admin approves (wallet credited / plan switched, once) or rejects the request.
 * Every step is written to payment_request_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique();                   // CT-4F7K2
            $table->enum('type', ['wallet_topup', 'plan_upgrade']);
            $table->enum('source', ['seller', 'admin'])->default('seller'); // admin = direct action, approved at once
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');

            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            // Snapshot of the seller at request time (what the WhatsApp message said).
            $table->string('store_name')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_email')->nullable();
            $table->string('seller_phone', 40)->nullable();
            $table->decimal('wallet_balance', 12, 3)->nullable();

            $table->decimal('amount', 12, 3);                               // requested amount / plan price
            $table->string('current_plan', 30)->nullable();
            $table->string('requested_plan', 30)->nullable();
            $table->enum('billing_period', ['monthly', 'yearly'])->nullable();
            $table->text('message')->nullable();                            // pre-filled WhatsApp text

            // Decision
            $table->decimal('amount_received', 12, 3)->nullable();
            $table->enum('payment_method', ['d17', 'bank_transfer', 'cash', 'other'])->nullable();
            $table->string('transaction_reference', 100)->nullable();
            $table->text('admin_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // What the approval produced
            $table->foreignId('ad_top_up_id')->nullable()->unique()->constrained('ad_top_ups')->nullOnDelete();
            $table->foreignId('subscription_payment_id')->nullable()->unique()->constrained('subscription_payments')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['seller_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('payment_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 20);                               // seller | admin | system
            $table->string('action', 30);                                   // created | cancelled | approved | rejected
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_logs');
        Schema::dropIfExists('payment_requests');
    }
};
