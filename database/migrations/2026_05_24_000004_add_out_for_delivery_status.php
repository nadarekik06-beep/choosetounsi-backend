<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration: add 'out_for_delivery' to the status ENUM of orders and seller_orders.
 *
 * This status is set when the delivery guy marks an assignment as 'picked_up'.
 * It gives clients, sellers, and admins a distinct tracking step between
 * 'processing' (seller prepared) and 'delivered' (delivery confirmed).
 *
 * Filename: 2026_05_24_000004_add_out_for_delivery_status.php
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── orders ─────────────────────────────────────────────────────────
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM(
                'pending',
                'processing',
                'out_for_delivery',
                'completed',
                'delivered',
                'cancelled',
                'refunded'
            )
            NOT NULL
            DEFAULT 'pending'
        ");

        // ── seller_orders ──────────────────────────────────────────────────
        DB::statement("
            ALTER TABLE seller_orders
            MODIFY COLUMN status
            ENUM(
                'pending',
                'processing',
                'out_for_delivery',
                'completed',
                'delivered',
                'cancelled',
                'refunded'
            )
            NOT NULL
            DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // WARNING: only run down() if no rows have status = 'out_for_delivery'
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM('pending','processing','completed','delivered','cancelled','refunded')
            NOT NULL
            DEFAULT 'pending'
        ");

        DB::statement("
            ALTER TABLE seller_orders
            MODIFY COLUMN status
            ENUM('pending','processing','completed','delivered','cancelled','refunded')
            NOT NULL
            DEFAULT 'pending'
        ");
    }
};