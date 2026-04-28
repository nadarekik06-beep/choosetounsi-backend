<?php
// database/migrations/2024_01_01_000002_create_promotion_products_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('promotion_products');
        Schema::create('promotion_products', function (Blueprint $table) {
            // ── Primary key ───────────────────────────────────────────────
            $table->id();

            // ── Foreign keys ──────────────────────────────────────────────
            $table->foreignId('promotion_id')
                  ->constrained('promotions')
                  ->cascadeOnDelete();   // pivot row gone when promo deleted

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnDelete();   // pivot row gone when product deleted

            // ── Constraints ───────────────────────────────────────────────
            // A product can only appear once per promotion
            $table->unique(['promotion_id', 'product_id'], 'uq_promotion_product');

            // ── Indexes ───────────────────────────────────────────────────
            // Used by PromotionService: "find active promotions for product X"
            // This is the hottest query in the whole module — runs on every
            // product page load and every product listing API call.
            $table->index('product_id', 'idx_pp_product_id');

            // Used by seller dashboard: "show all products attached to promo Y"
            $table->index('promotion_id', 'idx_pp_promotion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_products');
    }
};