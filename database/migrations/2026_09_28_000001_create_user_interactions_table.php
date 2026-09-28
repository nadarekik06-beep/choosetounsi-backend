<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of every signal the recommendation engine learns from.
 * Replaces user_activity_logs (user-only, no guests, no clicks/search/follows).
 *
 * Index strategy:
 *   (user_id, created_at)                  — build one user's profile / recently viewed
 *   (session_id, created_at)               — same for guests, and the login merge
 *   (created_at, event_type, product_id)   — platform-wide "trending in last 7 days" scan
 *   (product_id, event_type)               — per-product counts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->char('session_id', 36)->nullable()->comment('Guest id from the X-Session-Id header');
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->enum('event_type', [
                'view', 'click', 'cart_add', 'favorite_add', 'favorite_remove',
                'purchase', 'search', 'follow', 'unfollow',
            ]);
            $table->string('source_section', 40)->nullable();
            $table->string('search_query', 191)->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->comment('purchase only — ignored once the order is cancelled/refunded');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
            $table->index(['created_at', 'event_type', 'product_id']);
            $table->index(['product_id', 'event_type']);
        });

        if (Schema::hasTable('user_activity_logs')) {
            DB::statement("
                INSERT INTO user_interactions (user_id, product_id, seller_id, category_id, event_type, created_at)
                SELECT l.user_id, l.product_id, p.seller_id, l.category_id,
                       CASE l.action
                           WHEN 'cart'     THEN 'cart_add'
                           WHEN 'favorite' THEN 'favorite_add'
                           WHEN 'order'    THEN 'purchase'
                           ELSE l.action
                       END,
                       l.created_at
                FROM user_activity_logs l
                LEFT JOIN products p ON p.id = l.product_id
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_interactions');
    }
};
