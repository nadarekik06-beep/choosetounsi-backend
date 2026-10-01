<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which promotion priced an order line, and how many units it took from a
 * flash sale's quota (promotions.flash_stock_used).
 *
 *   promotion_id    the promotion applied at checkout (null = full price)
 *   flash_reserved  units still held in the flash quota; set back to 0 when the
 *                   seller order is cancelled or the card payment fails, so a
 *                   release happens exactly once
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('variant_id')
                ->constrained('promotions')->nullOnDelete();
            $table->unsignedInteger('flash_reserved')->default(0)->after('promotion_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('flash_reserved');
        });
    }
};
