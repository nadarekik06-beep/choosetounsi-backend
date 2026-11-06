<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT_DISCOUNTS M-1 / L-4:
 *   coupons.deleted_at            a deleted coupon is soft-deleted: its redemption
 *                                 history stays and it can't be applied any more
 *   promotions.per_customer_limit flash sales: units one customer (account, or phone
 *                                 for guests) may buy at the flash price. Default 2;
 *                                 existing flash sales get 2 (owner's decision 2026-11-06)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coupons', 'deleted_at')) {
            Schema::table('coupons', fn (Blueprint $table) => $table->softDeletes());
        }

        if (!Schema::hasColumn('promotions', 'per_customer_limit')) {
            Schema::table('promotions', function (Blueprint $table) {
                $table->unsignedInteger('per_customer_limit')->nullable()->after('flash_stock_used');
            });
            DB::table('promotions')->where('type', 'flash_sale')->update(['per_customer_limit' => 2]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('coupons', 'deleted_at')) {
            Schema::table('coupons', fn (Blueprint $table) => $table->dropSoftDeletes());
        }
        if (Schema::hasColumn('promotions', 'per_customer_limit')) {
            Schema::table('promotions', fn (Blueprint $table) => $table->dropColumn('per_customer_limit'));
        }
    }
};
