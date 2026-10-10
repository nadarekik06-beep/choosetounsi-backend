<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One seller_order = one parcel = one pickup, with its own delivery fee and
 * cash-on-delivery amount, frozen at checkout.
 *
 * Snapshot of the admin settings in force when the order was placed:
 *   client_delivery_fee, agency_delivery_cost, seller_free_delivery_contribution,
 *   is_free_delivery
 * Applied amounts keep their existing columns:
 *   delivery_fee            what the client pays for this parcel (0 when free)
 *   shipping_cost           what the agency keeps (= agency_delivery_cost)
 *   seller_shipping_charge  what the seller pays (contribution when free, else 0)
 *   seller_net_amount       the seller payout
 * Cash flow:
 *   cod_amount, amount_to_remit (= cod − agency), cash_collected_at,
 *   money_received_at (existing: remittance received by us)
 * Refused at the door: refused_at, refused_agency_fee, refused_fee_paid_by.
 *
 * Backfill fills ONLY the new columns, from what old orders actually booked
 * (one shipping fee per order, on the first seller_order). Payouts,
 * commissions and settlements are never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table) {
            $table->decimal('client_delivery_fee', 12, 3)->nullable()->after('delivery_fee');
            $table->decimal('agency_delivery_cost', 12, 3)->nullable()->after('client_delivery_fee');
            $table->decimal('seller_free_delivery_contribution', 12, 3)->nullable()->after('agency_delivery_cost');
            $table->boolean('is_free_delivery')->nullable()->after('seller_free_delivery_contribution');
            $table->decimal('platform_delivery_margin', 12, 3)->nullable()->after('is_free_delivery');
            $table->decimal('cod_amount', 12, 3)->nullable()->after('platform_delivery_margin');
            $table->decimal('amount_to_remit', 12, 3)->nullable()->after('cod_amount');
            $table->timestamp('cash_collected_at')->nullable()->after('delivery_confirmed_at');
            $table->timestamp('refused_at')->nullable()->after('cash_collected_at');
            $table->decimal('refused_agency_fee', 12, 3)->nullable()->after('refused_at');
            $table->string('refused_fee_paid_by', 10)->nullable()->after('refused_agency_fee');
        });

        Schema::table('orders', function (Blueprint $table) {
            // true = delivery charged per parcel (seller_orders.delivery_fee each);
            // false = legacy: one shipping_fee per order, booked on the first seller_order
            $table->boolean('shipping_per_parcel')->default(false)->after('shipping_paid_by');
        });

        // Add 'refused' to the existing status ENUMs, keeping every value they
        // already have (environments differ: some still carry 'processing').
        foreach (['seller_orders', 'orders'] as $table) {
            $this->setStatusValues($table, fn (array $values) => in_array('refused', $values, true) ? $values : [...$values, 'refused']);
        }

        $this->backfill();
    }

    /** Rewrite a status ENUM from its CURRENT values (never a hardcoded list). */
    private function setStatusValues(string $table, \Closure $change): void
    {
        $type = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'status']
        )->t;
        preg_match_all("/'([^']*)'/", $type, $m);   // status values never contain quotes
        $values = $change($m[1]);
        $list   = implode(',', array_map(fn ($v) => DB::getPdo()->quote($v), $values));
        DB::statement("ALTER TABLE `{$table}` MODIFY `status` ENUM({$list}) NOT NULL DEFAULT 'pending'");
    }

    /** Legacy orders: what was actually booked, per seller_order. */
    private function backfill(): void
    {
        DB::statement("
            UPDATE seller_orders so
            JOIN orders o ON o.id = so.order_id
            SET so.client_delivery_fee               = o.shipping_fee,
                so.agency_delivery_cost              = so.shipping_cost,
                so.seller_free_delivery_contribution = so.seller_shipping_charge,
                so.is_free_delivery                  = (o.shipping_paid_by = 'seller'),
                so.platform_delivery_margin          = so.delivery_fee + so.seller_shipping_charge - so.shipping_cost,
                so.cod_amount                        = CASE WHEN COALESCE(o.payment_method, 'cod') = 'cod'
                                                            THEN so.subtotal - so.discount_amount + so.delivery_fee
                                                            ELSE 0 END,
                so.amount_to_remit                   = CASE WHEN COALESCE(o.payment_method, 'cod') = 'cod'
                                                            THEN so.subtotal - so.discount_amount + so.delivery_fee - so.shipping_cost
                                                            ELSE 0 END,
                so.cash_collected_at                 = CASE WHEN so.status IN ('delivered', 'completed')
                                                            THEN COALESCE(so.delivery_confirmed_at, so.money_received_at, so.updated_at)
                                                            ELSE NULL END
            WHERE so.cod_amount IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE seller_orders SET status = 'cancelled' WHERE status = 'refused'");
        DB::statement("UPDATE orders SET status = 'cancelled' WHERE status = 'refused'");
        foreach (['seller_orders', 'orders'] as $table) {
            $this->setStatusValues($table, fn (array $values) => array_values(array_diff($values, ['refused'])));
        }

        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('shipping_per_parcel'));
        Schema::table('seller_orders', fn (Blueprint $t) => $t->dropColumn([
            'client_delivery_fee', 'agency_delivery_cost', 'seller_free_delivery_contribution', 'is_free_delivery',
            'platform_delivery_margin', 'cod_amount', 'amount_to_remit', 'cash_collected_at',
            'refused_at', 'refused_agency_fee', 'refused_fee_paid_by',
        ]));
    }
};
