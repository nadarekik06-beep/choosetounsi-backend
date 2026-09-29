<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet top-up intents. Hosted-page gateways (Konnect, Flouci) and manual
 * transfers complete asynchronously: a row is pending until the gateway callback
 * or an admin settles it. pending → paid | failed | cancelled, once; a paid
 * top-up is linked to exactly one wallet transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_top_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 3);
            $table->string('gateway', 30);
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled'])->default('pending');
            $table->string('reference')->nullable()->comment('Gateway payment id / transfer reference');
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('ad_wallet_transactions')->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['seller_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->unique(['gateway', 'reference'], 'uq_ad_top_ups_gateway_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_top_ups');
    }
};
