<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores admin-defined complementary product relationships.
 *
 * Example: A pair of jeans is complementary to a belt.
 * These are curated by admin (not AI) — the "Complementary Items" section
 * on the product detail page reads from this table.
 *
 * The relationship is directional:
 *   product_id     → the main product
 *   complement_id  → the recommended complement
 *
 * To make it bidirectional, insert two rows (A→B and B→A).
 * Admin panel can manage this in a future sprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_complementary', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->onDelete('cascade')
                  ->comment('The main product');

            $table->foreignId('complement_id')
                  ->constrained('products')
                  ->onDelete('cascade')
                  ->comment('The recommended complement product');

            $table->integer('order')
                  ->default(0)
                  ->comment('Display order within the complement list');

            $table->timestamps();

            // Prevent duplicate pairs
            $table->unique(['product_id', 'complement_id']);

            // Fast lookup
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_complementary');
    }
};