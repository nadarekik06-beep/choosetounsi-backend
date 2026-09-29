<?php

namespace App\Services\Ads;

use Illuminate\Support\Facades\DB;

/** Incremental writes to sponsorship_daily_stats (campaign × Africa/Tunis day × placement). */
class AdStats
{
    /**
     * Add (or, with negative values, remove) counts for one day and placement.
     *
     * @param array{impressions?: int, clicks?: int, cost?: float, orders?: int, revenue?: float} $delta
     */
    public static function add(int $sponsorshipId, string $placement, array $delta, ?string $date = null): void
    {
        $d = array_merge(['impressions' => 0, 'clicks' => 0, 'cost' => 0, 'orders' => 0, 'revenue' => 0], $delta);
        $v = [$d['impressions'], $d['clicks'], $d['cost'], $d['orders'], $d['revenue']];

        DB::statement(
            'INSERT INTO sponsorship_daily_stats (sponsorship_id, date, placement, impressions, clicks, cost, orders, revenue, created_at, updated_at)
             VALUES (?, ?, ?, GREATEST(?, 0), GREATEST(?, 0), GREATEST(?, 0), GREATEST(?, 0), GREATEST(?, 0), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                impressions = GREATEST(CAST(impressions AS SIGNED) + ?, 0),
                clicks      = GREATEST(CAST(clicks AS SIGNED) + ?, 0),
                cost        = GREATEST(cost + ?, 0),
                orders      = GREATEST(CAST(orders AS SIGNED) + ?, 0),
                revenue     = GREATEST(revenue + ?, 0),
                updated_at  = NOW()',
            array_merge([$sponsorshipId, $date ?? AdClock::today(), mb_substr($placement, 0, 30)], $v, $v)
        );
    }
}
