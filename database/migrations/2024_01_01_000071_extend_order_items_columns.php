<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds unit_price and product_name to order_items.
 * Runs AFTER order_items table is created (2024_01_01_000070).
 *
 * Split out from extend_orders_table (000061) which originally referenced
 * order_items before it existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'unit_price')) {
                $table->decimal('unit_price', 10, 3)->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('order_items', 'product_name')) {
                $table->string('product_name')->nullable()->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'unit_price'))    $table->dropColumn('unit_price');
            if (Schema::hasColumn('order_items', 'product_name'))  $table->dropColumn('product_name');
        });
    }
};
