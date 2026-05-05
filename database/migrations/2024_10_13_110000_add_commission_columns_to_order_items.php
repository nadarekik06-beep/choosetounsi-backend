<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 4 commission columns to order_items.
 *
 * These are populated at ORDER CREATION TIME by CommissionService
 * and must NEVER be recalculated afterward — they represent the
 * exact deal the seller accepted at the time of the transaction.
 *
 * commission_percentage  — final rate applied (e.g. 11.00 = 11%)
 * commission_amount      — what the platform keeps from this line
 * seller_amount          — what the seller receives from this line
 * plan_used              — seller's active plan at checkout time
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Platform's cut as a percentage (e.g. 11.00 for 11%)
            $table->decimal('commission_percentage', 5, 2)
                  ->default(0)
                  ->after('total')
                  ->comment('Final commission rate applied at checkout');

            // Absolute amount the platform keeps (total × rate)
            $table->decimal('commission_amount', 10, 3)
                  ->default(0)
                  ->after('commission_percentage')
                  ->comment('Platform fee in TND');

            // What the seller actually receives (total − commission)
            $table->decimal('seller_amount', 10, 3)
                  ->default(0)
                  ->after('commission_amount')
                  ->comment('Seller net earnings in TND');

            // Seller plan at the time of purchase (for audit trail)
            $table->enum('plan_used', ['free', 'red', 'black'])
                  ->default('free')
                  ->after('seller_amount')
                  ->comment('Seller active plan snapshot at checkout');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'commission_percentage',
                'commission_amount',
                'seller_amount',
                'plan_used',
            ]);
        });
    }
};