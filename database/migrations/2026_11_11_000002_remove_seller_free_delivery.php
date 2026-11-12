<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller-offered free delivery is removed (PRICING_PLAN.md, Q8): delivery is
 * free for every client and included in the displayed price.
 *
 * products.delivery_fee = 0 meant "this seller offers the delivery". Those
 * rows go back to NULL (their displayed price now includes the fee, like every
 * other product). Their ids are kept in legacy_free_delivery_products so the
 * change can be undone (down()) and their sellers told
 * (php artisan pricing:notify-free-delivery-sellers).
 *
 * The only data change of the project. No column is dropped; no order is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('legacy_free_delivery_products')) {
            Schema::create('legacy_free_delivery_products', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->primary();
                $table->unsignedBigInteger('seller_id')->nullable()->index();
                $table->timestamp('noted_at')->nullable();
                $table->timestamp('seller_notified_at')->nullable();
            });
        }

        DB::transaction(function () {
            // Soft-deleted products too: a restored product must not come back as "free delivery"
            DB::statement(
                'INSERT IGNORE INTO legacy_free_delivery_products (product_id, seller_id, noted_at)
                 SELECT id, seller_id, NOW() FROM products WHERE delivery_fee IS NOT NULL AND delivery_fee = 0'
            );
            DB::table('products')->whereNotNull('delivery_fee')->where('delivery_fee', 0)->update(['delivery_fee' => null]);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('legacy_free_delivery_products')) {
            return;
        }
        DB::transaction(function () {
            DB::table('products')
                ->whereIn('id', DB::table('legacy_free_delivery_products')->select('product_id'))
                ->update(['delivery_fee' => 0]);
        });
        Schema::dropIfExists('legacy_free_delivery_products');
    }
};
