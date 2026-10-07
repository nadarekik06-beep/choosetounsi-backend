<?php

namespace App\Observers;

use App\Jobs\CheckGoalMilestones;
use App\Models\RevenueGoal;
use App\Models\SellerOrder;

/**
 * Goal milestones follow real sales: a sub-order entering or leaving a counted
 * status (seller confirms, courier delivers, cancellation…) re-checks the
 * seller's current goal. Only sellers with a goal this month pay for the check.
 */
class ProfitGoalTriggers
{
    public static function register(): void
    {
        SellerOrder::updated(function (SellerOrder $so) {
            if (!$so->wasChanged('status')) return;
            $counted = config('profit.sale_statuses');
            if (in_array($so->getOriginal('status'), $counted, true) === in_array($so->status, $counted, true)) return;

            $ym = now(config('profit.timezone'))->format('Y-m');
            if (!RevenueGoal::where('seller_id', $so->seller_id)->where('month', $ym)->exists()) return;

            CheckGoalMilestones::dispatchAfterResponse((int) $so->seller_id);
        });
    }
}
