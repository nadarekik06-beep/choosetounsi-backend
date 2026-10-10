<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Custom per-product delivery fees are gone: a product is either free
 * delivery (delivery_fee = 0) or charged the admin's client_delivery_fee
 * (delivery_fee = NULL). Any other value becomes NULL. Orders keep the fee
 * they were charged (frozen on the order).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('products')->whereNotNull('delivery_fee')->where('delivery_fee', '>', 0)->pluck('id')->all();
        if ($ids) {
            Log::info('[migration] custom delivery fee removed from products: ' . implode(',', $ids));
            DB::table('products')->whereIn('id', $ids)->update(['delivery_fee' => null]);
        }
    }

    public function down(): void
    {
        // Not reversible: the old custom values are only in the log.
    }
};
