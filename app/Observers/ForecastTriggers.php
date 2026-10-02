<?php

namespace App\Observers;

use App\Jobs\ComputeSellerForecast;
use App\Models\Complaint;
use App\Models\SellerOrder;

/**
 * Recompute a seller's forecast when real sales change: a sub-order enters or
 * leaves a counted status (confirmed, cancelled, refunded…) or a return is
 * approved. Delayed 2 minutes and unique per seller, so bursts are one run.
 */
class ForecastTriggers
{
    public static function register(): void
    {
        SellerOrder::updated(function (SellerOrder $so) {
            if (!$so->wasChanged('status')) return;
            $counted = config('forecast.sale_statuses');
            $was = in_array($so->getOriginal('status'), $counted, true);
            $now = in_array($so->status, $counted, true);
            if ($was !== $now) self::dispatch((int) $so->seller_id);
        });

        Complaint::updated(function (Complaint $c) {
            if ($c->wasChanged('status') && $c->status === 'approved' && $c->resolution_type === 'return_refund' && $c->seller_id) {
                self::dispatch((int) $c->seller_id);
            }
        });
    }

    public static function dispatch(int $sellerId): void
    {
        try {
            ComputeSellerForecast::dispatch($sellerId)->delay(now()->addMinutes(2));
        } catch (\Throwable $e) {
            // Never let a forecast hiccup break an order update.
            report($e);
        }
    }
}
