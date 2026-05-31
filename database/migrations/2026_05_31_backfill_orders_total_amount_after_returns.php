<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE orders o
            SET total_amount = (
                SELECT COALESCE(SUM(so.subtotal), 0)
                FROM seller_orders so
                WHERE so.order_id = o.id
                AND so.status != 'cancelled'
            ) + COALESCE(o.shipping_fee, 0)
            WHERE EXISTS (
                SELECT 1 FROM seller_orders so
                WHERE so.order_id = o.id
                AND so.payment_status = 'refunded'
            )
        ");
    }

    public function down(): void
    {
        // Intentionally left empty — financial correction,
        // rolling back would restore incorrect stale amounts.
    }
};
