<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon discount snapshot on orders.
 *
 *   orders         → subtotal (items, pre-discount), discount_amount, coupon_codes
 *                    total_amount widened to 3 decimals (TND uses millimes)
 *   seller_orders  → coupon_type, coupon_value (snapshot of the coupon at checkout)
 *   order_items    → discount_amount (this line's share of the seller coupon),
 *                    net_total (total − discount_amount, commission base)
 *                    price / total widened to 3 decimals
 *
 * Historical rows are filled by `php artisan orders:backfill-discounts`.
 *
 * Raw ALTERs instead of ->change(): orders has enum columns, which doctrine/dbal
 * refuses to introspect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 3)->nullable()->after('order_number');
            $table->decimal('discount_amount', 10, 3)->default(0)->after('subtotal');
            $table->json('coupon_codes')->nullable()->after('discount_amount');
        });
        DB::statement('ALTER TABLE orders MODIFY total_amount DECIMAL(10,3) NOT NULL');

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->string('coupon_type', 20)->nullable()->after('coupon_code');
            $table->decimal('coupon_value', 10, 3)->nullable()->after('coupon_type');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 3)->default(0)->after('total');
            $table->decimal('net_total', 10, 3)->nullable()->after('discount_amount');
        });
        DB::statement('ALTER TABLE order_items MODIFY price DECIMAL(10,3) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY total DECIMAL(10,3) NOT NULL');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'net_total']);
        });
        DB::statement('ALTER TABLE order_items MODIFY price DECIMAL(10,2) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY total DECIMAL(10,2) NOT NULL');

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_type', 'coupon_value']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'discount_amount', 'coupon_codes']);
        });
        DB::statement('ALTER TABLE orders MODIFY total_amount DECIMAL(10,2) NOT NULL');
    }
};
