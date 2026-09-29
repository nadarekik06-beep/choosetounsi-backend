<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listings and recommendation queries still order by the sponsor flags until
 * the ad server replaces them; index them so those sorts don't scan.
 */
class AddSponsoredIndexToProductsTable extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['is_sponsored', 'sponsored_priority'], 'idx_products_sponsored');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_sponsored');
        });
    }
}
