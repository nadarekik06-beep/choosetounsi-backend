<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Ads\AttributionService;
use App\Services\Recommendation\InteractionTracker;

class OrderObserver
{
    /**
     * On a status transition:
     *   - delivered / completed → ad attributions of this order convert (once)
     *   - cancelled / refunded  → they are reversed, and the purchase stops counting
     *                             in the buyer's interest profile
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

        app(AttributionService::class)->onStatusChange($order, $newStatus);
    }
}
