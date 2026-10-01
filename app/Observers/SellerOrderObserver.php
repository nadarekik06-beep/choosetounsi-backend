<?php

namespace App\Observers;

use App\Models\SellerOrder;
use App\Services\PromotionService;

class SellerOrderObserver
{
    /**
     * Cancelled seller order: its flash-sale units go back to the quota.
     * (Writes through DB::table call PromotionService::releaseForSellerOrders()
     * themselves; promotions:sync sweeps anything missed.)
     */
    public function updated(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->isDirty('status') && $sellerOrder->status === 'cancelled') {
            app(PromotionService::class)->releaseForSellerOrders([$sellerOrder->id]);
        }
    }
}
