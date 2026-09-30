<?php

namespace App\Console\Commands;

use App\Services\Ads\AdClock;
use App\Services\Ads\AdMetrics;
use App\Services\Ads\AdSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings every stored ad aggregate back in line with the raw data (AdMetrics
 * definitions). Idempotent — run it as often as you like; it never touches money.
 *
 *   1. repeat clicks: an unpaid click by a viewer who already had a counted click on
 *      the campaign within ads.click_dedupe_hours stops counting (charged clicks are
 *      never changed)
 *   2. attributions whose click no longer counts move to the buyer's previous valid
 *      click on that product (or are dropped when there is none)
 *   3. attribution status snapshot: converted (delivered) / reversed (cancelled, refunded)
 *   4. sponsorship_daily_stats (ad ranking + forecasts) rebuilt for the window
 *   5. the campaigns' cached counters re-synced (all time)
 *
 * Scheduled daily at 03:45 Africa/Tunis.
 *   php artisan ads:rebuild-stats                 last 2 days
 *   php artisan ads:rebuild-stats --all           everything
 *   php artisan ads:rebuild-stats --all --dry-run shows what would change, writes nothing
 */
class RebuildAdStats extends Command
{
    protected $signature   = 'ads:rebuild-stats {--days=2 : Recent Africa/Tunis days to rebuild} {--all : Rebuild everything} {--dry-run : Report only, write nothing}';
    protected $description = 'Rebuild ad click flags, attributions, daily stats and campaign counters from raw data';

