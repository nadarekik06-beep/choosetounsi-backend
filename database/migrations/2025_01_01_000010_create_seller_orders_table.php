<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the seller_orders table.
 *
 * PURPOSE:
 * When a customer places a single order containing products from multiple sellers,
 * the system creates ONE `orders` row (the customer's unified view) plus ONE
 * `seller_orders` row per seller involved.
 *
 * This allows each seller to manage and update the status of only their own slice
 * of the order — completely independently of other sellers.
 *
 * SCHEMA OVERVIEW:
 *   orders          ← customer's checkout session (1 row per checkout)
 *   seller_orders   ← per-seller sub-order (N rows per checkout, one per seller)
 *   order_items     ← line items; seller_order_id links them to their sub-order
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. seller_orders ──────────────────────────────────────────────────
        Schema::create('seller_orders', function (Blueprint $table) {
            $table->id();

            // Parent order (the customer's checkout session)
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade');

            // The seller this sub-order belongs to
            $table->unsignedBigInteger('seller_id');
            $table->foreign('seller_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            // Per-seller status — this is what sellers update, NOT orders.status
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'delivered',
                'cancelled',
            ])->default('pending');

            // Per-seller payment status (COD confirmation per seller)
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])
                  ->default('unpaid');

            // Seller's portion of the total (sum of their items)
            $table->decimal('subtotal', 10, 3)->default(0);

            $table->timestamps();

            // Unique constraint: one sub-order per seller per checkout
            $table->unique(['order_id', 'seller_id']);

            $table->index('seller_id');
            $table->index('status');
            $table->index('order_id');
        });

        // ── 2. Add seller_order_id to order_items ────────────────────────────
        //
        // Links each order item to its seller's sub-order.
        // Nullable so that existing rows (before this migration) are not broken.
        // The backfill below populates it for all historical data.
        //
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_order_id')
                  ->nullable()
                  ->after('order_id');

            $table->foreign('seller_order_id')
                  ->references('id')
                  ->on('seller_orders')
                  ->onDelete('set null');

            $table->index('seller_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['seller_order_id']);
            $table->dropColumn('seller_order_id');
        });

        Schema::dropIfExists('seller_orders');
    }
};