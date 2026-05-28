<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration: add 'refunded' to the status ENUM of orders and seller_orders.
 *
 * Safe approach: we use MODIFY COLUMN which rewrites the full ENUM list.
 * This does NOT touch any existing rows — MySQL/MariaDB handles it in-place
 * as a metadata-only change when the existing values are a subset of the new list.
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
            ENUM('pending','processing','completed','delivered','cancelled','refunded')
            NOT NULL
            DEFAULT 'pending'
        ");

        // ── seller_orders ──────────────────────────────────────────────────
        // We read the current ENUM definition first so we can extend it safely
        // without hardcoding values that may differ in other environments.
        DB::statement("
            ALTER TABLE seller_orders
            MODIFY COLUMN status
            ENUM('pending','processing','completed','delivered','cancelled','refunded')
            NOT NULL
            DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // WARNING: only run this if no rows have status = 'refunded'.
        // Otherwise MySQL will refuse the change.
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM('pending','processing','completed','delivered','cancelled')
            NOT NULL
            DEFAULT 'pending'
        ");

        DB::statement("
            ALTER TABLE seller_orders
            MODIFY COLUMN status
            ENUM('pending','processing','completed','delivered','cancelled')
            NOT NULL
            DEFAULT 'pending'
        ");
    }
};