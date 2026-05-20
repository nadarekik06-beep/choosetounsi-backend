<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing orders table with fields needed for the checkout flow.
 * Run: php artisan migrate
 *
 * NOTE: order_items additions (unit_price, product_name) were moved here from
 * the original to AFTER order_items is created (2024_01_01_000070).
 * They are handled safely in 2024_01_01_000061b_extend_order_items_columns.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Delivery address fields
            if (!Schema::hasColumn('orders', 'address')) {
                $table->text('address')->nullable()->after('id');
            }
            if (!Schema::hasColumn('orders', 'phone')) {
                $table->string('phone', 20)->nullable()->after('address');
            }
            if (!Schema::hasColumn('orders', 'notes')) {
                $table->text('notes')->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['address', 'phone', 'notes']);
        });
    }
};
