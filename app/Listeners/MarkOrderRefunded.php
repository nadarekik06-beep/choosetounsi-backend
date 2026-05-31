<?php

namespace App\Listeners;

use App\Events\RefundCompleted;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\RefundCompletedNotification;

/**
 * FILE: app/Listeners/MarkOrderRefunded.php  ← REPLACE
 *
 * Triggered by: RefundCompleted event
 * (fired when a delivery guy marks a RefundDeliveryTask as 'completed')
 *
 * This listener handles ALL outcomes based on complaint.resolution_type
 * and whether the complaint covers partial or all items in the seller_order.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * RESOLUTION MATRIX
 * ══════════════════════════════════════════════════════════════════════════
 *
 *  resolution_type = 'exchange':
 *    → Agent delivered a replacement. No stock change. No financial change.
 *      Order stays 'delivered'. Just mark complaint refund_status = completed.
 *
 *  resolution_type = 'return_refund' (or NULL legacy):
 *    → Agent collected the complained item(s) and returned them to seller.
 *
 *    PARTIAL (only some items in seller_order were complained about):
 *      → Restore stock for each returned item (product or variant)
 *      → Reverse commission on returned items
 *      → Adjust seller_order.subtotal downward by returned items' total
 *      → seller_order.status stays 'delivered' (non-complained items are fine)
 *      → seller_order.payment_status = 'refunded' (partial refund noted)
 *      → orders.status stays derived (still 'delivered')
 *
 *    FULL (ALL items in seller_order were complained about, or no specific
 *          items were selected — legacy behaviour):
 *      → Restore stock for all returned items
 *      → Reverse all commission on the seller_order
 *      → seller_order.status = 'cancelled'
 *      → seller_order.payment_status = 'refunded'
 *      → Sync parent orders.status (will become 'cancelled' if all sub-orders cancelled)
 *
 * ══════════════════════════════════════════════════════════════════════════
 */
