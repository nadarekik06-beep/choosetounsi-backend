<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable, non-spammy low-stock alerts.
 *
 *   users.stock_alerts_enabled   low-stock alerts on/off (out-of-stock alerts are always sent)
 *   users.stock_alert_threshold  shop default threshold (products.low_stock_threshold overrides it)
 *   users.stock_alert_channel    in_app | in_app_email
 *   stock_alert_events           crossings waiting to be grouped into one notification
 *
 * products/product_variants.last_low_stock_notified_at / last_out_of_stock_notified_at
 * (2025_01_01_000020) are now the "alert sent" flags: set = this item is already
 * in the low / out zone, so no new alert until it is restocked above the threshold.
 *
 * Backfill, so nobody gets a burst of alerts after deploy:
 *   - products with variants: products.stock = sum of their active variants' stock
 *   - every product/variant already at or below its threshold is flagged as alerted
 */
return new class extends Migration
{
    private const DEFAULT_THRESHOLD = 2;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('stock_alerts_enabled')->default(true);
            $table->unsignedTinyInteger('stock_alert_threshold')->default(self::DEFAULT_THRESHOLD);
            $table->string('stock_alert_channel', 16)->default('in_app');
        });

        Schema::create('stock_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('kind', 8);                 // low | out
            $table->unsignedInteger('stock');
            $table->unsignedTinyInteger('threshold');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->index(['seller_id', 'notified_at']);
        });

        // Variant products: the product total is the active variants' stock
        DB::statement('
            UPDATE products p
            JOIN (SELECT product_id, SUM(CASE WHEN is_active = 1 THEN stock ELSE 0 END) AS total
                  FROM product_variants GROUP BY product_id) v ON v.product_id = p.id
            SET p.stock = v.total
        ');

        $now = now();
        $t   = 'COALESCE(p.low_stock_threshold, u.stock_alert_threshold, ' . self::DEFAULT_THRESHOLD . ')';

        // Simple products
        DB::statement("
            UPDATE products p JOIN users u ON u.id = p.seller_id
            SET p.last_low_stock_notified_at    = CASE WHEN p.stock <= $t THEN COALESCE(p.last_low_stock_notified_at, ?) ELSE NULL END,
                p.last_out_of_stock_notified_at = CASE WHEN p.stock <= 0  THEN COALESCE(p.last_out_of_stock_notified_at, ?) ELSE NULL END
            WHERE NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id)
        ", [$now, $now]);

        // Variants
        DB::statement("
            UPDATE product_variants pv
            JOIN products p ON p.id = pv.product_id
            JOIN users u ON u.id = p.seller_id
            SET pv.last_low_stock_notified_at    = CASE WHEN pv.stock <= $t THEN COALESCE(pv.last_low_stock_notified_at, ?) ELSE NULL END,
                pv.last_out_of_stock_notified_at = CASE WHEN pv.stock <= 0  THEN COALESCE(pv.last_out_of_stock_notified_at, ?) ELSE NULL END
        ", [$now, $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alert_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['stock_alerts_enabled', 'stock_alert_threshold', 'stock_alert_channel']);
        });
    }
};
