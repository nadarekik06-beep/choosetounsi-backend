<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FILE: database/migrations/2026_03_28_200000_add_seller_rejected_to_complaints_status.php
 *
 * Adds 'seller_rejected_pending_admin' to the complaints.status ENUM.
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE complaints
            MODIFY COLUMN status
            ENUM('pending','reviewing','approved','seller_rejected_pending_admin','rejected')
            NOT NULL
            DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // First move any seller_rejected_pending_admin rows back to pending
        DB::table('complaints')
            ->where('status', 'seller_rejected_pending_admin')
            ->update(['status' => 'pending']);

        DB::statement("
            ALTER TABLE complaints
            MODIFY COLUMN status
            ENUM('pending','reviewing','approved','rejected')
            NOT NULL
            DEFAULT 'pending'
        ");
    }
};