<?php
// database/migrations/xxxx_create_wallet_transactions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // positive = credit (top-up, refund)
            // negative = debit (order payment)
            $table->decimal('amount', 12, 3);

            $table->enum('type', ['credit', 'debit']);

            // What caused this transaction
            $table->enum('reason', [
                'order_payment',   // deducted when order placed with wallet
                'order_refund',    // credited back when order cancelled
                'admin_top_up',    // admin manually tops up balance
                'manual_debit',    // admin manually deducts
            ]);

            // Link back to the order that caused this transaction (if any)
            $table->foreignId('order_id')
                  ->nullable()
                  ->constrained('orders')
                  ->onDelete('set null');

            $table->text('note')->nullable();

            // Balance snapshot AFTER this transaction — useful for audit trail
            $table->decimal('balance_after', 12, 3)->default(0);

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};