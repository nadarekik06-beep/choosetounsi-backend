<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sur commande" products: the seller makes / prepares the item after the order.
 * preparation_days = business days (Mon–Fri, minus holidays) before pickup, 1–30.
 * Existing products start as not made-to-order (false / NULL): nothing changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_made_to_order')->default(false)->after('parcel_size_confirmed_at');
            $table->unsignedTinyInteger('preparation_days')->nullable()->after('is_made_to_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_made_to_order', 'preparation_days']);
        });
    }
};
