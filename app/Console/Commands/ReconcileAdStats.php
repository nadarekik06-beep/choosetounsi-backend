<?php

namespace App\Console\Commands;

use App\Services\Ads\AdClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds sponsorship_daily_stats for the last N Africa/Tunis days, and the
 * campaigns' cached counters, from sponsorship_events (the source of truth).
 *
 * Scheduled daily at 03:45 Africa/Tunis.   php artisan ads:reconcile-stats --days=2
 */
class ReconcileAdStats extends Command
{
    protected $signature   = 'ads:reconcile-stats {--days=2 : How many recent days of daily stats to rebuild}';
    protected $description = 'Rebuild ad daily stats and campaign counters from events';

    private const CLICKS  = "SUM(e.event = 'click' AND e.countable = 1)";
    private const COST    = "SUM(CASE WHEN e.event = 'click' THEN e.cost ELSE 0 END)";
    // A reversed order drops out of the day it was converted (as the incremental writes do).
    private const LIVE_CONVERSION = "e.event = 'conversion' AND NOT EXISTS (SELECT 1 FROM sponsorship_events r
        WHERE r.sponsorship_id = e.sponsorship_id AND r.order_id = e.order_id AND r.event = 'conversion_reversed')";
    private const ORDERS  = 'SUM(' . self::LIVE_CONVERSION . ')';
    private const REVENUE = 'SUM(CASE WHEN ' . self::LIVE_CONVERSION . ' THEN COALESCE(e.revenue, 0) ELSE 0 END)';

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $from  = AdClock::now()->subDays($days - 1)->startOfDay();
        // Africa/Tunis has no DST: its offset is fixed, so no MySQL time zone tables are needed.
        $local = "DATE(CONVERT_TZ(e.created_at, '+00:00', '" . AdClock::now()->format('P') . "'))";

        DB::transaction(function () use ($from, $local) {
            DB::table('sponsorship_daily_stats')->where('date', '>=', $from->toDateString())->delete();

            DB::statement("
                INSERT INTO sponsorship_daily_stats (sponsorship_id, date, placement, impressions, clicks, cost, orders, revenue, created_at, updated_at)
                SELECT e.sponsorship_id, {$local} AS d, e.placement, SUM(e.event = 'impression'), " . self::CLICKS . ', ' . self::COST . ', '
                    . self::ORDERS . ', ' . self::REVENUE . ", NOW(), NOW()
                FROM sponsorship_events e
                WHERE e.created_at >= ?
                GROUP BY e.sponsorship_id, d, e.placement
            ", [AdClock::toStorage($from)->toDateTimeString()]);

            DB::statement("
                UPDATE sponsorships s
                JOIN (
                    SELECT e.sponsorship_id, SUM(e.event = 'impression') AS imp, " . self::CLICKS . ' AS clk, '
                        . self::ORDERS . ' AS conv, ' . self::REVENUE . " AS rev
                    FROM sponsorship_events e GROUP BY e.sponsorship_id
                ) e ON e.sponsorship_id = s.id
                SET s.impressions = e.imp, s.clicks = e.clk, s.conversions = e.conv,
                    s.attributed_orders = e.conv, s.attributed_revenue = e.rev
            ");
        });

        $this->info("Rebuilt ad stats since {$from->toDateString()}.");
        return self::SUCCESS;
    }
}
