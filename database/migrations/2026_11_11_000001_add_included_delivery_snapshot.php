<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Free delivery everywhere" (PRICING_PLAN.md): the delivery fee is included in
 * the price the client sees. Orders placed with that model freeze, next to the
 * existing base-price columns (never changed in meaning):
 *
 * order_items
 *   parcel_size                  the item's size (S / L / XL)
 *   included_fee                 fee included in ONE unit's display price (product lines; null on pack lines)
 *   included_fee_total           fee included in the whole line (pack lines: their share of the pack's one fee)
 *   pack_units                   pack lines: how many packs were bought (a pack = one unit)
 *   display_unit_price           unit price the client saw (after promotion, fee included)
 *   display_original_unit_price  crossed-out unit price the client saw
 *
 * seller_orders (one parcel)
 *   pricing_model        'included' for these orders, NULL for older ones (which keep their delivery line)
 *   included_fees_total  Σ included fees of its units
 *   parcel_fee           fee of the parcel's size = what the platform keeps for delivery (= delivery_fee)
 *   multi_item_discount  included_fees_total − parcel_fee
 *   client_total         Σ display prices − coupon − multi_item_discount (= the COD amount)
 *   fee_rates            {"S":"9.000","L":"11.000","XL":"17.000"} in force at checkout (returns recompute with them)
 *
 * orders
 *   pricing_model, included_fees_total, multi_item_discount (Σ of its parcels at checkout)
 *
 * products
 *   parcel_size_confirmed_at  when a seller or an admin last chose the size. NULL = never confirmed
 *                             (older products silently got S). Products already on L / XL were chosen
 *                             on purpose: they count as confirmed.
 *
 * Additive and nullable: no existing row changes meaning, older orders are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_items', 'included_fee')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('parcel_size', 3)->nullable();
                $table->decimal('included_fee', 12, 3)->nullable();
                $table->decimal('included_fee_total', 12, 3)->nullable();
                $table->unsignedInteger('pack_units')->nullable();
                $table->decimal('display_unit_price', 12, 3)->nullable();
                $table->decimal('display_original_unit_price', 12, 3)->nullable();
            });
        }

        if (!Schema::hasColumn('seller_orders', 'pricing_model')) {
            Schema::table('seller_orders', function (Blueprint $table) {
                $table->string('pricing_model', 20)->nullable();
                $table->decimal('included_fees_total', 12, 3)->nullable();
                $table->decimal('parcel_fee', 12, 3)->nullable();
                $table->decimal('multi_item_discount', 12, 3)->nullable();
                $table->decimal('client_total', 12, 3)->nullable();
                $table->json('fee_rates')->nullable();
            });
        }

        if (!Schema::hasColumn('orders', 'pricing_model')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('pricing_model', 20)->nullable();
                $table->decimal('included_fees_total', 12, 3)->nullable();
                $table->decimal('multi_item_discount', 12, 3)->nullable();
            });
        }

        if (!Schema::hasColumn('products', 'parcel_size_confirmed_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('parcel_size_confirmed_at')->nullable();
            });
            DB::table('products')->whereIn('parcel_size', ['L', 'XL'])
                ->update(['parcel_size_confirmed_at' => DB::raw('COALESCE(updated_at, created_at, NOW())')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'parcel_size_confirmed_at')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('parcel_size_confirmed_at'));
        }
        if (Schema::hasColumn('orders', 'pricing_model')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['pricing_model', 'included_fees_total', 'multi_item_discount']));
        }
        if (Schema::hasColumn('seller_orders', 'pricing_model')) {
            Schema::table('seller_orders', fn (Blueprint $table) => $table->dropColumn([
                'pricing_model', 'included_fees_total', 'parcel_fee', 'multi_item_discount', 'client_total', 'fee_rates',
            ]));
        }
        if (Schema::hasColumn('order_items', 'included_fee')) {
            Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn([
                'parcel_size', 'included_fee', 'included_fee_total', 'pack_units', 'display_unit_price', 'display_original_unit_price',
            ]));
        }
    }
};
