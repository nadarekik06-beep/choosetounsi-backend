<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Notifications\Orders\NewSellerOrderNotification;
use App\Notifications\Orders\SellerOrderCancelledNotification;
use App\Notifications\Orders\SellerOrderConfirmedNotification;
use App\Notifications\Orders\SellerOrderNotification;
use App\Notifications\Orders\SellerPickupReminderNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tells sellers about their sub-orders: one notification per seller
 * sub-order and event, never per item.
 *
 *   placed     COD / wallet right away; card / D17 only once paid
 *              (and only while the sub-order is still pending)
 *   confirmed  the admin confirmed it: prepare the package
 *   cancelled  only to sellers who were already told about the order
 *
 * Safe to call from any code path, as often as you like:
 *   - each (sub-order, event) is claimed once in seller_order_notifications
 *     (unique key), so retries and double clicks never send twice;
 *   - the claim is written in the caller's transaction and the notification
 *     is dispatched after it commits, so a rolled-back order sends nothing.
 */
class SellerOrderNotifier
{
    /** Payment methods paid online: the seller hears about them once paid. */
    const ONLINE_METHODS = ['card', 'd17'];

    public function orderPlaced(Order $order): void
    {
        if (in_array($order->payment_method, self::ONLINE_METHODS, true) && $order->payment_status !== 'paid') {
            return;
        }

        // Still pending only: a payment recorded late (D17 confirmed after
        // delivery…) must not announce an order the seller already handled.
        foreach ($this->sellerOrders($order) as $sellerOrder) {
            if ($sellerOrder->status === 'pending') {
                $this->send($sellerOrder, 'placed', new NewSellerOrderNotification($sellerOrder));
            }
        }
    }

    /** @param int[]|null $sellerOrderIds limit to these sub-orders (scoped admin updates) */
    public function orderConfirmed(Order $order, ?array $sellerOrderIds = null): void
    {
        foreach ($this->sellerOrders($order, $sellerOrderIds) as $sellerOrder) {
            if ($sellerOrder->status === 'confirmed') {
                $this->send($sellerOrder, 'confirmed', new SellerOrderConfirmedNotification($sellerOrder));
            }
        }
    }

    /** @param int[]|null $sellerOrderIds limit to these sub-orders (scoped admin updates) */
    public function orderCancelled(Order $order, ?array $sellerOrderIds = null): void
    {
        $sellerOrders = $this->sellerOrders($order, $sellerOrderIds)->where('status', 'cancelled');
        if ($sellerOrders->isEmpty()) {
            return;
        }

        // A seller who never heard about the order has nothing to stop.
        $notified = DB::table('seller_order_notifications')
            ->whereIn('seller_order_id', $sellerOrders->pluck('id'))
            ->whereIn('event', ['placed', 'confirmed'])
            ->pluck('seller_order_id')
            ->all();

        foreach ($sellerOrders as $sellerOrder) {
            if (in_array($sellerOrder->id, $notified)) {
                $this->send($sellerOrder, 'cancelled', new SellerOrderCancelledNotification($sellerOrder));
            }
        }
    }

    public function pickupReminder(SellerOrder $sellerOrder): bool
    {
        return $this->send($sellerOrder, 'pickup_reminder', new SellerPickupReminderNotification($sellerOrder));
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function sellerOrders(Order $order, ?array $ids = null): Collection
    {
        return $order->sellerOrders()
            ->whereNotNull('seller_id')
            ->when($ids !== null, fn($q) => $q->whereIn('id', $ids))
            ->with('seller')
            ->get()
            ->filter(fn(SellerOrder $so) => $so->seller !== null);
    }

    /** True when this call claimed the event (and the notification goes out). */
    private function send(SellerOrder $sellerOrder, string $event, SellerOrderNotification $notification): bool
    {
        $claimed = DB::table('seller_order_notifications')->insertOrIgnore([
            'seller_order_id' => $sellerOrder->id,
            'event'           => $event,
            'created_at'      => now(),
        ]) === 1;

        if (!$claimed) {
            return false;
        }

        $seller = $sellerOrder->seller;
        DB::afterCommit(function () use ($seller, $notification, $sellerOrder, $event) {
            try {
                $seller->notify($notification);
            } catch (\Throwable $e) {
                // Never break checkout or an admin action because of a notification.
                Log::error("[SellerOrderNotifier] {$event} for seller_order {$sellerOrder->id} could not be dispatched: " . $e->getMessage());
            }
        });

        return true;
    }
}
