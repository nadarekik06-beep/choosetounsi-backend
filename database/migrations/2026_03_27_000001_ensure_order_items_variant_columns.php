<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe migration — adds variant_label to order_items only if missing.
 * Run this if you get "column not found" errors on checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'variant_label')) {
                $table->string('variant_label')->nullable()->after('variant_id');
            }
            // Also ensure product_name column exists (needed by CheckoutController)
            if (!Schema::hasColumn('order_items', 'product_name')) {
                $table->string('product_name')->nullable()->after('product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'variant_label')) {
                $table->dropColumn('variant_label');
            }
        });
    }
};