<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'delivered' to the orders.status ENUM column.
 *
 * IMPORTANT: Never edit the original migration on an existing DB.
 * This migration uses a raw ALTER TABLE because Laravel's Schema builder
 * cannot modify ENUM columns directly on MySQL.
 *
 * Run with: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // Modify the ENUM to include 'delivered'
        // Full list must be repeated — MySQL replaces the entire ENUM definition
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM('pending', 'processing', 'completed', 'delivered', 'cancelled', 'refunded')
            NOT NULL
            DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // Revert: remove 'delivered' (rows with status='delivered' become empty string — handle with caution)
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM('pending', 'processing', 'completed', 'cancelled', 'refunded')
            NOT NULL
            DEFAULT 'pending'
        ");
    }
};