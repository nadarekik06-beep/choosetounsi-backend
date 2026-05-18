<?php
// database/migrations/2026_05_18_000002_create_settlement_batches_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement batches — one row per daily payout run.
 *
 * A batch groups all seller_orders where:
 *   - payout_status = 'ready'
 *   - money_received_at IS NOT NULL
 *   - The seller matches the batch's seller_id
 *   - The batch_date matches the settlement date
 *
 * Each batch is immutable once status = 'paid'.
 * Amounts are snapshot-frozen at batch creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_batches', function (Blueprint $table) {
            $table->id();

            // Batch reference (human-readable, unique)
            $table->string('batch_reference')->unique()
                  ->comment('e.g. BATCH-2026-05-18-001');

            // Which seller this batch pays out
            $table->unsignedBigInteger('seller_id');
            $table->foreign('seller_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            // The date this batch covers (orders delivered/confirmed on this date)
            $table->date('batch_date')
                  ->comment('Settlement date — groups orders by delivery confirmation date');

            // ── Financial totals (frozen at batch creation) ─────────────────
            $table->decimal('total_orders_gross', 12, 3)->default(0)
                  ->comment('Sum of seller_orders.subtotal in this batch');

            $table->decimal('total_commission', 12, 3)->default(0)
                  ->comment('Sum of seller_orders.commission_amount in this batch');

            $table->decimal('total_delivery_fees', 12, 3)->default(0)
                  ->comment('Sum of seller_orders.delivery_fee in this batch');

            $table->decimal('total_seller_payout', 12, 3)->default(0)
                  ->comment('Sum of seller_orders.seller_net_amount — what seller receives');

            $table->decimal('total_platform_profit', 12, 3)->default(0)
                  ->comment('Total admin revenue = commissions + delivery fees');

            $table->integer('orders_count')->default(0)
                  ->comment('Number of seller_orders in this batch');

            // ── Status ──────────────────────────────────────────────────────
            $table->enum('status', ['draft', 'confirmed', 'paid', 'cancelled'])
                  ->default('draft')
                  ->comment('draft=created | confirmed=verified | paid=seller paid | cancelled');

            // ── Audit ───────────────────────────────────────────────────────
            $table->unsignedBigInteger('created_by')->nullable()
                  ->comment('Admin user_id who created this batch');

            $table->unsignedBigInteger('confirmed_by')->nullable()
                  ->comment('Admin user_id who confirmed/paid this batch');

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable()
                  ->comment('Admin notes for this settlement batch');

            $table->timestamps();

            // ── Indexes ─────────────────────────────────────────────────────
            $table->index('seller_id');
            $table->index('batch_date');
            $table->index('status');
            $table->index(['seller_id', 'batch_date']);
        });

        // Add FK on seller_orders now that settlement_batches exists
        Schema::table('seller_orders', function (Blueprint $table) {
            if (Schema::hasColumn('seller_orders', 'settlement_batch_id')) {
                $table->foreign('settlement_batch_id')
                      ->references('id')
                      ->on('settlement_batches')
                      ->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropForeign(['settlement_batch_id']);
        });
        Schema::dropIfExists('settlement_batches');
    }
};