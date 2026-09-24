<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot WHERE a commission rate came from at order time.
 * Existing rows stay NULL — past orders are never recalculated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'commission_source')) {
                // override | plan | default
                $table->string('commission_source', 20)->nullable()->after('commission_percentage');
            }
        });

        Schema::table('seller_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_orders', 'commission_rate')) {
                // Effective rate on this seller order (commission_amount / net × 100)
                $table->decimal('commission_rate', 5, 2)->nullable()->after('commission_amount');
            }
            if (!Schema::hasColumn('seller_orders', 'commission_source')) {
                $table->string('commission_source', 20)->nullable()->after('commission_rate');
            }
            if (!Schema::hasColumn('seller_orders', 'plan_used')) {
                $table->string('plan_used', 30)->nullable()->after('commission_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_source', 'plan_used']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('commission_source');
        });
    }
};
