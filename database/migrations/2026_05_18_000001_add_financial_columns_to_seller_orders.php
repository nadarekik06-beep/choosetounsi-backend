<?php
// database/migrations/2026_05_18_000001_add_financial_columns_to_seller_orders.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds financial settlement columns to seller_orders.
 *
 * RULE: No recalculation ever. All amounts are snapshots.
 * RULE: Payout only possible after delivery_confirmed_at + money_received_at.
 *
 * delivery_fee         — fixed 8.000 DT per seller_order (platform charges customer)
 * commission_amount    — sum of order_items.commission_amount for this seller_order
 * seller_net_amount    — sum of order_items.seller_amount for this seller_order
 * platform_profit      — commission_amount (what admin keeps from products)
 * delivery_fee_revenue — delivery fee collected (platform keeps this too)
 *
 * delivery_confirmed_at — delivery guy marked as "delivered"
 * money_received_at     — admin confirmed cash in hand
 * payout_status         — pending → ready → paid → cancelled
 * settlement_batch_id   — FK to settlement_batches
 * settled_at            — when seller was paid
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {

            // ── Financial snapshot columns ──────────────────────────────────
            // These are computed once from order_items at order creation time
            // and stored here for fast querying without joins.

            if (!Schema::hasColumn('seller_orders', 'commission_amount')) {
                $table->decimal('commission_amount', 10, 3)
                      ->default(0)
                      ->after('subtotal')
                      ->comment('Sum of order_items.commission_amount — platform fee');
            }

            if (!Schema::hasColumn('seller_orders', 'seller_net_amount')) {
                $table->decimal('seller_net_amount', 10, 3)
                      ->default(0)
                      ->after('commission_amount')
                      ->comment('Sum of order_items.seller_amount — what seller receives');
            }

            if (!Schema::hasColumn('seller_orders', 'delivery_fee')) {
                $table->decimal('delivery_fee', 8, 3)
                      ->default(8.000)
                      ->after('seller_net_amount')
                      ->comment('Fixed 8 DT delivery fee (platform revenue)');
            }

            if (!Schema::hasColumn('seller_orders', 'platform_profit')) {
                $table->decimal('platform_profit', 10, 3)
                      ->default(0)
                      ->after('delivery_fee')
                      ->comment('commission_amount + delivery_fee — total admin revenue');
            }

            // ── Delivery money flow columns ─────────────────────────────────

            if (!Schema::hasColumn('seller_orders', 'delivery_confirmed_at')) {
                $table->timestamp('delivery_confirmed_at')
                      ->nullable()
                      ->after('platform_profit')
                      ->comment('When delivery guy marked status = delivered');
            }

            if (!Schema::hasColumn('seller_orders', 'money_received_at')) {
                $table->timestamp('money_received_at')
                      ->nullable()
                      ->after('delivery_confirmed_at')
                      ->comment('When admin confirmed cash received from delivery company');
            }

            if (!Schema::hasColumn('seller_orders', 'money_received_by')) {
                $table->unsignedBigInteger('money_received_by')
                      ->nullable()
                      ->after('money_received_at')
                      ->comment('Admin user_id who confirmed cash receipt');
            }

            // ── Settlement columns ──────────────────────────────────────────

            if (!Schema::hasColumn('seller_orders', 'payout_status')) {
                $table->enum('payout_status', ['pending', 'ready', 'paid', 'cancelled'])
                      ->default('pending')
                      ->after('money_received_by')
                      ->comment(
                          'pending=awaiting delivery | ready=money in hand, can settle | ' .
                          'paid=seller paid | cancelled=refunded or cancelled order'
                      );
            }

            if (!Schema::hasColumn('seller_orders', 'settlement_batch_id')) {
                $table->unsignedBigInteger('settlement_batch_id')
                      ->nullable()
                      ->after('payout_status')
                      ->comment('FK to settlement_batches — set when included in a batch');
            }

            if (!Schema::hasColumn('seller_orders', 'settled_at')) {
                $table->timestamp('settled_at')
                      ->nullable()
                      ->after('settlement_batch_id')
                      ->comment('When the seller was actually paid');
            }

            // ── Indexes ─────────────────────────────────────────────────────
            $table->index('payout_status');
            $table->index('settlement_batch_id');
            $table->index('money_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn([
                'commission_amount',
                'seller_net_amount',
                'delivery_fee',
                'platform_profit',
                'delivery_confirmed_at',
                'money_received_at',
                'money_received_by',
                'payout_status',
                'settlement_batch_id',
                'settled_at',
            ]);
        });
    }
};