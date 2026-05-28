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
 * Responsibilities (all inside a single DB transaction):
 *   1. Update Order.status              → 'refunded'
 *   2. Update SellerOrder.status        → 'refunded' (the relevant sub-order)
 *   3. Update Complaint.refund_status   → 'completed'
 *   4. Send notification to customer    (outside transaction, queued)
 *
 * This listener runs SYNCHRONOUSLY to guarantee atomicity of the
 * status cascade before the API returns 200 to the delivery app.
 *
 * Notifications are dispatched after the transaction to avoid
 * blocking the response on email/push delivery latency.
 */
class MarkOrderRefunded
{
    /**
     * Handle the RefundCompleted event.
     */
    public function handle(RefundCompleted $event): void
    {
        $task = $event->task;

        try {
            DB::transaction(function () use ($task) {

                // ── 1. Update the parent Order ─────────────────────────────
                Order::where('id', $task->order_id)
                    ->update(['status' => 'refunded']);

                // ── 2. Update the relevant SellerOrder ─────────────────────
                // We target the SellerOrder that belongs to this seller
                // within the parent order, to preserve multi-seller isolation.
                SellerOrder::where('order_id', $task->order_id)
                    ->where('seller_id',  $task->seller_id)
                    ->update(['status' => 'refunded']);

                // ── 3. Update the Complaint refund status ──────────────────
                Complaint::where('id', $task->complaint_id)
                    ->update(['refund_status' => Complaint::REFUND_STATUS_COMPLETED]);

                Log::info(
                    "[RefundCompleted] Order #{$task->order_id} → refunded. " .
                    "Complaint #{$task->complaint_id} refund_status → completed."
                );
            });

            // ── 4. Notify the customer (outside transaction) ───────────────
            // Load the order with its user for the notification
            $order = Order::with('user')->find($task->order_id);
            if ($order && $order->user) {
                try {
                    $order->user->notify(new RefundCompletedNotification($task, $order));
                } catch (\Throwable $e) {
                    Log::error("[RefundCompleted] Notification failed: " . $e->getMessage());
                }
            }

        } catch (\Throwable $e) {
            // Log but do not re-throw — the delivery guy's status update
            // has already been saved; we don't want to roll that back.
            Log::error(
                "[RefundCompleted] Status cascade failed for task #{$task->id}: " .
                $e->getMessage()
            );
        }
    }
}