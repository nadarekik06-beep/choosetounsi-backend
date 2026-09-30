<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Recommendation\InteractionTracker;

class OrderObserver
{
    /**
     * Cancelled / refunded: the purchase stops counting in the buyer's interest profile.
     * (Ad results don't need a hook: AdMetrics reads the order's status live.)
     */
    public function updated(Order $order): void
    {
        if (!$order->isDirty('status')) {
            return;
        }

        $newStatus = (string) $order->status;

        if (in_array($newStatus, ['cancelled', 'refunded'], true)) {
            app(InteractionTracker::class)->markDirty((int) $order->user_id, null);
        }
    }
}
