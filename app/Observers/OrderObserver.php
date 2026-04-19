<?php
// app/Observers/OrderObserver.php  — ADD this logic to your existing observer,
// OR create this file if you have no existing order observer.
//
// If you already have an OrderObserver, add the updated() method below.
// If not, register this new observer in AppServiceProvider:
//
//   use App\Models\Order;
//   use App\Observers\OrderObserver;
//   Order::observe(OrderObserver::class);

namespace App\Observers;

use App\Models\Order;
use App\Models\Sponsorship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    /**
     * When an order status changes to 'completed' or 'delivered',
     * increment the conversion counter for any sponsored products in that order.
     *
     * This is the ONLY place conversions are tracked.
     * We use a dirty-check so we only fire on the status transition,
     * not on every save of a completed order.
     */
    public function updated(Order $order): void
    {
        // Only act on the specific transition to completed/delivered
        if (!$order->isDirty('status')) {
            return;
        }

        $newStatus = $order->status;
        if (!in_array($newStatus, ['completed', 'delivered'], true)) {
            return;
        }

        try {
            // Get all product_ids in this order
            $productIds = DB::table('order_items')
                ->where('order_id', $order->id)
                ->pluck('product_id')
                ->toArray();

            if (empty($productIds)) {
                return;
            }

            // For each product that has an active (or recently expired) sponsorship,
            // increment conversions. We check 'active' OR sponsorship that was active
            // when the order was placed (end_at > order created_at).
            $orderCreatedAt = $order->created_at ?? now();

            $sponsorships = Sponsorship::whereIn('product_id', $productIds)
                ->where(function ($q) use ($orderCreatedAt) {
                    $q->where('status', 'active')
                      ->orWhere(function ($q2) use ($orderCreatedAt) {
                          // Was active when the order was placed
                          $q2->where('start_at', '<=', $orderCreatedAt)
                             ->where(function ($q3) use ($orderCreatedAt) {
                                 $q3->whereNull('end_at')
                                    ->orWhere('end_at', '>=', $orderCreatedAt);
                             });
                      });
                })
                ->get();

            foreach ($sponsorships as $s) {
                $s->increment('conversions');
            }

            if ($sponsorships->count() > 0) {
                Log::info("[OrderObserver] Recorded {$sponsorships->count()} sponsorship conversion(s) for order #{$order->id}");
            }

        } catch (\Throwable $e) {
            // Never let conversion tracking break order processing
            Log::warning('[OrderObserver::SponsorshipConversion] ' . $e->getMessage());
        }
    }
}