    public function handle(AdMetrics $metrics, AdSettings $settings): int
    {
        $from = $this->option('all') ? null : AdClock::now()->subDays(max(1, (int) $this->option('days')) - 1)->toDateString();
        $dedupeHours = max(1, $settings->int('click_dedupe_hours'));

        DB::beginTransaction();
        try {
            $unflagged = $this->dedupeClicks($from, $dedupeHours);
            [$moved, $dropped] = $this->repointAttributions($settings->int('attribution_days'));
            $this->snapshotAttributionStatus();

            $q = DB::table('sponsorship_daily_stats');
            if ($from) {
                $q->where('date', '>=', $from);
            }
            $q->delete();
            $cells = $metrics->cells(null, $from, AdClock::today());
            foreach (array_chunk($cells, 500) as $chunk) {
                DB::table('sponsorship_daily_stats')->insert(array_map(fn ($c) => [
                    'sponsorship_id' => $c['campaign_id'], 'date' => $c['date'], 'placement' => mb_substr($c['placement'], 0, 30),
                    'impressions' => $c['impressions'], 'clicks' => $c['clicks'], 'cost' => $c['spend'],
                    'orders' => $c['orders'], 'revenue' => $c['revenue'], 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }

            $ids = DB::table('sponsorships')->pluck('id')->all();
            foreach ($metrics->perCampaign($ids) as $id => $m) {
                DB::table('sponsorships')->where('id', $id)->update([
                    'impressions' => $m['impressions'], 'clicks' => $m['clicks'],
                    'conversions' => $m['orders'], 'attributed_orders' => $m['orders'], 'attributed_revenue' => $m['revenue'],
                ]);
            }

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->info(($this->option('dry-run') ? '[dry run] ' : '') . sprintf(
            'Since %s: %d repeat clicks un-counted, %d attributions moved, %d dropped, %d daily cells, %d campaigns synced.',
            $from ?? 'the start', $unflagged, $moved, $dropped, count($cells), count($ids)
        ));
        return self::SUCCESS;
    }

    /** Step 1. Returns how many clicks stopped counting. */
    private function dedupeClicks(?string $from, int $hours): int
    {
        $q = DB::table('sponsorship_events')->where('event', 'click')->where('countable', true)
            ->orderBy('sponsorship_id')->orderBy('created_at')->orderBy('id');
        if ($from) {
            // Earlier clicks inside the window still decide whether the first ones repeat.
            $q->where('created_at', '>=', AdClock::dayStart($from)->subHours($hours)->toDateTimeString());
        }

        $changed = [];
        foreach ($q->get(['id', 'sponsorship_id', 'user_id', 'session_id', 'ip_hash', 'billable', 'created_at'])->groupBy('sponsorship_id') as $clicks) {
            $counted = [];
            foreach ($clicks as $e) {
                $repeat = !$e->billable && collect($counted)->contains(fn ($v) =>
                    (($e->user_id && $v->user_id == $e->user_id) || ($e->session_id && $v->session_id === $e->session_id)
                        || (!$e->user_id && !$e->session_id && $e->ip_hash && $v->ip_hash === $e->ip_hash))
                    && strtotime($e->created_at) - strtotime($v->created_at) < $hours * 3600);
                $repeat ? $changed[] = $e->id : $counted[] = $e;
            }
        }
        foreach (array_chunk($changed, 500) as $chunk) {
            DB::table('sponsorship_events')->whereIn('id', $chunk)->update(['countable' => false]);
        }
        return count($changed);
    }

    /** Step 2 (same rule as AttributionService::recordOrder). */
    private function repointAttributions(int $days): array
    {
        $moved = $dropped = 0;
        $stale = DB::table('order_ad_attributions as a')
            ->join('sponsorship_events as ce', 'ce.id', '=', 'a.click_event_id')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->join('order_items as oi', 'oi.id', '=', 'a.order_item_id')
            ->where('ce.countable', false)
            ->get(['a.id', 'o.user_id', 'o.created_at', 'oi.product_id', 'ce.session_id']);

        foreach ($stale as $row) {
            $click = DB::table('sponsorship_events as e')->join('sponsorships as s', 's.id', '=', 'e.sponsorship_id')
                ->where('s.product_id', $row->product_id)->where('e.event', 'click')->where('e.countable', true)
                ->whereBetween('e.created_at', [Carbon::parse($row->created_at)->subDays(max(1, $days)), $row->created_at])
                ->where(function ($q) use ($row) {
                    if ($row->user_id) {
                        $q->orWhere('e.user_id', $row->user_id);
                    }
                    if ($row->session_id) {
                        $q->orWhere('e.session_id', $row->session_id);
                    }
                })
                ->orderByDesc('e.id')->first(['e.id', 'e.sponsorship_id']);

            if ($click) {
                DB::table('order_ad_attributions')->where('id', $row->id)
                    ->update(['click_event_id' => $click->id, 'sponsorship_id' => $click->sponsorship_id, 'updated_at' => now()]);
                $moved++;
            } else {
                DB::table('order_ad_attributions')->where('id', $row->id)->delete();
                $dropped++;
            }
        }
        return [$moved, $dropped];
    }

    /** Step 3. */
    private function snapshotAttributionStatus(): void
    {
        $dead = "'" . implode("','", AdMetrics::DEAD_STATUSES) . "'";
        DB::statement("
            UPDATE order_ad_attributions a
            JOIN order_items oi ON oi.id = a.order_item_id
            JOIN orders o ON o.id = a.order_id
            LEFT JOIN seller_orders so ON so.id = oi.seller_order_id
            SET a.status = CASE
                    WHEN o.status IN ({$dead}) OR so.status IN ({$dead}) OR so.payment_status = 'refunded' THEN 'reversed'
                    WHEN COALESCE(so.status, o.status) IN ('delivered', 'completed') THEN 'converted'
                    ELSE 'pending' END,
                a.converted_at = CASE WHEN COALESCE(so.status, o.status) IN ('delivered', 'completed') THEN COALESCE(a.converted_at, ?) ELSE a.converted_at END,
                a.reversed_at  = CASE WHEN o.status IN ({$dead}) OR so.status IN ({$dead}) OR so.payment_status = 'refunded' THEN COALESCE(a.reversed_at, ?) ELSE NULL END
        ", [now()->toDateTimeString(), now()->toDateTimeString()]);
    }
}
