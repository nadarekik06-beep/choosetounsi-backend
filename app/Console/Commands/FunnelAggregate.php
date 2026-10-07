<?php

namespace App\Console\Commands;

use App\Services\VisitorInsights\FunnelAggregator;
use App\Services\VisitorInsights\FunnelBenchmarks;
use App\Services\VisitorInsights\FunnelExclusions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Visitor Insights roll-ups.
 *
 *   php artisan funnel:aggregate                 nightly: last 3 days + today, benchmarks, prune raw events
 *   php artisan funnel:aggregate --today         hourly: today only
 *   php artisan funnel:aggregate --backfill=90   history: flag the seller's own / staff / bot rows,
 *                                                give old events a source, rebuild the last 90 days
 *   php artisan funnel:aggregate --date=2026-10-01
 */
class FunnelAggregate extends Command
{
    protected $signature = 'funnel:aggregate
        {--date= : Rebuild one day (Y-m-d, Africa/Tunis)}
        {--days=3 : Rebuild this many past days (plus today)}
        {--today : Only today (no benchmarks)}
        {--backfill= : Clean history and rebuild this many days}';

    protected $description = 'Roll funnel events up into product_daily_stats / product_daily_traffic and rebuild category benchmarks';

    public function handle(FunnelAggregator $agg, FunnelBenchmarks $bench, FunnelExclusions $exclusions): int
    {
        $tz = config('funnel.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        if ($this->option('date')) {
            $day = CarbonImmutable::parse($this->option('date'), $tz);
            $this->line("{$day->format('Y-m-d')}: " . $agg->aggregateDay($day) . ' products');
            return self::SUCCESS;
        }
        if ($this->option('today')) {
            $this->line('today: ' . $agg->aggregateDay($today) . ' products');
            Cache::forget('funnel:tracking-since');
            return self::SUCCESS;
        }

        $backfill = $this->option('backfill') !== null ? max(1, (int) $this->option('backfill')) : null;
        $days = $backfill ?? max(1, (int) $this->option('days'));
        $since = $backfill ? null : $today->subDays($days + 1)->utc()->format('Y-m-d H:i:s');

        $flagged = $exclusions->markHistory($since);
        $this->line("excluded rows (seller / staff / bot): $flagged");
        $sourced = $agg->backfillSources($since);
        $this->line("events given a traffic source: $sourced");

        $bar = $this->output->createProgressBar($days + 1);
        for ($d = $days; $d >= 0; $d--) {
            $agg->aggregateDay($today->subDays($d));
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $rows = $bench->build($today->subDay());
        $this->line("benchmarks: $rows scopes");
        $this->line('raw events pruned: ' . $agg->pruneRaw());
        Cache::forget('funnel:tracking-since');
        return self::SUCCESS;
    }
}
