<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One parcel workflow (App\Services\Orders\ParcelStatus), ready for the
 * delivery agency API:
 *
 *   statuses   + handed_to_courier   (parcel picked up by the agency)
 *              + returned_to_seller  (refused parcel back at the seller: stock released)
 *   carrier    seller_orders.carrier_tracking_number, carrier_status_raw,
 *              carrier_status_at (nullable; filled by the future API mapping)
 *   history    seller_order_status_history: from → to, who (changed_by),
 *              what (source: admin | seller | courier | api | system), when
 *
 * Data fix: cancelled parcels whose payout was still 'pending' / 'ready'
 * (cancelled before payouts followed cancellation) → payout 'cancelled'.
 * Parcels already in a settlement batch are only logged, never rewritten.
 */
return new class extends Migration
{
    private const NEW_STATUSES = ['handed_to_courier', 'returned_to_seller'];

    public function up(): void
    {
        foreach (['seller_orders', 'orders'] as $table) {
            $this->setStatusValues($table, fn (array $values) => array_values(array_unique([...$values, ...self::NEW_STATUSES])));
        }

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->string('carrier_tracking_number', 64)->nullable()->after('status');
            $table->string('carrier_status_raw', 191)->nullable()->after('carrier_tracking_number');
            $table->timestamp('carrier_status_at')->nullable()->after('carrier_status_raw');
            $table->timestamp('returned_to_seller_at')->nullable()->after('refused_fee_paid_by');
            $table->index('carrier_tracking_number');
        });

        Schema::create('seller_order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('source', 16);                       // admin | seller | courier | api | system
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('carrier_status_raw', 191)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['seller_order_id', 'created_at']);
        });

        $this->cancelPayoutsOfCancelledParcels();
    }

    private function cancelPayoutsOfCancelledParcels(): void
    {
        $rows = DB::table('seller_orders as so')->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('so.status', 'cancelled')
            ->where('so.payout_status', '!=', 'cancelled')
            ->get(['so.id', 'o.order_number', 'so.payout_status', 'so.settlement_batch_id']);

        $fix = $rows->filter(fn ($r) => in_array($r->payout_status, ['pending', 'ready'], true) && $r->settlement_batch_id === null);
        if ($fix->isNotEmpty()) {
            DB::table('seller_orders')->whereIn('id', $fix->pluck('id'))
                ->update(['payout_status' => 'cancelled', 'updated_at' => now()]);
            Log::info('[migration] cancelled parcels: payout → cancelled', $fix->map(fn ($r) => "{$r->id} {$r->order_number} ({$r->payout_status})")->values()->all());
        }

        $review = $rows->diffKeys($fix);
        if ($review->isNotEmpty()) {
            Log::warning('[migration] cancelled parcels with money already moved (not touched)', $review->map(fn ($r) => "{$r->id} {$r->order_number} ({$r->payout_status}, batch {$r->settlement_batch_id})")->values()->all());
        }
    }

    /** Rewrite a status ENUM from its CURRENT values (never a hardcoded list). */
    private function setStatusValues(string $table, \Closure $change): void
    {
        $type = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'status']
        )->t;
        preg_match_all("/'([^']*)'/", $type, $m);
        $list = implode(',', array_map(fn ($v) => DB::getPdo()->quote($v), $change($m[1])));
        DB::statement("ALTER TABLE `{$table}` MODIFY `status` ENUM({$list}) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_order_status_history');

        Schema::table('seller_orders', function (Blueprint $table) {
            $table->dropIndex(['carrier_tracking_number']);
            $table->dropColumn(['carrier_tracking_number', 'carrier_status_raw', 'carrier_status_at', 'returned_to_seller_at']);
        });

        foreach (['seller_orders', 'orders'] as $table) {
            DB::table($table)->where('status', 'handed_to_courier')->update(['status' => 'out_for_delivery']);
            DB::table($table)->where('status', 'returned_to_seller')->update(['status' => 'refused']);
            $this->setStatusValues($table, fn (array $values) => array_values(array_diff($values, self::NEW_STATUSES)));
        }
        // The payout data fix is not reverted: a cancelled parcel owes nothing.
    }
};
