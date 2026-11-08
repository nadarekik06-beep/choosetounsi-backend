<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who confirmed the parcel: only an admin confirmation lets the seller mark it
 * prepared (which sends it to Intigo). Set by ParcelStatus on an admin move to
 * 'confirmed', cleared when the parcel goes back to pending or is cancelled.
 *
 * Backfill: parcels past 'pending' whose history shows an admin confirmation.
 * A parcel only a seller confirmed stays NULL (it was never admin-confirmed).
 * Additive: no existing column or row status changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('seller_orders', 'admin_confirmed_at')) {
                $table->timestamp('admin_confirmed_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('seller_orders', 'admin_confirmed_by')) {
                $table->unsignedBigInteger('admin_confirmed_by')->nullable()->after('admin_confirmed_at');
            }
        });

        $rows = DB::table('seller_order_status_history')
            ->where('to_status', 'confirmed')
            ->where('source', 'admin')
            ->orderBy('id')
            ->get(['seller_order_id', 'changed_by', 'created_at']);

        // Latest admin confirmation per parcel
        foreach ($rows->keyBy('seller_order_id') as $soId => $h) {
            DB::table('seller_orders')
                ->where('id', $soId)
                ->whereNull('admin_confirmed_at')
                ->whereNotIn('status', ['pending', 'cancelled'])
                ->update(['admin_confirmed_at' => $h->created_at, 'admin_confirmed_by' => $h->changed_by]);
        }
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            if (Schema::hasColumn('seller_orders', 'admin_confirmed_by')) {
                $table->dropColumn('admin_confirmed_by');
            }
            if (Schema::hasColumn('seller_orders', 'admin_confirmed_at')) {
                $table->dropColumn('admin_confirmed_at');
            }
        });
    }
};
