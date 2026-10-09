<?php

namespace App\Observers;

use App\Models\SellerOrder;
use App\Services\Orders\OrderStock;
use App\Services\PromotionService;

class SellerOrderObserver
{
    /**
     * Cancelled sub-order put back in play: its lines take their stock again.
     * Runs before the save so a sold-out line (InsufficientStock) blocks it.
     */
    public function updating(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->isDirty('status')
            && $sellerOrder->getOriginal('status') === 'cancelled'
            && $sellerOrder->status !== 'cancelled') {
            app(OrderStock::class)->reclaimForSellerOrders([$sellerOrder->id]);
        }
    }

    /** Re-opened after a cancel: the payout waits for cash again. */
    public function saved(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->wasChanged('status') && $sellerOrder->getOriginal('status') === 'cancelled') {
            SellerOrder::syncPayoutWithStatus([$sellerOrder->id], false);
        }
    }

    /**
     * Cancelled seller order: its stock and flash-sale units go back.
     * (Writes through DB::table call OrderStock / PromotionService themselves;
     * promotions:sync sweeps any flash units missed.)
     */
    public function updated(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->isDirty('status') && $sellerOrder->status === 'cancelled') {
            app(OrderStock::class)->releaseForSellerOrders([$sellerOrder->id]);
            app(PromotionService::class)->releaseForSellerOrders([$sellerOrder->id]);
            SellerOrder::syncPayoutWithStatus([$sellerOrder->id], true);
        }
    }
}
