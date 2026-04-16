<?php
// database/migrations/2026_04_15_000001_add_sponsored_to_products_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Black Pepper sponsored-product columns to the products table.
 *
 * is_sponsored       — true when the seller has activated sponsorship
 * sponsored_until    — nullable expiry timestamp (null = no expiry / manual control)
 * sponsored_priority — higher number = higher ranking boost (default 0 = not boosted)
 * sponsored_at       — when sponsorship was last activated
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_sponsored')->default(false)->after('featured');
            $table->timestamp('sponsored_until')->nullable()->after('is_sponsored');
            $table->unsignedTinyInteger('sponsored_priority')->default(0)->after('sponsored_until');
            $table->timestamp('sponsored_at')->nullable()->after('sponsored_priority');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_sponsored', 'sponsored_until', 'sponsored_priority', 'sponsored_at']);
        });
    }
};