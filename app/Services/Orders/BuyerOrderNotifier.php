<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Notifications\Buyer\OrderPlacedNotification;
use App\Notifications\Buyer\OrderStatusNotification;
use App\Notifications\Buyer\PaymentNotification;
use App\Services\Notifications\BuyerNotifier;
use App\Services\ReviewPromptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tells the buyer (whoever placed the order — client or seller account) about
 * their order, the twin of SellerOrderNotifier:
 *
 *   placed           COD / wallet right away; card / D17 once paid (receipt)
 *   payment pending  D17 order waiting for the transfer
 *   payment failed   card payment refused
 *   statusChanged()  any code path that wrote seller_orders.status calls it:
 *                    confirmed → packed ('completed') → shipped ('out_for_delivery')
 *                    → delivered, or cancelled — one notification per step and
 *                    sub-order (several sub-orders reaching a step in the same
 *                    action share one notification naming every shop)
 *
 * Safe to call from anywhere, as often as you like: each (sub-order, step) is
 * claimed once in notification_dispatches, in the caller's transaction, and the
 * queued notification is pushed after it commits.
 */
class BuyerOrderNotifier
{
    /** seller_orders.status → buyer step. pending / processing say nothing. */
    public const STEPS = [
        'confirmed'         => 'confirmed',
        'completed'         => 'packed',
        'handed_to_courier' => 'shipped',
        'out_for_delivery'  => 'shipped',
        'delivered'         => 'delivered',
        'cancelled'         => 'cancelled',
    ];

    /** Cancellation reasons (wording of the cancelled notification). */
    public const REASON_SELLER  = 'seller';
    public const REASON_ADMIN   = 'admin';
    public const REASON_PAYMENT = 'payment';
    /** Cancelled because the return was refunded: RefundNotification already says it. */
    public const REASON_REFUND  = 'refund';

    public function __construct(private BuyerNotifier $notifier) {}

    public function orderPlaced(Order $order): void
    {
        $this->safely(function () use ($order) {
            $online = in_array($order->payment_method, SellerOrderNotifier::ONLINE_METHODS, true);

            if ($online && $order->payment_status !== 'paid') {
                // Card: the buyer is on the payment page; failure has its own message.
                if ($order->payment_method === 'd17') {
                    $this->notifier->send($order->user, new PaymentNotification($order, 'pending'));
                }
                return;
            }

            if ($order->status === 'cancelled') return;
            $this->notifier->send($order->user, new OrderPlacedNotification($order));
        });
    }

    public function paymentFailed(Order $order): void
    {
        $this->safely(function () use ($order) {
            if ($order->payment_status === 'paid' || $order->status === 'cancelled') return;
            $this->notifier->send($order->user, new PaymentNotification($order, 'failed'));
        });
    }

    /**
     * Sub-orders whose status was just written.
     *
     * @param Order|int  $order
     * @param int[]|null $sellerOrderIds limit to these sub-orders (null: all of the order)
     * @param string     $reason         who cancelled (REASON_*), when a sub-order is cancelled
     */
    public function statusChanged($order, ?array $sellerOrderIds = null, string $reason = self::REASON_ADMIN): void
    {
        $this->safely(function () use ($order, $sellerOrderIds, $reason) {
            $order = $order instanceof Order ? $order : Order::find($order);
            if (!$order || !$order->user_id || $reason === self::REASON_REFUND) return;

            $buyer = $order->user;
            if (!$buyer) return;

            $sellerOrders = SellerOrder::where('order_id', $order->id)
                ->when($sellerOrderIds !== null, fn ($q) => $q->whereIn('id', $sellerOrderIds))
                ->get(['id', 'order_id', 'seller_id', 'status']);

            // Grouped by step, so one admin action on a multi-shop order is one message.
            $byStep = [];
            foreach ($sellerOrders as $so) {
                $step = self::STEPS[$so->status] ?? null;
                if (!$step) continue;
                // Self-purchase is blocked at checkout; a stray one must not notify the seller about their own order.
                if ((int) $so->seller_id === (int) $order->user_id) continue;
                // Delivered: the /orders review popup gets its prompts now, the reminder N days later.
                if ($step === 'delivered') ReviewPromptService::dispatch($so);
                $byStep[$step][] = $so->id;
            }

            foreach ($byStep as $step => $ids) {
                $claimed = array_values(array_filter($ids, fn ($id) => $this->claim($order->user_id, "seller_order:{$id}:{$step}")));
                if (!$claimed) continue;

                $this->notifier->send($buyer, new OrderStatusNotification(
                    $order->fresh(),
                    $step,
                    $claimed,
                    $step === 'cancelled' ? $reason : null,
                    $step === 'shipped' ? $this->courier($claimed) : null,
                ));
            }
        });
    }

    /**
     * Mark a step as already told for these sub-orders, without sending anything
     * (e.g. a full return cancels the sub-order: the refund notification covers it).
     */
    public function markHandled(int $orderId, array $sellerOrderIds, string $step): void
    {
        $this->safely(function () use ($orderId, $sellerOrderIds, $step) {
            $userId = (int) Order::whereKey($orderId)->value('user_id');
            if (!$userId) return;
            foreach ($sellerOrderIds as $id) {
                $this->claim($userId, "seller_order:{$id}:{$step}");
            }
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function claim(int $userId, string $key): bool
    {
        return DB::table('notification_dispatches')->insertOrIgnore([
            'user_id'    => $userId,
            'dedupe_key' => $key,
            'created_at' => now(),
        ]) === 1;
    }

    /** First name of the courier who picked the parcel up, if one is assigned. */
    private function courier(array $sellerOrderIds): ?string
    {
        $name = DB::table('delivery_assignments as da')
            ->join('users as u', 'u.id', '=', 'da.delivery_guy_id')
            ->whereIn('da.seller_order_id', $sellerOrderIds)
            ->orderByDesc('da.id')
            ->value('u.name');

        $name = trim((string) $name);
        return $name === '' ? null : preg_split('/\s+/u', $name)[0];
    }

    /** Never break checkout or a status update because of a notification. */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('[BuyerOrderNotifier] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }
}
