<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks user interactions with products for recommendation logic.
 *
 * Actions tracked:
 *   view     — user opened the product detail page
 *   favorite — user added product to favorites
 *   cart     — user added product to cart
 *   order    — user purchased the product
 *
 * We store category_id as a denormalized column so recommendations
 * can query by category without joining products every time.
 *
 * Nullable user_id is intentional — guest activity is NOT tracked.
 * Only authenticated users are tracked (privacy-safe).
 *
 * Performance strategy:
 *   - Composite index on (user_id, action) for "what did this user do?"
 *   - Composite index on (product_id, action) for "who interacted with this product?"
 *   - Index on category_id for category-based recommendations
 *   - Index on created_at for recency-weighted queries
 *
 * We do NOT enforce a unique constraint — same user viewing same product
 * multiple times is valid and useful for weighting frequency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->onDelete('cascade');

            // Denormalized for fast category-based queries
            $table->unsignedBigInteger('category_id')
                  ->nullable()
                  ->comment('Denormalized from products.category_id');

            // The type of interaction
            $table->enum('action', ['view', 'favorite', 'cart', 'order'])
                  ->comment('Type of user interaction with the product');

            // Session/request metadata (optional, useful for deduplication)
            $table->string('session_id', 64)
                  ->nullable()
                  ->comment('Laravel session ID — used to deduplicate rapid re-views');

            $table->timestamp('created_at')
                  ->useCurrent()
                  ->comment('When the interaction happened — used for recency weighting');

            // ── Indexes ─────────────────────────────────────────────────────
            // For "get user's activity" (recommendation engine)
            $table->index(['user_id', 'action']);

            // For "get product's activity" (trending, popularity)
            $table->index(['product_id', 'action']);

            // For category-based recommendations
            $table->index(['user_id', 'category_id']);

            // For recency-based queries
            $table->index('created_at');

            // No updated_at — activity logs are append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
};