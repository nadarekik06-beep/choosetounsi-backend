<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHOOSE'Tounsi Brand Products
 *
 * Separate table from `products` (seller products) for a clean separation.
 *
 * Why NOT a flag on products:
 *   - products.seller_id is semantically required; platform products have no seller
 *   - Seller approval/variant/sponsorship logic should not bleed into brand products
 *   - Independent scaling: brand products can grow their own fields (collection, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_products', function (Blueprint $table) {
            $table->id();

            // Core fields
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->decimal('price', 10, 3);
            $table->unsignedInteger('stock')->default(0);
            $table->string('sku')->nullable()->unique();

            // Classification
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            // Visibility controls
            $table->boolean('is_active')->default(true);
            $table->boolean('featured')->default(false);

            // Analytics
            $table->unsignedInteger('views')->default(0);

            $table->timestamps();

            // Indexes
            $table->index(['is_active', 'featured']);
            $table->index('category_id');
        });

        Schema::create('brand_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_product_id')
                ->constrained('brand_products')
                ->onDelete('cascade');
            $table->string('image_path');
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['brand_product_id', 'order']);
            $table->index(['brand_product_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_product_images');
        Schema::dropIfExists('brand_products');
    }
};