<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotion breakdown on each order line, frozen at checkout:
 *   original_unit_price    unit price before the promotion (the crossed-out price
 *                          the buyer saw); equals unit_price when no promotion
 *   promo_discount_amount  (original_unit_price − unit_price) × quantity
 *   promotion_source       seller | admin (kept even if the promotion is deleted)
 * unit_price / price stay the FINAL unit price; total, net_total, commission and
 * seller_amount are unchanged and keep using it. discount_amount stays the
 * seller COUPON share of the line (not the promotion).
 * Older rows: null / 0 (nothing is back-filled).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_items', 'original_unit_price')) {
            return;
        }
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('original_unit_price', 10, 3)->nullable()->after('unit_price');
            $table->decimal('promo_discount_amount', 10, 3)->default(0)->after('original_unit_price');
            $table->string('promotion_source', 10)->nullable()->after('promotion_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'original_unit_price')) {
            Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['original_unit_price', 'promo_discount_amount', 'promotion_source']));
        }
    }
};
