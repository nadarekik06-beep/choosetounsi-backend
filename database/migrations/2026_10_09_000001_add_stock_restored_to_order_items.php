<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout reserves stock per order line; this records that the line's units
 * went back to the shelf (order cancelled, or item returned) so it can never
 * happen twice — see App\Services\Orders\OrderStock.
 *
 * Backfill: lines that may already have gone back before this column existed
 * (cancelled sub-orders, completed return complaints) are stamped 'legacy' so
 * the new code never restores them again. Nothing is added to stock here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('stock_restored_at')->nullable()->after('flash_reserved');
            $table->string('stock_restored_reason', 16)->nullable()->after('stock_restored_at'); // cancelled | returned | legacy
        });

        $now = now();

        DB::table('order_items')
            ->whereIn('seller_order_id', DB::table('seller_orders')->where('status', 'cancelled')->select('id'))
            ->update(['stock_restored_at' => $now, 'stock_restored_reason' => 'legacy']);

        $returnedIds = DB::table('complaints')
            ->where('refund_status', 'completed')
            ->where(fn ($q) => $q->whereNull('resolution_type')->orWhere('resolution_type', '!=', 'exchange'))
            ->whereNotNull('order_item_ids')
            ->pluck('order_item_ids')
            ->flatMap(fn ($json) => (array) json_decode($json, true))
            ->filter()->unique()->values()->all();

        foreach (array_chunk($returnedIds, 500) as $chunk) {
            DB::table('order_items')->whereIn('id', $chunk)->whereNull('stock_restored_at')
                ->update(['stock_restored_at' => $now, 'stock_restored_reason' => 'legacy']);
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['stock_restored_at', 'stock_restored_reason']);
        });
    }
};
