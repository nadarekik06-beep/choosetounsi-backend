<?php

namespace App\Console\Commands;

use App\Models\SellerOrder;
use App\Services\Orders\SellerOrderNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reminds sellers about confirmed sub-orders that are still not marked ready
 * for pickup ("completed") N hours after the confirmation e-mail went out.
 * At most one reminder per sub-order. Off unless SELLER_PICKUP_REMINDER=true.
 */
class RemindSellerPickup extends Command
{
    protected $signature   = 'orders:remind-seller-pickup';
    protected $description = 'Remind sellers about confirmed sub-orders not ready for pickup after 24h (SELLER_PICKUP_REMINDER)';

    public function handle(SellerOrderNotifier $notifier): int
    {
        if (!config('seller_notifications.pickup_reminder.enabled')) {
            $this->info('Pickup reminders are disabled (SELLER_PICKUP_REMINDER=false).');
            return self::SUCCESS;
        }

        $cutoff = now()->subHours(config('seller_notifications.pickup_reminder.hours'));

        $confirmedLongAgo = DB::table('seller_order_notifications')
            ->where('event', 'confirmed')
            ->where('created_at', '<=', $cutoff)
            ->select('seller_order_id');

        $sent = 0;
        SellerOrder::where('status', 'confirmed')
            ->whereIn('id', $confirmedLongAgo)
            ->whereNotExists(fn($q) => $q->from('seller_order_notifications as r')
                ->whereColumn('r.seller_order_id', 'seller_orders.id')
                ->where('r.event', 'pickup_reminder'))
            ->with('seller')
            ->chunkById(100, function ($sellerOrders) use ($notifier, &$sent) {
                foreach ($sellerOrders as $sellerOrder) {
                    if ($sellerOrder->seller && $notifier->pickupReminder($sellerOrder)) {
                        $sent++;
                    }
                }
            });

        $this->info("Pickup reminders sent: {$sent}");
        return self::SUCCESS;
    }
}
