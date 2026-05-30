<?php

namespace App\Listeners;

use App\Events\RefundCompleted;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\SellerOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\RefundCompletedNotification;

/**
 * Listener: MarkOrderRefunded
 *
 * Triggered by: RefundCompleted event
 * (fired when a delivery guy marks a RefundDeliveryTask as 'completed')
 *
 * BUGS FIXED IN THIS VERSION:
 *
 *   BUG 1 — $task->order_id was always null:
 *     RefundDeliveryTask has no order_id column — it only has complaint_id.
 *     The order must be resolved via: task → complaint → order_id.
 *     Fix: load complaint relation and read $task->complaint->order_id.
 *
 *   BUG 2 — 'refunded' is not a valid seller_orders.status ENUM value:
 *     The SellerOrder status column only accepts:
 *       pending | processing | out_for_delivery | completed | delivered | cancelled
 *     Setting status = 'refunded' silently fails (or throws, swallowed by catch).
 *     Fix: set seller_orders.status → 'cancelled' (the logical closed state
 *     for a returned order) AND seller_orders.payment_status → 'refunded'
 *     (the payment column DOES accept 'refunded').
 *     This way the seller dashboard shows:
 *       STATUS badge  → Cancelled  (order is closed/returned)
 *       PAYMENT badge → Refunded   (money was returned)
 *
 *   BUG 3 — orders.status → 'refunded' also invalid:
 *     Same ENUM issue on the parent orders table.
 *     Fix: derive the correct aggregate status via syncParentOrderStatus()
 *     after updating seller_orders, consistent with how all other controllers
 *     handle parent order status (never set it directly — always derive it).
 */
class MarkOrderRefunded
{
    public function handle(RefundCompleted $event): void
    {
        $task = $event->task;

        try {
            // ── Resolve order_id via complaint relation ─────────────────────
            // RefundDeliveryTask has no order_id column — must go through complaint.
            $task->loadMissing('complaint');
            $complaint = $task->complaint;

            if (!$complaint) {
                Log::error("[RefundCompleted] Task #{$task->id} has no complaint relation — cannot resolve order.");
                return;
            }

            $orderId  = $complaint->order_id;
            $sellerId = $task->seller_id;

            if (!$orderId || !$sellerId) {
                Log::error("[RefundCompleted] Task #{$task->id} missing order_id ({$orderId}) or seller_id ({$sellerId}).");
                return;
            }

            DB::transaction(function () use ($task, $complaint, $orderId, $sellerId) {

                // ── 1. Update the SellerOrder ──────────────────────────────
                //
                // FIX BUG 2: 'refunded' is NOT a valid status ENUM value.
                // Valid values: pending | processing | out_for_delivery |
                //               completed | delivered | cancelled
                //
                // Correct approach:
                //   - status         → 'cancelled'  (order is closed/returned)
                //   - payment_status → 'refunded'   (payment_status column accepts this)
                //
                // This produces the correct UI in the seller dashboard:
                //   STATUS badge  → "Cancelled" (red)
                //   PAYMENT badge → "Refunded"  (purple)
                //
                SellerOrder::where('order_id', $orderId)
                    ->where('seller_id', $sellerId)
                    ->update([
                        'status'         => 'cancelled',  // valid ENUM — order is closed
                        'payment_status' => 'refunded',   // payment_status accepts 'refunded'
                    ]);

                // ── 2. Sync the parent orders.status ───────────────────────
                //
                // FIX BUG 3: Never write orders.status directly — always derive
                // it from the current seller_orders states, exactly as
                // SellerOrderController and DeliveryController do.
                //
                $this->syncParentOrderStatus($orderId);

                // ── 3. Update the Complaint refund_status ──────────────────
                Complaint::where('id', $task->complaint_id)
                    ->update(['refund_status' => Complaint::REFUND_STATUS_COMPLETED]);

                Log::info(
                    "[RefundCompleted] SellerOrder (order #{$orderId}, seller #{$sellerId}) " .
                    "→ status: cancelled, payment_status: refunded. " .
                    "Complaint #{$task->complaint_id} refund_status → completed."
                );
            });

            // ── 4. Notify the customer (outside transaction) ───────────────
            $order = Order::with('user')->find($orderId);
            if ($order && $order->user) {
                try {
                    $order->user->notify(new RefundCompletedNotification($task, $order));
                } catch (\Throwable $e) {
                    Log::error("[RefundCompleted] Customer notification failed: " . $e->getMessage());
                }
            }

            // ── 5. Notify the seller ───────────────────────────────────────
            // Seller should also know the physical return is complete.
            $sellerOrder = SellerOrder::where('order_id', $orderId)
                ->where('seller_id', $sellerId)
                ->first();

            if ($sellerOrder) {
                $seller = \App\Models\User::find($sellerId);
                $orderNumber = $order?->order_number ?? "#{$orderId}";

                if ($seller) {
                    try {
                        $seller->notify(
                            new \App\Notifications\RefundStatusNotification(
                                'pickup_done',
                                $sellerOrder,
                                $orderNumber
                            )
                        );
                    } catch (\Throwable $e) {
                        Log::error("[RefundCompleted] Seller notification failed: " . $e->getMessage());
                    }
                }
            }

        } catch (\Throwable $e) {
            Log::error(
                "[RefundCompleted] Status cascade failed for task #{$task->id}: " .
                $e->getMessage()
            );
        }
    }

    /**
     * Derive and write the correct aggregate status to orders.status
     * based on the current state of all seller_orders for that order.
     *
     * Identical logic to SellerOrderController and DeliveryController.
     * Never writes orders.status directly — always derives from seller_orders.
     */
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
    }
}