<?php

namespace App\Services\Returns;

use Illuminate\Support\Facades\DB;

/** Settlement batch totals, including the seller adjustments it carries. */
class Settlements
{
    /** A draft batch whose orders changed (a return was refunded): totals again. */
    public static function recomputeDraft(int $batchId): void
    {
        $batch = DB::table('settlement_batches')->where('id', $batchId)->first();
        if (!$batch || $batch->status !== 'draft') {
            return;
        }

        $orders = DB::table('seller_orders')->where('settlement_batch_id', $batchId)->get();
        $adjustments = round((float) DB::table('seller_adjustments')->where('settlement_batch_id', $batchId)->sum('amount'), 3);

        DB::table('settlement_batches')->where('id', $batchId)->update([
            'total_orders_gross'    => round($orders->sum(fn($o) => (float) $o->subtotal - (float) $o->discount_amount), 3),
            'total_commission'      => round((float) $orders->sum('commission_amount'), 3),
            'total_delivery_fees'   => round((float) $orders->sum('delivery_fee'), 3),
            'total_adjustments'     => $adjustments,
            'total_seller_payout'   => round((float) $orders->sum('seller_net_amount') + $adjustments, 3),
            'total_platform_profit' => round((float) $orders->sum('platform_profit'), 3),
            'orders_count'          => $orders->count(),
            'updated_at'            => now(),
        ]);
    }
}
