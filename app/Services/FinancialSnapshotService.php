<?php
// app/Services/FinancialSnapshotService.php

namespace App\Services;

use App\Models\SellerOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FinancialSnapshotService
 *
 * Called ONCE at order creation to freeze all financial data
 * on the seller_order row.
 *
 * NEVER call this on existing orders — it would recalculate them.
 * NEVER call CommissionService here — read from order_items only.
 */
class FinancialSnapshotService
{
    private const DELIVERY_FEE = 8.000;

    /**
     * Compute and store financial snapshot on a seller_order.
     *
     * Reads commission data from order_items (already stored at checkout)
     * and aggregates it onto the seller_order for fast dashboard queries.
     *
     * @param  int  $sellerOrderId
     * @return void
     */
    public function freeze(int $sellerOrderId): void
    {
        try {
            $totals = DB::table('order_items')
                ->where('seller_order_id', $sellerOrderId)
                ->selectRaw('
                    COALESCE(SUM(commission_amount), 0) as total_commission,
                    COALESCE(SUM(seller_amount), 0)     as total_seller_net,
                    COALESCE(SUM(total), 0)             as total_gross
                ')
                ->first();

            $commissionAmount = round((float) ($totals->total_commission ?? 0), 3);
            $sellerNetAmount  = round((float) ($totals->total_seller_net  ?? 0), 3);
            $deliveryFee      = self::DELIVERY_FEE;
            $platformProfit   = round($commissionAmount + $deliveryFee, 3);

            DB::table('seller_orders')
                ->where('id', $sellerOrderId)
                ->update([
                    'commission_amount' => $commissionAmount,
                    'seller_net_amount' => $sellerNetAmount,
                    'delivery_fee'      => $deliveryFee,
                    'platform_profit'   => $platformProfit,
                    'payout_status'     => 'pending',
                    'updated_at'        => now(),
                ]);

        } catch (\Throwable $e) {
            Log::error('[FinancialSnapshotService::freeze] seller_order_id=' . $sellerOrderId . ' — ' . $e->getMessage());
        }
    }

    /**
     * Mark money as received from delivery company.
     * Transitions payout_status from 'pending' to 'ready'.
     *
     * Only call this after delivery confirmation AND admin cash receipt.
     *
     * @param  int  $sellerOrderId
     * @param  int  $adminUserId
     * @return bool
     */
    public function confirmMoneyReceived(int $sellerOrderId, int $adminUserId): bool
    {
        $sellerOrder = SellerOrder::find($sellerOrderId);

        if (!$sellerOrder) {
            return false;
        }

        // Guard: must be delivered first
        if (!in_array($sellerOrder->status, ['delivered', 'completed'])) {
    return false;
}
        // Guard: already processed
        if ($sellerOrder->payout_status !== 'pending') {
            return false;
        }

        DB::table('seller_orders')
            ->where('id', $sellerOrderId)
            ->update([
                'money_received_at' => now(),
                'money_received_by' => $adminUserId,
                'payout_status'     => 'ready',
                'payment_status'    => 'paid',
                'updated_at'        => now(),
            ]);

        return true;
    }
}