<?php

namespace App\Services\VisitorInsights;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rolls one local (Africa/Tunis) day of raw events up into product_daily_stats
 * and product_daily_traffic. Idempotent: the day's rows are rebuilt from
 * scratch, so re-running a day (late order status changes, backfill) is safe.
 *
 * Counted: only rows not flagged funnel_excluded / excluded (seller's own
 * visits, staff, bots). Views are unique per session per day (a browser that
 * opens a product 5 times in a day is one view); the view's source and device
 * are the ones of that session's first view that day. Orders come from
 * seller_orders in a sale status (not cancelled / refunded), never the buyer
 * being the seller, and are attributed to the buyer's last view of the product
 * in the previous config('funnel.order_attribution_days') days (else "direct").
 */
class FunnelAggregator
{
    /** @return int products with activity that day */
    public function aggregateDay(CarbonImmutable $day): int
    {
        $tz = config('funnel.timezone');
        $local = CarbonImmutable::parse($day->format('Y-m-d'), $tz)->startOfDay();
        $from = $local->utc()->format('Y-m-d H:i:s');
        $to   = $local->addDay()->utc()->format('Y-m-d H:i:s');
        $date = $local->format('Y-m-d');

        $stats = [];     // product_id => counters
        $traffic = [];   // "pid|source|device" => counters
        $add = function (int $pid, string $field, int|float $n, ?string $source = null, ?string $device = null) use (&$stats, &$traffic) {
            $stats[$pid][$field] = ($stats[$pid][$field] ?? 0) + $n;
            if ($source !== null) {
                $k = $pid . '|' . ($source ?: 'direct') . '|' . ($device ?: 'unknown');
                $traffic[$k][$field] = ($traffic[$k][$field] ?? 0) + $n;
            }
        };

        // ── Views: first view per (product, session) of the day ────────────────
        $firstViews = DB::table('user_interactions')
            ->where('event_type', 'view')->where('funnel_excluded', false)->whereNotNull('product_id')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("product_id, COALESCE(session_id, CONCAT('u', user_id)) as actor, MIN(id) as first_id")
            ->groupBy('product_id', 'actor');
        $views = DB::table('user_interactions as ui')
            ->joinSub($firstViews, 'f', 'f.first_id', '=', 'ui.id')
            ->selectRaw("ui.product_id, COALESCE(ui.traffic_source, 'direct') as src, COALESCE(ui.device, 'unknown') as dev, COUNT(*) as n")
            ->groupBy('ui.product_id', 'src', 'dev')->get();
        foreach ($views as $r) {
            $add((int) $r->product_id, 'views', (int) $r->n, $r->src, $r->dev);
            $stats[(int) $r->product_id]['views_' . $r->src] = ($stats[(int) $r->product_id]['views_' . $r->src] ?? 0) + (int) $r->n;
        }

        // Unique visitors: people, not sessions (a logged-in buyer on two devices is one)
        $uniques = DB::table('user_interactions')
            ->where('event_type', 'view')->where('funnel_excluded', false)->whereNotNull('product_id')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("product_id, COUNT(DISTINCT COALESCE(CONCAT('u', user_id), session_id)) as n")
            ->groupBy('product_id')->pluck('n', 'product_id');
        foreach ($uniques as $pid => $n) $add((int) $pid, 'unique_visitors', (int) $n);

        // ── Clicks, carts, wishlist ───────────────────────────────────────────
        $events = DB::table('user_interactions')
            ->whereIn('event_type', ['click', 'cart_add', 'favorite_add'])
            ->where('funnel_excluded', false)->whereNotNull('product_id')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("product_id, event_type, COALESCE(traffic_source, 'direct') as src, COALESCE(device, 'unknown') as dev, COUNT(*) as n")
            ->groupBy('product_id', 'event_type', 'src', 'dev')->get();
        $field = ['click' => 'clicks', 'cart_add' => 'add_to_cart', 'favorite_add' => 'wishlist'];
        foreach ($events as $r) {
            $f = $field[$r->event_type];
            $f === 'wishlist'
                ? $add((int) $r->product_id, $f, (int) $r->n)
                : $add((int) $r->product_id, $f, (int) $r->n, $r->src, $r->dev);
        }

        // ── Impressions, checkout starts ──────────────────────────────────────
        $funnel = DB::table('product_funnel_events')
            ->where('excluded', false)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("product_id, event, traffic_source as src, COALESCE(device, 'unknown') as dev, COUNT(*) as n")
            ->groupBy('product_id', 'event', 'src', 'dev')->get();
        foreach ($funnel as $r) {
            $r->event === 'impression'
                ? $add((int) $r->product_id, 'impressions', (int) $r->n, $r->src, $r->dev)
                : $add((int) $r->product_id, 'checkout_started', (int) $r->n);
        }

        // ── Orders (seller_orders) ────────────────────────────────────────────
        $attrDays = (int) config('funnel.order_attribution_days');
        $orders = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->whereIn('so.status', config('forecast.sale_statuses'))
            ->where('so.created_at', '>=', $from)->where('so.created_at', '<', $to)
            ->whereColumn('o.user_id', '!=', 'so.seller_id')
            ->whereNotNull('oi.product_id')
            ->selectRaw("oi.product_id, so.id as so_id, o.user_id as buyer, so.created_at as at,
                         SUM(oi.quantity) as units, SUM(COALESCE(oi.net_total, oi.total, 0)) as revenue")
            ->groupBy('oi.product_id', 'so.id', 'o.user_id', 'so.created_at')->get();
        foreach ($orders as $r) {
            $view = DB::table('user_interactions')
                ->where('user_id', $r->buyer)->where('product_id', $r->product_id)->where('event_type', 'view')
                ->where('created_at', '<=', $r->at)
                ->where('created_at', '>=', CarbonImmutable::parse($r->at)->subDays($attrDays))
                ->orderByDesc('id')->first(['traffic_source', 'device']);
            $pid = (int) $r->product_id;
            $add($pid, 'orders', 1, $view->traffic_source ?? 'direct', $view->device ?? 'unknown');
            $add($pid, 'units', (int) $r->units);
            $add($pid, 'revenue', (float) $r->revenue, $view->traffic_source ?? 'direct', $view->device ?? 'unknown');
        }

        DB::transaction(function () use ($date, $stats, $traffic) {
            DB::table('product_daily_stats')->where('date', $date)->delete();
            DB::table('product_daily_traffic')->where('date', $date)->delete();
            if (!$stats) return;

            $products = DB::table('products')->whereIn('id', array_keys($stats))
                ->get(['id', 'seller_id', 'category_id', 'subcategory_id'])->keyBy('id');
            $now = now();
            $rows = [];
            foreach ($stats as $pid => $s) {
                $p = $products[$pid] ?? null;
                if (!$p || !$p->seller_id) continue;
                $row = ['product_id' => $pid, 'seller_id' => $p->seller_id, 'category_id' => $p->category_id,
                        'subcategory_id' => $p->subcategory_id, 'date' => $date, 'created_at' => $now, 'updated_at' => $now];
                foreach (['impressions', 'clicks', 'views', 'unique_visitors', 'add_to_cart', 'wishlist', 'checkout_started', 'orders', 'units'] as $f) {
                    $row[$f] = (int) ($s[$f] ?? 0);
                }
                $row['revenue'] = round((float) ($s['revenue'] ?? 0), 3);
                foreach (TrafficSource::all() as $src) $row["views_$src"] = (int) ($s["views_$src"] ?? 0);
                $rows[] = $row;
            }
            foreach (array_chunk($rows, 500) as $chunk) DB::table('product_daily_stats')->insert($chunk);

            $rows = [];
            foreach ($traffic as $k => $t) {
                [$pid, $src, $dev] = explode('|', $k);
                $p = $products[(int) $pid] ?? null;
                if (!$p || !$p->seller_id) continue;
                $rows[] = ['product_id' => (int) $pid, 'seller_id' => $p->seller_id, 'date' => $date,
                           'traffic_source' => $src, 'device' => $dev,
                           'impressions' => (int) ($t['impressions'] ?? 0), 'clicks' => (int) ($t['clicks'] ?? 0),
                           'views' => (int) ($t['views'] ?? 0), 'add_to_cart' => (int) ($t['add_to_cart'] ?? 0),
                           'orders' => (int) ($t['orders'] ?? 0), 'revenue' => round((float) ($t['revenue'] ?? 0), 3)];
            }
            foreach (array_chunk($rows, 500) as $chunk) DB::table('product_daily_traffic')->insert($chunk);
        });

        return count($stats);
    }

    /**
     * History has no source on views: give each one the source of the click that led to it
     * (same actor and product, within click_attribution_minutes before), else "direct";
     * give clicks the source of their section, and carts / wishlist adds the source of the
     * actor's previous view. Only fills NULLs, so it is safe to re-run.
     */
    public function backfillSources(?string $since = null): int
    {
        $n = 0;
        $window = (int) config('funnel.click_attribution_minutes');

        // Clicks: from their section (sections are few — map each distinct one)
        $sections = DB::table('user_interactions')->where('event_type', 'click')->whereNull('traffic_source')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->distinct()->pluck('source_section');
        foreach ($sections as $section) {
            $n += DB::table('user_interactions')->where('event_type', 'click')->whereNull('traffic_source')
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->where(fn ($q) => $section === null ? $q->whereNull('source_section') : $q->where('source_section', $section))
                ->update(['traffic_source' => TrafficSource::fromSection($section)]);
        }

        // Views: the latest preceding click by the same actor on the same product
        $n += $this->fillFromSubquery("
            SELECT v2.id, (
                SELECT c.traffic_source FROM user_interactions c
                WHERE c.event_type = 'click' AND c.product_id = v2.product_id
                  AND c.created_at <= v2.created_at
                  AND c.created_at >= DATE_SUB(v2.created_at, INTERVAL {$window} MINUTE)
                  AND ((v2.session_id IS NOT NULL AND c.session_id = v2.session_id)
                       OR (v2.user_id IS NOT NULL AND c.user_id = v2.user_id))
                ORDER BY c.created_at DESC, c.id DESC LIMIT 1
            ) AS src
            FROM user_interactions v2
            WHERE v2.event_type = 'view' AND v2.traffic_source IS NULL AND v2.product_id IS NOT NULL
              " . ($since ? "AND v2.created_at >= ?" : ''), $since);

        // Carts / wishlist adds: the actor's latest view of the product before them
        $n += $this->fillFromSubquery("
            SELECT e2.id, (
                SELECT v.traffic_source FROM user_interactions v
                WHERE v.event_type = 'view' AND v.product_id = e2.product_id
                  AND v.created_at <= e2.created_at
                  AND ((e2.session_id IS NOT NULL AND v.session_id = e2.session_id)
                       OR (e2.user_id IS NOT NULL AND v.user_id = e2.user_id))
                ORDER BY v.created_at DESC, v.id DESC LIMIT 1
            ) AS src
            FROM user_interactions e2
            WHERE e2.event_type IN ('cart_add', 'favorite_add') AND e2.traffic_source IS NULL AND e2.product_id IS NOT NULL
              " . ($since ? "AND e2.created_at >= ?" : ''), $since);

        return $n;
    }

    /** Run a SELECT of (id, src) and write src (or "direct") back, grouped by value. */
    private function fillFromSubquery(string $select, ?string $since): int
    {
        $bySource = [];
        foreach (DB::select($select, $since ? [$since] : []) as $r) {
            $bySource[$r->src ?: 'direct'][] = (int) $r->id;
        }
        $n = 0;
        foreach ($bySource as $src => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $n += DB::table('user_interactions')->whereIn('id', $chunk)->update(['traffic_source' => $src]);
            }
        }
        return $n;
    }

    /** Drop raw impressions / checkout starts once they are long rolled up. */
    public function pruneRaw(): int
    {
        return DB::table('product_funnel_events')
            ->where('created_at', '<', now()->subDays((int) config('funnel.raw_retention_days')))
            ->delete();
    }
}
