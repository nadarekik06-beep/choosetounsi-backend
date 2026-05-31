<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: add_item_ids_to_complaints_table
 *
 * Adds the order_item_ids column (JSON array) so a complaint can target
 * one or more specific items within an order rather than the whole order.
 *
 * Backward-compatible: nullable, existing rows stay valid (NULL = full order).
 *
 * Run: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            /**
             * JSON array of order_items.id values the customer is complaining about.
             * NULL  → legacy complaint (pre-fix), treated as "entire order".
             * [1,2] → specifically items with those IDs.
             *
             * Stored after 'order_id' column for logical grouping.
             */
            $table->json('order_item_ids')->nullable()->after('order_id');

            // Index not added — JSON columns aren't directly indexable in MySQL
            // without generated columns; the column is queried via JSON functions
            // or loaded via Eloquent, both of which work fine without an index.
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropColumn('order_item_ids');
        });
    }
};