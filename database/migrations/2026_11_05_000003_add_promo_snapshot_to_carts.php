<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product cart rows remember the promotion they were added with, so the cart
 * and checkout can tell the buyer "this promotion has ended, normal price
 * applies" (the price itself is always recalculated live, never from here).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('carts', 'promotion_id_snapshot')) {
            return;
        }
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('promotion_id_snapshot')->nullable()->after('variant_id');
            $table->decimal('promo_price_snapshot', 10, 3)->nullable()->after('promotion_id_snapshot');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('carts', 'promotion_id_snapshot')) {
            Schema::table('carts', fn (Blueprint $table) => $table->dropColumn(['promotion_id_snapshot', 'promo_price_snapshot']));
        }
    }
};
