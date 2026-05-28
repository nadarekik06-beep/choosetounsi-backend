<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create refund_delivery_tasks table.
 *
 * This table is the central entity of the Refund Delivery flow.
 * It is intentionally SEPARATE from delivery_assignments because:
 *
 *   1. delivery_assignments has a UNIQUE constraint on seller_order_id —
 *      refunds are tied to complaint_id, not seller_order_id.
 *
 *   2. The physical flow is INVERTED:
 *      Normal delivery: Seller → Customer (drop off)
 *      Refund delivery: Customer → Seller  (pick up from customer)
 *
 *   3. Keeping them separate avoids any risk of breaking the existing
 *      delivery assignment logic.
 *
 * Snapshot columns (customer_*, seller_*):
 *   We snapshot address data at task creation time so the task remains
 *   readable even if the order/user records change later.
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_delivery_tasks', function (Blueprint $table) {
            $table->id();

            // ── Relations ──────────────────────────────────────────────────
            $table->foreignId('complaint_id')
                  ->constrained('complaints')
                  ->cascadeOnDelete();

            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();

            $table->unsignedBigInteger('seller_id')->index();
            $table->foreign('seller_id')
                  ->references('id')
                  ->on('users')
                  ->cascadeOnDelete();

            // ── Customer snapshot (pickup location — where agent goes TO collect) ──
            $table->string('customer_name');
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_wilaya', 100)->nullable();
            $table->text('customer_address')->nullable();

            // ── Seller snapshot (return location — where agent brings item BACK) ──
            $table->string('seller_name');
            $table->string('seller_business_name')->nullable();
            $table->string('seller_phone', 30)->nullable();
            $table->string('seller_wilaya', 100)->nullable();
            $table->string('seller_city', 100)->nullable();

            // ── Items snapshot ─────────────────────────────────────────────
            // JSON array: [{ product_name, quantity }]
            // Stored as snapshot so we don't need to re-join order_items later.
            $table->json('items_summary');

            // ── Complaint details (for delivery agent context) ─────────────
            $table->string('complaint_type', 50);
            $table->text('complaint_description')->nullable();
            $table->string('complaint_image_url', 500)->nullable();

            // ── Status lifecycle ───────────────────────────────────────────
            // pending   → created, not yet assigned to a delivery agent
            // assigned  → delivery guy assigned
            // picked_up → delivery guy collected item from customer
            // completed → delivery guy returned item to seller / confirmed refund
            $table->enum('status', ['pending', 'assigned', 'picked_up', 'completed'])
                  ->default('pending');

            // ── Assignment ─────────────────────────────────────────────────
            $table->unsignedBigInteger('delivery_guy_id')->nullable()->index();
            $table->foreign('delivery_guy_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->foreign('assigned_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            // ── Timestamps for each stage ──────────────────────────────────
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // ── Optional notes from delivery admin ─────────────────────────
            $table->text('notes')->nullable();

            $table->timestamps();

            // ── Indexes ────────────────────────────────────────────────────
            $table->index('status');
            $table->index(['delivery_guy_id', 'status']);
            $table->unique('complaint_id'); // one refund task per complaint
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_delivery_tasks');
    }
};