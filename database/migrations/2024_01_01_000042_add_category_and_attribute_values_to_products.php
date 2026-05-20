<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This migration runs AFTER products (000040), categories (000030),
 * subcategories (000031), and attributes (000032) all exist.
 *
 * It:
 *  1. Adds category_id + subcategory_id FKs to products
 *  2. Creates product_attribute_values (FK to products + attributes)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add category_id to products
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'category_id')) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('seller_id')
                    ->constrained()
                    ->nullOnDelete();
            }
        });

        // 2. Add subcategory_id to products
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'subcategory_id')) {
                $table->foreignId('subcategory_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('subcategories')
                    ->nullOnDelete();
            }
        });

        // 3. Product attribute values (the actual data per product)
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('attribute_id')->constrained()->onDelete('cascade');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'subcategory_id')) {
                $table->dropForeign(['subcategory_id']);
                $table->dropColumn('subcategory_id');
            }
            if (Schema::hasColumn('products', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
        });
    }
};
