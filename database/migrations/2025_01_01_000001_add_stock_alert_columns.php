<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds stock-alert deduplication columns to products and product_variants.
 *
 * WHY these columns exist
 * ────────────────────────
 * Stock can cross a threshold multiple times (e.g. seller restocks then
 * sells again). Without deduplication, the seller would receive a flood
 * of identical notifications. We prevent this by recording:
 *
 *   last_low_stock_notified_at   — timestamp of the last "Low Stock" alert
 *   last_out_of_stock_notified_at — timestamp of the last "Out of Stock" alert
 *
 * StockAlertService checks these before sending. A new notification is only
 * sent if:
 *   1. No prior notification of that type exists, OR
 *   2. The stock previously recovered ABOVE the threshold (reset by observer),
 *      meaning a fresh crossing has occurred.
 *
 * low_stock_threshold (products only):
 *   Per-product override. NULL = use config('stock.low_stock_threshold').
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── products ───────────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('low_stock_threshold')
                  ->nullable()
                  ->after('stock')
                  ->comment('Per-product override. NULL = use config(stock.low_stock_threshold)');

            $table->timestamp('last_low_stock_notified_at')
                  ->nullable()
                  ->after('low_stock_threshold')
                  ->comment('Null = no notification sent yet or stock recovered above threshold');

            $table->timestamp('last_out_of_stock_notified_at')
                  ->nullable()
                  ->after('last_low_stock_notified_at');
        });

        // ── product_variants ───────────────────────────────────────────────
        Schema::table('product_variants', function (Blueprint $table) {
            $table->timestamp('last_low_stock_notified_at')
                  ->nullable()
                  ->after('stock');

            $table->timestamp('last_out_of_stock_notified_at')
                  ->nullable()
                  ->after('last_low_stock_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'low_stock_threshold',
                'last_low_stock_notified_at',
                'last_out_of_stock_notified_at',
            ]);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'last_low_stock_notified_at',
                'last_out_of_stock_notified_at',
            ]);
        });
    }
};