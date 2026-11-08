<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * products.hidden_reason gets 'seller_suspended': products of a suspended seller are
 * hidden from the storefront (App\Services\Sellers\SuspendedSellerProducts) and restored
 * when the seller is reactivated. Additive: existing values and rows are untouched.
 *
 * down() only narrows the enum when no row uses the new value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE products MODIFY hidden_reason ENUM('over_plan_limit','admin_suspended','seller_suspended') NULL DEFAULT NULL");
    }

    public function down(): void
    {
        if (DB::table('products')->where('hidden_reason', 'seller_suspended')->exists()) return;
        DB::statement("ALTER TABLE products MODIFY hidden_reason ENUM('over_plan_limit','admin_suspended') NULL DEFAULT NULL");
    }
};
