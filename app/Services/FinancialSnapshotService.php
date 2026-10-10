<?php
// app/Services/FinancialSnapshotService.php

namespace App\Services;

use App\Models\SellerOrder;
use App\Support\Millimes;
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
            $deliveryFee      = $this->deliveryFeeFor($sellerOrderId);
            $platformProfit   = round($commissionAmount + $deliveryFee, 3);
            // Shipping cost/charge are applied by allocateShipping() below,
            // once every sibling seller_order of the order exists.

            // Snapshot which rate applied and where it came from (items with
            // commission only — pack follower rows carry 0 by design).
            $rated = DB::table('order_items')
                ->where('seller_order_id', $sellerOrderId)
                ->where('commission_amount', '>', 0)
                ->get(['commission_percentage', 'commission_source', 'plan_used', 'net_total', 'commission_amount']);
            $ratedNet  = (float) $rated->sum('net_total');
            $sources   = $rated->pluck('commission_source')->filter()->unique();

            DB::table('seller_orders')
                ->where('id', $sellerOrderId)
                ->update([
                    'commission_amount' => $commissionAmount,
                    'commission_rate'   => $ratedNet > 0
                        ? round((float) $rated->sum('commission_amount') / $ratedNet * 100, 2)
                        : optional($rated->first())->commission_percentage,
                    'commission_source' => $sources->count() > 1 ? 'mixed' : $sources->first(),
                    'plan_used'         => optional($rated->first())->plan_used,
                    'seller_net_amount' => $sellerNetAmount,
                    'delivery_fee'      => $deliveryFee,
                    'platform_profit'   => $platformProfit,
                    'payout_status'     => 'pending',
                    'updated_at'        => now(),
                ]);

            $orderId = DB::table('seller_orders')->where('id', $sellerOrderId)->value('order_id');
            if ($orderId) {
                $this->allocateShipping((int) $orderId);
            }

        } catch (\Throwable $e) {
            Log::error('[FinancialSnapshotService::freeze] seller_order_id=' . $sellerOrderId . ' — ' . $e->getMessage());
        }
    }

    /**
     * Freeze a parcel (seller_order) at checkout: commission from its stored
     * order_items, delivery from the quote (App\Services\Orders\OrderPricing)
     * with the admin settings in force. Never called on an existing order.
     *
     *   seller_net_amount  = Σ items.seller_amount − seller_shipping_charge (= payout)
     *   platform_profit    = commission + platform_delivery_margin
     */
    public function snapshotParcel(int $sellerOrderId, array $parcel): void
    {
        $items = DB::table('order_items')->where('seller_order_id', $sellerOrderId)
            ->get(['commission_percentage', 'commission_source', 'plan_used', 'net_total', 'commission_amount', 'seller_amount']);

        $commissionM  = $items->sum(fn ($i) => Millimes::of($i->commission_amount));
        $sellerItemsM = $items->sum(fn ($i) => Millimes::of($i->seller_amount));
        $rated        = $items->filter(fn ($i) => Millimes::of($i->commission_amount) > 0);
        $ratedNetM    = $rated->sum(fn ($i) => Millimes::of($i->net_total));
        $sources      = $rated->pluck('commission_source')->filter()->unique();

        // The quote and the stored lines must agree to the millime
        if ($commissionM !== $parcel['commission_m'] || $sellerItemsM !== $parcel['seller_items_net_m']) {
            throw new \LogicException("Parcel {$sellerOrderId}: stored lines differ from the quote.");
        }

        $s   = $parcel['settings'];
        $dec = fn (int $m) => Millimes::toDecimal($m);

        DB::table('seller_orders')->where('id', $sellerOrderId)->update([
            'commission_amount'                 => $dec($commissionM),
            'commission_rate'                   => $ratedNetM > 0
                ? round($rated->sum(fn ($i) => Millimes::of($i->commission_amount)) / $ratedNetM * 100, 2)
                : optional($items->first())->commission_percentage,
            'commission_source'                 => $sources->count() > 1 ? 'mixed' : $sources->first(),
            'plan_used'                         => optional($rated->first() ?? $items->first())->plan_used,
            'seller_net_amount'                 => $dec($parcel['seller_payout_m']),
            'delivery_fee'                      => $dec($parcel['delivery_fee_m']),
            'shipping_cost'                     => $dec($parcel['agency_cost_m']),
            'seller_shipping_charge'            => $dec($parcel['contribution_m']),
            'platform_profit'                   => $dec($commissionM + $parcel['delivery_margin_m']),
            'client_delivery_fee'               => $dec($s['client_delivery_fee']),
            'agency_delivery_cost'              => $dec($s['agency_delivery_cost']),
            'seller_free_delivery_contribution' => $dec($s['seller_free_delivery_contribution']),
            'is_free_delivery'                  => $parcel['is_free_delivery'],
            'platform_delivery_margin'          => $dec($parcel['delivery_margin_m']),
            'cod_amount'                        => $dec($parcel['cod_amount_m']),
            'amount_to_remit'                   => $dec($parcel['amount_to_remit_m']),
            'payout_status'                     => 'pending',
            'updated_at'                        => now(),
        ]);

        self::checkParcel($sellerOrderId);
    }

    /**
     * Money check, every parcel:
     *   parcel total (cash collected for COD) = payout + commission + agency fee + delivery margin
     * Logged (never thrown) when it fails, with the figures.
     */
    public static function checkParcel(int $sellerOrderId): bool
    {
        $so = DB::table('seller_orders')->where('id', $sellerOrderId)->first();
        if (!$so || $so->platform_delivery_margin === null) {
            return true; // legacy row without a parcel snapshot
        }
        $m = fn ($v) => Millimes::of($v ?? 0);

        $total = $m($so->subtotal) - $m($so->discount_amount) + $m($so->delivery_fee);
        $sum   = $m($so->seller_net_amount) + $m($so->commission_amount) + $m($so->shipping_cost) + $m($so->platform_delivery_margin);
        if ($total === $sum) {
            return true;
        }

        Log::error('[MoneyCheck] parcel does not balance', [
            'seller_order_id' => $sellerOrderId,
            'parcel_total'    => Millimes::toDecimal($total),
            'payout'          => $so->seller_net_amount,
            'commission'      => $so->commission_amount,
            'agency_fee'      => $so->shipping_cost,
            'delivery_margin' => $so->platform_delivery_margin,
            'difference'      => Millimes::toDecimal($total - $sum),
        ]);
        return false;
    }

    /**
     * LEGACY (orders placed before per-parcel delivery, and the
     * BackfillSellerOrderFinancials command). Checkout uses snapshotParcel().
     *
     * Spread the order's agency shipping cost over its seller_orders.
     *
     *   paid_by customer/platform → cost booked on the first seller_order,
     *                                next to the delivery_fee the customer paid
     *   paid_by seller            → cost split evenly between the sellers and
     *                                deducted from each one's seller_net_amount
     *
     *   seller_net_amount = Σ items.seller_amount − seller_shipping_charge
     *   platform_profit   = commission + delivery_fee + seller_shipping_charge − shipping_cost
     *
     * Idempotent: freeze() calls it after every seller_order it snapshots, so the
     * last call of a checkout sees all siblings. Commission is never touched.
     */
    public function allocateShipping(int $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['shipping_cost', 'shipping_paid_by']);
        if (!$order || $order->shipping_cost === null) {
            return; // not tracked for this order
        }

        $sellerOrders = DB::table('seller_orders')->where('order_id', $orderId)->orderBy('id')->get(['id', 'commission_amount']);
        if ($sellerOrders->isEmpty()) return;

        $cost      = round((float) $order->shipping_cost, 3);
        $sellerPay = $order->shipping_paid_by === 'seller';

        // Even split in millimes; the first seller_order absorbs the remainder.
        $share  = $sellerPay ? floor($cost * 1000 / $sellerOrders->count()) / 1000 : 0.0;
        $firstId = $sellerOrders->first()->id;

        foreach ($sellerOrders as $so) {
            $isFirst = $so->id === $firstId;

            $shippingCost = $sellerPay
                ? round($isFirst ? $cost - $share * ($sellerOrders->count() - 1) : $share, 3)
                : ($isFirst ? $cost : 0.0);
            $charge       = $sellerPay ? $shippingCost : 0.0;
            $deliveryFee  = $this->deliveryFeeFor($so->id);
            $itemsNet     = (float) DB::table('order_items')->where('seller_order_id', $so->id)->sum('seller_amount');

            DB::table('seller_orders')->where('id', $so->id)->update([
                'delivery_fee'           => $deliveryFee,
                'shipping_cost'          => $shippingCost,
                'seller_shipping_charge' => $charge,
                'seller_net_amount'      => round($itemsNet - $charge, 3),
                'platform_profit'        => round((float) $so->commission_amount + $deliveryFee + $charge - $shippingCost, 3),
                'updated_at'             => now(),
            ]);
        }
    }

    /**
     * Who carries the agency cost for an order the customer pays $shippingFee on.
     * Free shipping (0) means the seller offered it, so the seller pays.
     */
    public static function shippingPayer(float $shippingFee): string
    {
        return $shippingFee > 0 ? 'customer' : 'seller';
    }

    /**
     * The customer pays shipping ONCE per order (orders.shipping_fee), so it is
     * booked on the order's first seller_order only — the others get 0.
     * Prevents a multi-seller order from counting the fee once per seller.
     */
    public function deliveryFeeFor(int $sellerOrderId): float
    {
        $orderId = DB::table('seller_orders')->where('id', $sellerOrderId)->value('order_id');
        if (!$orderId) return 0.0;

        $firstId = DB::table('seller_orders')->where('order_id', $orderId)->min('id');
        if ((int) $firstId !== $sellerOrderId) return 0.0;

        return round((float) (DB::table('orders')->where('id', $orderId)->value('shipping_fee') ?? 0), 3);
    }

    /**
     * Mark money as received from delivery company.
     * Transitions payout_status from 'pending' to 'ready'.
     *
     * After marking the seller_order, syncs the parent orders.payment_status
     * to 'paid' if ALL seller_orders for that order are now paid.
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

        // Guard: delivered (legacy 'completed' counts as delivered) with the
        // cash collected by the courier — the payout is never payable before.
        if (!in_array($sellerOrder->status, ['delivered', 'completed']) || $sellerOrder->getAttribute('cash_collected_at') === null) {
            return false;
        }

        // Guard: already processed
        if ($sellerOrder->payout_status !== 'pending') {
            return false;
        }

        // ── Mark this seller_order as ready + paid ────────────────────────────
        DB::table('seller_orders')
            ->where('id', $sellerOrderId)
            ->update([
                'money_received_at' => now(),
                'money_received_by' => $adminUserId,
                'payout_status'     => 'ready',
                'payment_status'    => 'paid',
                'updated_at'        => now(),
            ]);

        // ── Sync parent order payment_status ──────────────────────────────────
        // If ALL seller_orders for this parent order are now paid,
        // mark the parent orders row as paid too.
        // This keeps the Orders page in sync with the Finance > Cash In action.
        try {
            $orderId = $sellerOrder->order_id;

            $totalSellerOrders = DB::table('seller_orders')
                ->where('order_id', $orderId)
                ->count();

            $paidSellerOrders = DB::table('seller_orders')
                ->where('order_id', $orderId)
                ->where('payment_status', 'paid')
                ->count();

            if ($totalSellerOrders > 0 && $paidSellerOrders === $totalSellerOrders) {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->update([
                        'payment_status' => 'paid',
                        'updated_at'     => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal — the seller_order is already correctly marked.
            // The parent order sync is a display convenience only.
            Log::warning('[FinancialSnapshotService::confirmMoneyReceived] parent sync failed — ' . $e->getMessage());
        }

        return true;
    }
}