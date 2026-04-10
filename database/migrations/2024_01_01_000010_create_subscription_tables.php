<?php
// database/migrations/2024_01_01_000010_create_subscription_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Subscription payments log ─────────────────────────────────────────
        // One row per successful upgrade payment.
        // We do NOT create a separate subscriptions table — the active plan
        // lives on seller_applications.plan (already exists).
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('plan', ['red', 'black']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('TND');
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('succeeded');
            // For mock payments we store simulated card last4
            $table->string('card_last4', 4)->nullable();
            $table->string('cardholder_name')->nullable();
            // If you later integrate real Stripe, store the PaymentIntent ID here
            $table->string('stripe_payment_intent_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};