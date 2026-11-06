<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What discount priced each order line, frozen at checkout (AUDIT_DISCOUNTS C-1, M-4, M-7, M-8):
 *   discount_type          promotion | flash_sale | pack | coupon | none
 *   discount_source_id     promotions.id / packs.id / coupons.id (no FK: history survives deletes)
 *   applied_discount_amount money taken off this line by that discount
 *   promo_discount_type    percentage | fixed, and promo_discount_value: the promotion's terms
 *   pack_id / pack_group   the pack this line belongs to (one group per pack row of the order)
 *   pack_allocation        this line's share of the pack price (× pack quantity) = its total
 *   pack_original_value    this line's items at their original price
 *   commission_tier_price  the ORIGINAL unit price the commission bracket was chosen from
 *   funded_by              seller | platform (who pays the promotion)
 *   platform_funded_amount the discount the platform pays the seller back (platform-funded promotions)
 *
 * seller_orders.platform_funded_amount: Σ of its lines.
 * Older rows keep null / 0: nothing is back-filled, existing orders never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_items', 'discount_type')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('discount_type', 12)->nullable()->after('promotion_source');
                $table->unsignedBigInteger('discount_source_id')->nullable()->after('discount_type');
                $table->decimal('applied_discount_amount', 10, 3)->default(0)->after('discount_source_id');
                $table->string('promo_discount_type', 12)->nullable()->after('applied_discount_amount');
                $table->decimal('promo_discount_value', 10, 3)->nullable()->after('promo_discount_type');
                $table->unsignedBigInteger('pack_id')->nullable()->after('promo_discount_value');
                $table->string('pack_group', 40)->nullable()->after('pack_id');
                $table->decimal('pack_allocation', 10, 3)->nullable()->after('pack_group');
                $table->decimal('pack_original_value', 10, 3)->nullable()->after('pack_allocation');
                $table->decimal('commission_tier_price', 10, 3)->nullable()->after('commission_percentage');
                $table->string('funded_by', 10)->nullable()->after('pack_original_value');
                $table->decimal('platform_funded_amount', 10, 3)->default(0)->after('funded_by');
                $table->index('pack_id');
            });
        }

        if (!Schema::hasColumn('seller_orders', 'platform_funded_amount')) {
            Schema::table('seller_orders', function (Blueprint $table) {
                $table->decimal('platform_funded_amount', 10, 3)->default(0)->after('discount_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'discount_type')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropIndex(['pack_id']);
                $table->dropColumn([
                    'discount_type', 'discount_source_id', 'applied_discount_amount', 'promo_discount_type',
                    'promo_discount_value', 'pack_id', 'pack_group', 'pack_allocation', 'pack_original_value',
                    'commission_tier_price', 'funded_by', 'platform_funded_amount',
                ]);
            });
        }
        if (Schema::hasColumn('seller_orders', 'platform_funded_amount')) {
            Schema::table('seller_orders', fn (Blueprint $table) => $table->dropColumn('platform_funded_amount'));
        }
    }
};
