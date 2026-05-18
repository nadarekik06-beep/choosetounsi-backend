<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEWS TABLE
 *
 * A review can only be submitted when:
 *   - seller_order.status = 'delivered'
 *   - The order_item links the product to the delivered seller_order
 *   - No review already exists for (user_id, order_item_id)
 *
 * Unique constraint: one review per (user, order_item) — not per (user, product)
 * This allows a customer who buys the same product twice to review both purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            // The customer who wrote this review
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // The product being reviewed
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->onDelete('cascade');

            // Linked to a specific purchase — guarantees "verified purchase"
            $table->foreignId('order_item_id')
                  ->constrained('order_items')
                  ->onDelete('cascade');

            // The seller whose product is being reviewed (denormalized for fast analytics)
            $table->unsignedBigInteger('seller_id');
            $table->foreign('seller_id')->references('id')->on('users')->onDelete('cascade');

            // ── Core review content ────────────────────────────────────────
            $table->unsignedTinyInteger('rating'); // 1–5

            $table->text('body')->nullable(); // review text (min 10 chars enforced at API layer)

            // ── Display options ────────────────────────────────────────────
            $table->boolean('is_anonymous')->default(false);
            // "Verified Purchase" badge — always true since we gate on order_item_id,
            // but kept explicit so it can be set false for migrated legacy reviews
            $table->boolean('is_verified_purchase')->default(true);

            // ── Moderation ─────────────────────────────────────────────────
            // pending   → just submitted, awaiting auto-approve or manual review
            // approved  → visible on product page
            // rejected  → removed by admin (spam, inappropriate)
            // flagged   → under review due to reports
            $table->enum('status', ['pending', 'approved', 'rejected', 'flagged'])
                  ->default('approved'); // auto-approve by default; flip to 'pending' if you want manual moderation

            $table->text('rejection_reason')->nullable(); // admin note when rejecting

            // ── Helpful votes (denormalized count for fast sorting) ─────────
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('not_helpful_count')->default(0);

            $table->timestamps();

            // ── Indexes ────────────────────────────────────────────────────
            // One review per order item (the strictest uniqueness guarantee)
            $table->unique(['user_id', 'order_item_id']);

            $table->index('product_id');
            $table->index('seller_id');
            $table->index('rating');
            $table->index('status');
            $table->index(['product_id', 'status']); // used by product page listing
            $table->index(['seller_id', 'status']);   // used by seller analytics
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};