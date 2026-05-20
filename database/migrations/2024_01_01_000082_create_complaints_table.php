<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_complaints_table
 *
 * Run with: php artisan migrate
 *
 * Table stores complaint/return requests submitted by clients.
 * Status workflow: pending → reviewing → approved | rejected
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();

            // ── Relations ──────────────────────────────────────────────────
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();

            // seller_id resolved at creation time from order items → products.seller_id
            $table->unsignedBigInteger('seller_id')->nullable()->index();

            // ── Complaint details ──────────────────────────────────────────
            /**
             * complaint_type values (enum-like):
             *   wrong_product | wrong_size | wrong_color | damaged_product | other
             */
            $table->string('complaint_type', 50);

            /**
             * When complaint_type = 'other', user provides a custom label.
             */
            $table->string('other_reason', 255)->nullable();

            $table->text('description');

            /**
             * Path to proof image stored in storage/app/public/complaints/
             * Accessed via Storage::url($image_path)
             */
            $table->string('image_path')->nullable();

            // ── Status workflow ────────────────────────────────────────────
            /**
             * PENDING   → just submitted, no action taken
             * REVIEWING → seller has acknowledged / responded
             * APPROVED  → admin approved, next steps follow
             * REJECTED  → admin rejected, reason given
             */
            $table->enum('status', ['pending', 'reviewing', 'approved', 'rejected'])
                  ->default('pending');

            /**
             * Admin fills this when status = rejected.
             */
            $table->text('rejection_reason')->nullable();

            /**
             * Optional seller response / note visible to admin.
             */
            $table->text('seller_note')->nullable();

            // ── Time tracking ─────────────────────────────────────────────
            $table->timestamp('reviewed_at')->nullable();   // when seller marked reviewing
            $table->timestamp('resolved_at')->nullable();   // when admin approved/rejected

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────
            $table->index(['user_id', 'status']);
            $table->index(['seller_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};