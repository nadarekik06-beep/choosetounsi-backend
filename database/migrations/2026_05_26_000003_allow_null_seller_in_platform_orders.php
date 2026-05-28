<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow platform brand products (seller_id = null) to be purchased.
 *
 * Problems fixed:
 *   1. seller_orders.seller_id       — was NOT NULL → made nullable
 *      Platform orders have no seller; the column must accept null.
 *
 *   2. order_items.seller_order_id   — was NOT NULL → made nullable
 *      Platform order items have no seller_order row; the FK must accept null.
 *
 * Both columns already have the correct foreign-key relationships;
 * we are only relaxing the NOT NULL constraint, not changing the FK itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. seller_orders.seller_id ────────────────────────────────────────
        // Drop the FK first (required by MySQL before modifying the column),
        // make the column nullable, then re-add the FK.
        Schema::table('seller_orders', function (Blueprint $table) {
            // Drop existing foreign key constraint (Laravel naming convention)
            // If your project used a custom name, replace accordingly.
            try {
                $table->dropForeign(['seller_id']);
            } catch (\Throwable $e) {
                // Constraint may already be missing — safe to continue
            }

            $table->unsignedBigInteger('seller_id')->nullable()->change();

            // Re-add FK with ON DELETE SET NULL so orphan rows are handled cleanly
            $table->foreign('seller_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });

        // ── 2. order_items.seller_order_id ────────────────────────────────────
        Schema::table('order_items', function (Blueprint $table) {
            try {
                $table->dropForeign(['seller_order_id']);
            } catch (\Throwable $e) {
                // Constraint may already be missing — safe to continue
            }

            $table->unsignedBigInteger('seller_order_id')->nullable()->change();

            // Re-add FK with ON DELETE SET NULL
            $table->foreign('seller_order_id')
                  ->references('id')
                  ->on('seller_orders')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        // ── Revert order_items.seller_order_id ────────────────────────────────
        Schema::table('order_items', function (Blueprint $table) {
            try {
                $table->dropForeign(['seller_order_id']);
            } catch (\Throwable $e) {}

            $table->unsignedBigInteger('seller_order_id')->nullable(false)->change();

            $table->foreign('seller_order_id')
                  ->references('id')
                  ->on('seller_orders')
                  ->onDelete('cascade');
        });

        // ── Revert seller_orders.seller_id ────────────────────────────────────
        Schema::table('seller_orders', function (Blueprint $table) {
            try {
                $table->dropForeign(['seller_id']);
            } catch (\Throwable $e) {}

            $table->unsignedBigInteger('seller_id')->nullable(false)->change();

            $table->foreign('seller_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }
};