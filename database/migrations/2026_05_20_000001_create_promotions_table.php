<?php
// database/migrations/2024_01_01_000001_create_promotions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            // ── Primary key ───────────────────────────────────────────────
            $table->id();

            // ── Ownership ─────────────────────────────────────────────────
            $table->foreignId('seller_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────────
            $table->string('name');                          // e.g. "Summer Flash Sale"

            // ── Type ──────────────────────────────────────────────────────
            // flash_sale: time-limited, high urgency, countdown required
            // discount:   longer duration, no countdown required
            $table->enum('type', ['flash_sale', 'discount']);

            // ── Discount definition ───────────────────────────────────────
            $table->enum('discount_type', ['percentage', 'fixed']);
            // percentage: 0.001–100 | fixed: 0.001–product_price
            $table->decimal('discount_value', 10, 3)->unsigned();

            // ── Scheduling ────────────────────────────────────────────────
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // ── Flash sale stock cap (null = unlimited within normal stock) ──
            // When set, only this many units can be sold at the promo price.
            // Separate from products.stock — does NOT decrement it.
            $table->unsignedInteger('flash_stock')->nullable();
            $table->unsignedInteger('flash_stock_used')->default(0);

            // ── Lifecycle status ──────────────────────────────────────────
            // scheduled → active (when starts_at reached, via cron)
            // active    → expired (when ends_at passed, via cron or on-read)
            // paused    → manually paused by seller
            $table->enum('status', ['scheduled', 'active', 'paused', 'expired'])
                  ->default('scheduled');

            // ── Conflict resolution priority ──────────────────────────────
            // When two promotions are active on the same product:
            //   1. type:  flash_sale always beats discount (hardcoded in service)
            //   2. priority column: higher value wins within same type
            //   3. tie-break: most recently created_at wins
            // Default: flash_sale = 10, discount = 5 (set in controller)
            $table->unsignedTinyInteger('priority')->default(0);

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────
            // Used by PromotionService::getActivePromotionForProduct()
            $table->index(['status', 'starts_at', 'ends_at'],  'idx_promotions_active_window');
            // Used by seller dashboard list queries
            $table->index(['seller_id', 'type', 'status'],     'idx_promotions_seller_type');
            // Used by cron SyncPromotionStatuses command
            $table->index(['status', 'ends_at'],               'idx_promotions_expiry');
            $table->index(['status', 'starts_at'],             'idx_promotions_activation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};