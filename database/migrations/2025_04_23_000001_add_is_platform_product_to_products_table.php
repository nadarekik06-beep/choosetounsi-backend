<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add is_platform_product flag to the products table.
 *
 * WHY FLAG INSTEAD OF SEPARATE TABLE:
 *   - Variants, ProductImages, ProductAttributeValues, Sponsorships, etc.
 *     are all foreign-keyed to products.id — reusing the table gives brand
 *     products full variant/image/attribute support for free.
 *   - The only difference between seller products and platform products is:
 *     - seller_id can be null for platform products
 *     - is_approved / approval flow is bypassed (admin owns them)
 *     - is_platform_product = true filters them out of seller APIs
 *
 * EXISTING DATA: All current rows default to false → no regression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_platform_product')
                ->default(false)
                ->after('featured')
                ->comment('True for CHOOSE\'Tounsi brand products (admin-owned). False for all seller products.');
        });

        // Index for fast filtering — both the admin list and public API
        // will always filter by this column.
        Schema::table('products', function (Blueprint $table) {
            $table->index('is_platform_product');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_platform_product']);
            $table->dropColumn('is_platform_product');
        });
    }
};