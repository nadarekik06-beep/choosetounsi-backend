<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing orders table with fields needed for the checkout flow.
 * Run: php artisan migrate
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

        // Make sure order_items has unit_price (your existing migration uses 'price')
        // Add unit_price alias if missing so SellerDashboardController still works
        if (!Schema::hasColumn('order_items', 'unit_price')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->decimal('unit_price', 10, 3)->default(0)->after('quantity');
            });
        }
        // Add product_name snapshot if missing
        if (!Schema::hasColumn('order_items', 'product_name')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('product_name')->nullable()->after('unit_price');
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['address', 'phone', 'notes']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'product_name']);
        });
    }
};