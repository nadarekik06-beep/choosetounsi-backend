<?php
// database/migrations/2026_04_16_000001_create_sponsorships_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the sponsorships audit/business-logic table.
 *
 * The FAST query layer (is_sponsored, sponsored_priority) lives on the
 * products table and is already in production.
 *
 * This table tracks:
 *   - Every sponsorship activation (who, what, when, which plan)
 *   - Per-plan pricing and payment hooks
 *   - Black Pepper free-quota usage (3 free/week)
 *   - AI-generated tags and ad copy per sponsorship
 *   - Click / impression counters for seller analytics
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorships', function (Blueprint $table) {
            $table->id();

            // ── Foreign keys ──────────────────────────────────────────────────
            $table->foreignId('seller_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->onDelete('cascade');

            // ── Plan info ─────────────────────────────────────────────────────
            // Which plan the seller was on when they activated this sponsorship.
            $table->enum('plan_type', ['free', 'red', 'black'])
                  ->default('free')
                  ->comment('Seller plan at time of activation');

            // ── Boost ─────────────────────────────────────────────────────────
            // Denormalised copy of boost applied (Green=10, Red=30, Black=70)
            $table->unsignedTinyInteger('boost_score')
                  ->default(10)
                  ->comment('10=green, 30=red, 70=black');

            // ── Status & dates ────────────────────────────────────────────────
            $table->enum('status', ['active', 'expired', 'cancelled'])
                  ->default('active')
                  ->index();

            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable()
                  ->comment('Null = open-ended / manual deactivation');

            // ── Payment ───────────────────────────────────────────────────────
            // Cost charged for this sponsorship (0 for black free uses).
            $table->decimal('amount_charged', 10, 3)
                  ->default(0)
                  ->comment('0 for free-quota uses or free-plan with override');

            // Hook for future payment gateway integration.
            $table->string('payment_reference')->nullable()
                  ->comment('Future Stripe / D17 payment reference');

            // Whether a payment was required (for reporting).
            $table->boolean('was_paid')->default(false);

            // ── Black Pepper free quota ───────────────────────────────────────
            // True if this activation consumed one of the 3 free/week uses.
            $table->boolean('used_free_quota')
                  ->default(false)
                  ->comment('True when Black plan used a free weekly slot');

            // ── AI-generated content ──────────────────────────────────────────
            // Stored as JSON arrays / strings for display in the seller UI.
            $table->json('ai_tags')->nullable()
                  ->comment('AI-generated keyword tags for the product');

            $table->text('ai_ad_copy')->nullable()
                  ->comment('AI-generated short promotional text');

            // ── Analytics counters ────────────────────────────────────────────
            // Incremented by middleware / frontend tracking events.
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('conversions')->default(0)
                  ->comment('Orders where this product was bought while sponsored');

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index(['seller_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index(['plan_type', 'status']);
            $table->index('start_at');
            $table->index('end_at');

            // Prevent duplicate active sponsorship for the same product
            // (enforced in code too, but belt-and-suspenders at DB level).
            // We can't do a partial unique index in Laravel portable SQL,
            // so we enforce uniqueness in the controller.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorships');
    }
};