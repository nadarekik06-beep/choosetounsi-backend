<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CouponService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * Card orders whose payment never completed: after 30 minutes the order is
 * cancelled so what it held goes back —
 *   stock          the units decremented at checkout
 *   flash quota    via SellerOrderObserver (seller orders → cancelled)
 *   coupon use     the redemption is removed
 *
 * The Stripe PaymentIntent is cancelled first; if Stripe refuses (the customer
 * is paying right now, or already paid) the order is left alone.
 */
class CancelAbandonedCardOrders extends Command
{
    protected $signature   = 'orders:cancel-abandoned-card {--minutes=30 : Age of an unpaid card order before it is cancelled}';
    protected $description = 'Cancel unpaid card orders older than 30 minutes and give back their stock, flash quota and coupon use';

    public function handle(CouponService $coupons): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $orders = Order::where('payment_method', 'card')
            ->where('payment_status', 'unpaid')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->get();

        $cancelled = 0;
        foreach ($orders as $order) {
            if (!$this->cancelPaymentIntent($order)) {
                continue;
            }
            if ($this->cancel($order, $coupons)) {
                $cancelled++;
            }
        }

        $this->info("Abandoned card orders cancelled: {$cancelled}");
        return self::SUCCESS;
    }

    private function cancel(Order $order, CouponService $coupons): bool
    {
        return DB::transaction(function () use ($order, $coupons) {
            // Re-check under lock: the webhook may have marked it paid meanwhile
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked || $locked->payment_status !== 'unpaid' || $locked->status !== 'pending') {
                return false;
            }

            foreach ($locked->sellerOrders as $sellerOrder) {
                $sellerOrder->update(['status' => 'cancelled']);   // observer releases flash units
            }
            $locked->update(['status' => 'cancelled']);

            foreach (DB::table('order_items')->where('order_id', $locked->id)->get() as $item) {
                $item->variant_id
                    ? DB::table('product_variants')->where('id', $item->variant_id)->increment('stock', (int) $item->quantity)
                    : DB::table('products')->where('id', $item->product_id)->increment('stock', (int) $item->quantity);
            }

            $coupons->releaseForOrder($locked);

            Log::info("[AbandonedCard] Order #{$locked->order_number} cancelled: card payment not completed.");
            return true;
        });
    }

    /** True when the order may be cancelled (no intent, or Stripe cancelled it). */
    private function cancelPaymentIntent(Order $order): bool
    {
        if (!$order->stripe_payment_intent_id) {
            return true;
        }
        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $intent = PaymentIntent::retrieve($order->stripe_payment_intent_id);
            if (in_array($intent->status, ['succeeded', 'processing'], true)) {
                return false;   // the webhook will mark it paid
            }
            if ($intent->status !== 'canceled') {
                $intent->cancel();
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning("[AbandonedCard] Could not cancel intent for order #{$order->order_number}: " . $e->getMessage());
            return false;
        }
    }
}
