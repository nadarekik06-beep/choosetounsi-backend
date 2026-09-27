<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every shipment has a real cost billed by the delivery agency. Until now a
 * free-shipping order was treated as costing nothing, and the fee a customer
 * paid was booked as platform profit.
 *
 * orders.shipping_cost            what the agency bills for this order (snapshot)
 * orders.shipping_paid_by         customer | seller | platform
 * seller_orders.shipping_cost     this sub-order's share of the agency cost
 * seller_orders.seller_shipping_charge  deducted from the seller's earnings
 *                                  (free-shipping orders only)
 *
 * Existing rows:
 *   - Customer-paid orders: shipping_cost = the fee the customer paid (pass-
 *     through to the agency) and platform_profit loses that fee, since it was
 *     never platform money. Seller figures are untouched.
 *   - Free-shipping orders: sellers are NOT charged retroactively (many are
 *     already settled). They are recorded as paid_by = 'platform' at the
 *     default cost, which is what really happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'shipping_cost')) {
                $table->decimal('shipping_cost', 8, 3)->nullable()->after('shipping_fee')
                      ->comment('Delivery agency cost for this order, frozen at checkout');
            }
            if (!Schema::hasColumn('orders', 'shipping_paid_by')) {
                $table->string('shipping_paid_by', 16)->nullable()->after('shipping_cost')
                      ->comment('customer | seller | platform');
            }
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_orders', 'shipping_cost')) {
                $table->decimal('shipping_cost', 8, 3)->default(0)->after('delivery_fee')
                      ->comment('Share of the agency shipping cost booked on this sub-order');
            }
            if (!Schema::hasColumn('seller_orders', 'seller_shipping_charge')) {
                $table->decimal('seller_shipping_charge', 8, 3)->default(0)->after('shipping_cost')
                      ->comment('Shipping deducted from seller earnings (free-shipping orders)');
            }
        });

        $defaultCost = (float) config('platform.shipping_cost', 8.0);

        DB::transaction(function () use ($defaultCost) {
            // Customer-paid: the fee went straight to the agency.
            DB::table('orders')->whereNull('shipping_paid_by')->where('shipping_fee', '>', 0)
                ->update(['shipping_cost' => DB::raw('shipping_fee'), 'shipping_paid_by' => 'customer']);

            DB::table('orders')->whereNull('shipping_paid_by')
                ->update(['shipping_cost' => $defaultCost, 'shipping_paid_by' => 'platform']);

            // Book the cost on each order's first seller_order (same row that
            // carries delivery_fee) and take it out of platform_profit.
            $firsts = DB::table('seller_orders')->selectRaw('MIN(id) as id, order_id')->groupBy('order_id');

            DB::table('seller_orders as so')
                ->joinSub($firsts, 'f', 'f.id', '=', 'so.id')
                ->join('orders as o', 'o.id', '=', 'so.order_id')
                ->where('so.shipping_cost', 0)
                ->update([
                    'so.shipping_cost'   => DB::raw('o.shipping_cost'),
                    'so.platform_profit' => DB::raw('so.platform_profit - o.shipping_cost'),
                ]);
        });
    }

    public function down(): void
    {
        // Give back the shipping cost taken out of platform_profit.
        if (Schema::hasColumn('seller_orders', 'shipping_cost')) {
            DB::table('seller_orders')->update([
                'platform_profit'   => DB::raw('platform_profit + shipping_cost - seller_shipping_charge'),
                'seller_net_amount' => DB::raw('seller_net_amount + seller_shipping_charge'),
            ]);
        }

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_cost', 'seller_shipping_charge']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_cost', 'shipping_paid_by']);
        });
    }
};