class MarkOrderRefunded
{
    public function handle(RefundCompleted $event): void
    {
        $task = $event->task;

        try {
            // ── Resolve complaint and order ────────────────────────────────
            $task->loadMissing('complaint');
            $complaint = $task->complaint;

            if (!$complaint) {
                Log::error("[RefundCompleted] Task #{$task->id} has no complaint.");
                return;
            }

            $orderId  = $complaint->order_id;
            $sellerId = $task->seller_id;

            if (!$orderId || !$sellerId) {
                Log::error("[RefundCompleted] Task #{$task->id} missing order_id or seller_id.");
                return;
            }

            // ── Load the seller_order ──────────────────────────────────────
            $sellerOrder = SellerOrder::where('order_id', $orderId)
                ->where('seller_id', $sellerId)
                ->with('items')
                ->first();

            if (!$sellerOrder) {
                Log::error("[RefundCompleted] No seller_order found for order #{$orderId}, seller #{$sellerId}.");
                return;
            }

            // ── Branch by resolution type ──────────────────────────────────
            if ($complaint->isExchange()) {
                $this->handleExchange($task, $complaint);
            } else {
                $this->handleReturnRefund($task, $complaint, $sellerOrder, $orderId, $sellerId);
            }

            // ── Notify customer (always, outside transaction) ──────────────
            $order = Order::with('user')->find($orderId);
            if ($order?->user) {
                try {
                    $order->user->notify(new RefundCompletedNotification($task, $order));
                } catch (\Throwable $e) {
                    Log::error("[RefundCompleted] Customer notification failed: " . $e->getMessage());
                }
            }

            // ── Notify seller ──────────────────────────────────────────────
            if ($sellerOrder) {
                $seller      = \App\Models\User::find($sellerId);
                $orderNumber = $order?->order_number ?? "#{$orderId}";
                if ($seller) {
                    try {
                        $seller->notify(
                            new \App\Notifications\RefundStatusNotification(
                                'pickup_done', $sellerOrder, $orderNumber
                            )
                        );
                    } catch (\Throwable $e) {
                        Log::error("[RefundCompleted] Seller notification failed: " . $e->getMessage());
                    }
                }
            }

        } catch (\Throwable $e) {
            Log::error("[RefundCompleted] Failed for task #{$task->id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // EXCHANGE: agent delivered replacement — nothing changes financially
    // ──────────────────────────────────────────────────────────────────────

    private function handleExchange($task, Complaint $complaint): void
    {
        // Just mark the complaint as fully resolved
        Complaint::where('id', $complaint->id)
            ->update(['refund_status' => Complaint::REFUND_STATUS_COMPLETED]);

        Log::info("[RefundCompleted] Exchange completed for complaint #{$complaint->id}. No stock/financial changes.");
    }

    // ──────────────────────────────────────────────────────────────────────
    // RETURN + REFUND: agent collected items → restore stock + adjust finances
    // ──────────────────────────────────────────────────────────────────────

    private function handleReturnRefund(
        $task,
        Complaint $complaint,
        SellerOrder $sellerOrder,
        int $orderId,
        int $sellerId
    ): void {
        // ── Determine which items are being returned ───────────────────────
        $complainedItemIds = $complaint->order_item_ids; // array|null

        // All items in this seller_order
        $allSellerItems = $sellerOrder->items;

        // Items actually being returned
        if (!empty($complainedItemIds)) {
            $returnedItems = $allSellerItems->whereIn('id', $complainedItemIds);
        } else {
            // Legacy: no specific items → return everything
            $returnedItems = $allSellerItems;
        }

        // Is this a full or partial return?
        $isFullReturn = $returnedItems->count() === $allSellerItems->count();

        DB::transaction(function () use (
            $task, $complaint, $sellerOrder,
            $returnedItems, $isFullReturn, $orderId
        ) {
            // ── 1. Restore stock for each returned item ────────────────────
            foreach ($returnedItems as $item) {
                $this->restoreStock($item);
            }

            // ── 2. Calculate financial impact of returned items ────────────
            $returnedSubtotal   = $returnedItems->sum(fn($i) => (float) $i->total);
            $returnedCommission = $returnedItems->sum(fn($i) => (float) ($i->commission_amount ?? 0));
            $returnedSellerNet  = $returnedItems->sum(fn($i) => (float) ($i->seller_amount ?? $i->total));

            Log::info("[RefundCompleted] Returning {$returnedItems->count()} item(s). " .
                "Subtotal: {$returnedSubtotal}, Commission reversed: {$returnedCommission}. " .
                "Full return: " . ($isFullReturn ? 'yes' : 'no'));

            // ── 3. Update seller_order based on partial vs full ────────────
            if ($isFullReturn) {
                // Full return → cancel the seller_order entirely
                $sellerOrder->update([
                    'status'         => 'cancelled',
                    'payment_status' => 'refunded',
                    // Adjust financial columns (reverse commission)
                    'commission_amount'  => 0,
                    'seller_net_amount'  => 0,
                    'platform_profit'    => DB::raw('delivery_fee'), // only delivery fee remains
                ]);

                // Sync parent order (may become 'cancelled' if all sub-orders cancelled)
                $this->syncParentOrderStatus($orderId);

            } else {
                // Partial return → keep seller_order as 'delivered' for remaining items
                // Adjust subtotal and commission to exclude returned items
                $newSubtotal        = max(0, (float) $sellerOrder->subtotal - $returnedSubtotal);
                $newCommission      = max(0, (float) $sellerOrder->commission_amount - $returnedCommission);
                $newSellerNet       = max(0, (float) $sellerOrder->seller_net_amount - $returnedSellerNet);

                $sellerOrder->update([
                    // status stays 'delivered' — remaining items are fine
                    'payment_status'    => 'refunded',   // partial refund
                    'subtotal'          => round($newSubtotal,   3),
                    'commission_amount' => round($newCommission,  3),
                    'seller_net_amount' => round($newSellerNet,   3),
                ]);

                // Parent order status unchanged (still 'delivered')
            }

            // ── 4. Mark complaint refund as complete ───────────────────────
            Complaint::where('id', $complaint->id)
                ->update(['refund_status' => Complaint::REFUND_STATUS_COMPLETED]);
                // ── 5. Sync orders.total_amount from sum of seller_orders.subtotal ─────
                   $newOrderTotal = SellerOrder::where('order_id', $orderId)
                        ->where('status', '!=', 'cancelled')
                        ->sum('subtotal');

                    $shippingFee = Order::where('id', $orderId)->value('shipping_fee') ?? 0;

                    Order::where('id', $orderId)->update([
                        'total_amount' => round((float) $newOrderTotal + (float) $shippingFee, 3),
                    ]);

                    Log::info("[RefundCompleted] orders.total_amount updated → {$newOrderTotal} for order #{$orderId}.");
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // STOCK RESTORATION
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Restore stock for a returned order item.
     *
     * Priority:
     *   1. If item has a variant_id → restore ProductVariant.stock
     *   2. Otherwise → restore Product.stock
     *
     * Uses increment() to avoid race conditions (atomic DB operation).
     */
    private function restoreStock(OrderItem $item): void
    {
        try {
            $qty = (int) $item->quantity;

            if ($item->variant_id) {
                // Restore variant stock
                DB::table('product_variants')
                    ->where('id', $item->variant_id)
                    ->increment('stock', $qty);

                Log::info("[RefundCompleted] Restored {$qty} units to variant #{$item->variant_id} " .
                    "for order_item #{$item->id} ({$item->product_name}).");
            } elseif ($item->product_id) {
                // Restore product stock (simple product, no variants)
                DB::table('products')
                    ->where('id', $item->product_id)
                    ->increment('stock', $qty);

                Log::info("[RefundCompleted] Restored {$qty} units to product #{$item->product_id} " .
                    "for order_item #{$item->id} ({$item->product_name}).");
            }
        } catch (\Throwable $e) {
            Log::error("[RefundCompleted] Stock restoration failed for order_item #{$item->id}: " .
                $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // PARENT ORDER STATUS SYNC (identical to all other controllers)
    // ──────────────────────────────────────────────────────────────────────

    private function syncParentOrderStatus(int $orderId): void
    {
        $statuses = SellerOrder::where('order_id', $orderId)
            ->pluck('status')
            ->toArray();

        if (empty($statuses)) return;

        $unique = array_unique($statuses);

        $derived = match (true) {
            $unique === ['cancelled']
                => 'cancelled',
            $unique === ['delivered']
                => 'delivered',
            in_array('out_for_delivery', $statuses)
                => 'out_for_delivery',
            count(array_diff($unique, ['completed', 'delivered'])) === 0
                => 'completed',
            default
                => 'processing',
        };

        Order::where('id', $orderId)->update(['status' => $derived]);

        Log::info("[RefundCompleted] Parent order #{$orderId} status synced → {$derived}.");
    }
}