<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEW PROMPTS
 *
 * Tracks which order_items have had a review prompt sent to the customer.
 * Prevents duplicate prompts and allows analytics on prompt conversion rate.
 *
 * This table is written to when:
 *   - A seller_order transitions to 'delivered'
 *   - The post-delivery notification/email is dispatched
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_prompts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');

            // When the prompt was sent
            $table->timestamp('sent_at')->nullable();

            // When the customer dismissed the popup ("Maybe Later")
            $table->timestamp('dismissed_at')->nullable();

            // When the review was actually submitted (null until reviewed)
            $table->timestamp('reviewed_at')->nullable();

            // Channel: 'popup', 'email', 'push' (future)
            $table->string('channel')->default('popup');

            $table->timestamps();

            // One prompt per (user, order_item)
            $table->unique(['user_id', 'order_item_id']);
            $table->index(['user_id', 'reviewed_at']); // pending prompts
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_prompts');
    }
};