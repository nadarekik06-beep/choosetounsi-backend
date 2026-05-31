<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: add_resolution_type_to_complaints_and_fix_seller_orders_enum
 *
 * Changes:
 *
 *   1. complaints.resolution_type (nullable string)
 *      Determines what happens when the complaint is approved and the
 *      refund delivery task is completed:
 *
 *        'exchange'      → delivery agent brings a replacement item.
 *                          Stock is NOT restored (replacement was sent).
 *                          Order status stays 'delivered'.
 *                          No financial change.
 *
 *        'return_refund' → delivery agent collects the complained item(s),
 *                          returns them to the seller.
 *                          Stock IS restored for each returned item.
 *                          Commission on those items is reversed.
 *                          seller_order.subtotal is adjusted downward.
 *                          If ALL items returned → seller_order status = 'cancelled'.
 *                          If SOME items returned → seller_order status stays 'delivered'.
 *
 *        NULL            → legacy complaint (pre-feature). MarkOrderRefunded
 *                          falls back to original behaviour (cancel whole order).
 *
 *   2. seller_orders.status ENUM — add 'out_for_delivery'
 *      This value is already being written by DeliveryController (picked_up → out_for_delivery)
 *      but was missing from the original ENUM definition, causing silent failures
 *      on some MySQL configurations that enforce strict ENUM checking.
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add resolution_type to complaints ──────────────────────────
        Schema::table('complaints', function (Blueprint $table) {
            /**
             * What the customer wants as resolution.
             * Set during complaint submission (frontend step).
             * NULL for legacy complaints.
             */
            $table->enum('resolution_type', ['exchange', 'return_refund'])
                  ->nullable()
                  ->default(null)
                  ->after('complaint_type');
        });

        // ── 2. Add 'out_for_delivery' to seller_orders.status ENUM ────────
        // Also add 'refunded' if not already present (idempotent-safe via MODIFY).
        DB::statement("
            ALTER TABLE seller_orders
            MODIFY COLUMN status
            ENUM(
                'pending',
                'processing',
                'out_for_delivery',
                'completed',
                'delivered',
                'cancelled',
                'refunded'
            )
            NOT NULL
            DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('resolution_type');
        });

        // Revert seller_orders ENUM — only safe if no rows have the removed values
        DB::statement("
            ALTER TABLE seller_orders
            MODIFY COLUMN status
            ENUM('pending','processing','completed','delivered','cancelled','refunded')
            NOT NULL
            DEFAULT 'pending'
        ");
    }
};