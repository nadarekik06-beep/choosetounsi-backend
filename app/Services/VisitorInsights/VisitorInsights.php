<?php

namespace App\Services\VisitorInsights;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Payload of Analyse des visiteurs (GET /api/seller/black/visitor-insights).
 * Where and why buyers drop off — never what was sold (that's Statistiques)
 * and never a content checklist (that's Qualité des fiches, reused as a cause).
 *
 * Reads aggregates only (product_daily_stats / product_daily_traffic /
 * funnel_benchmarks). Periods end yesterday (complete days) and are compared
 * with the same number of days just before.
 */
class VisitorInsights
{
    private const COUNTERS = ['impressions', 'clicks', 'views', 'unique_visitors', 'carts', 'checkouts', 'orders', 'revenue'];

    public function __construct(
        private FunnelBenchmarks $bench,
        private FunnelDiagnosis $diagnosis,
        private ProductFacts $facts,
        private InsightActions $actions,
    ) {}

    public static function forget(int $sellerId): void
    {
        foreach (config('funnel.periods') as $d) Cache::forget("visitor_insights:$sellerId:$d");
    }

    public function get(int $sellerId, int $days): array
    {
        return Cache::remember("visitor_insights:$sellerId:$days", now()->addMinutes(15), fn () => $this->build($sellerId, $days));
    }

    public function build(int $sellerId, int $days, ?CarbonImmutable $today = null): array
    {
        $tz = config('funnel.timezone');
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $end = $today->subDay();
        $start = $end->subDays($days - 1);
        $prevEnd = $start->subDay();
        $prevStart = $prevEnd->subDays($days - 1);

        $cur  = $this->totals($sellerId, $start, $end);
        $prev = $this->totals($sellerId, $prevStart, $prevEnd);
        $tracking = $this->tracking($start);
        $curRates = FunnelDiagnosis::rates($this->asFunnel($cur, $tracking));
        $prevRates = FunnelDiagnosis::rates($this->asFunnel($prev, $tracking));

        $facts = $this->facts->forSeller($sellerId);
        $mainCategory = $this->mainCategory($sellerId, $start, $end, $facts);
        $ownPrev = $prevRates + ['views_per_product' => count($facts) ? $prev['views'] / count($facts) : null];
        $storeBench = fn (string $metric) => $this->bench->resolve($metric, $mainCategory, null, $days, $ownPrev[$metric] ?? null);

        $products = $this->products($sellerId, $start, $end, $prevStart, $prevEnd, $days, $facts, $tracking);

        $problems = array_values(array_filter($products, fn ($p) => $p['status'] === 'problem'));
        usort($problems, fn ($a, $b) => [$b['lost_revenue'] !== null, $b['lost_revenue'] ?? 0, $b['severity'] === 'high']
                                    <=> [$a['lost_revenue'] !== null, $a['lost_revenue'] ?? 0, $a['severity'] === 'high']);
        $stages = [];
        foreach (FunnelDiagnosis::STAGES as $s) {
            $items = array_values(array_filter($problems, fn ($p) => $p['stage'] === $s));
            $stages[] = ['stage' => $s, 'count' => count($items), 'lost_revenue' => round(array_sum(array_map(fn ($p) => $p['lost_revenue'] ?? 0, $items)), 1),
                         'items' => array_slice($items, 0, 8)];
        }

        $state = count($facts) === 0 ? 'no_products' : ($cur['views'] < (int) config('funnel.min_views') ? 'low_data' : 'ok');

        return [
            'period'    => ['days' => $days, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'),
                            'previous_from' => $prevStart->format('Y-m-d'), 'previous_to' => $prevEnd->format('Y-m-d')],
            'state'     => $state,
            'tracking'  => $tracking,
            'kpis'      => $this->kpis($cur, $prev, $curRates, $prevRates, $storeBench, $tracking),
            'funnel'    => $this->funnel($cur, $curRates, $storeBench, $tracking),
            'traffic'   => $this->traffic($sellerId, $start, $end),
            'fix_this_week' => array_slice($problems, 0, 3),
            'stages'    => $stages,
            'summary'   => [
                'products'     => count($products),
                'problems'     => count($problems),
                'ok'           => count(array_filter($products, fn ($p) => $p['status'] === 'ok')),
                'insufficient' => count(array_filter($products, fn ($p) => $p['status'] === 'insufficient')),
                'lost_revenue' => round(array_sum(array_map(fn ($p) => $p['lost_revenue'] ?? 0, $problems)), 1),
            ],
            'actions'   => $this->actions->results($sellerId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ── Store level ──────────────────────────────────────────────────────────

    private function totals(int $sellerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $s = DB::table('product_daily_stats')->where('seller_id', $sellerId)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(views),0) views,
                         COALESCE(SUM(unique_visitors),0) unique_visitors, COALESCE(SUM(add_to_cart),0) carts,
                         COALESCE(SUM(checkout_started),0) checkouts, COALESCE(SUM(orders),0) orders, COALESCE(SUM(revenue),0) revenue')
            ->first();
        $out = [];
        foreach (self::COUNTERS as $k) $out[$k] = $k === 'revenue' ? round((float) $s->$k, 3) : (int) $s->$k;
        return $out;
    }

    private function asFunnel(array $t, array $tracking): array
    {
        return ['impressions' => $tracking['impressions_full'] ? $t['impressions'] : null, 'clicks' => $t['clicks'],
                'views' => $t['views'], 'carts' => $t['carts'],
                'checkouts' => $tracking['checkout_full'] ? $t['checkouts'] : null, 'orders' => $t['orders']];
    }

    /**
     * Impressions and checkout starts are tracked since this feature shipped (history can't
     * be rebuilt): rates that need them are only judged over periods fully covered.
     */
    private function tracking(CarbonImmutable $start): array
    {
        $since = Cache::remember('funnel:tracking-since', now()->addHour(), function () {
            $first = fn (string $col) => DB::table('product_daily_stats')->where($col, '>', 0)->min('date');
            return ['impressions' => $first('impressions'), 'checkout' => $first('checkout_started')];
        });
        return [
            'impressions_since' => $since['impressions'],
            'checkout_since'    => $since['checkout'],
            'impressions_full'  => $since['impressions'] !== null && $since['impressions'] <= $start->format('Y-m-d'),
            'checkout_full'     => $since['checkout'] !== null && $since['checkout'] <= $start->format('Y-m-d'),
        ];
    }

    /** The category most of the shop's visits are in (else most of its products). */
    private function mainCategory(int $sellerId, CarbonImmutable $from, CarbonImmutable $to, array $facts): ?int
    {
        $cat = DB::table('product_daily_stats')->where('seller_id', $sellerId)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->whereNotNull('category_id')
            ->groupBy('category_id')->orderByRaw('SUM(views) DESC')->value('category_id');
        if ($cat) return (int) $cat;
        $counts = array_count_values(array_filter(array_column($facts, 'category_id')));
        arsort($counts);
        return $counts ? (int) array_key_first($counts) : null;
    }

    private function kpis(array $cur, array $prev, array $curRates, array $prevRates, \Closure $bench, array $tracking): array
    {
        $change = fn ($a, $b) => $a === null || $b === null || $b == 0 ? null : round(($a - $b) / $b * 100, 1);
        $rpv = fn ($t) => $t['views'] > 0 ? round($t['revenue'] / $t['views'], 3) : null;
        $kpi = function (string $key, $value, $previous, string $format, ?string $benchMetric = null) use ($change, $bench) {
            $b = $benchMetric && $value !== null ? $bench($benchMetric) : null;
            return ['key' => $key, 'value' => $value, 'previous' => $previous, 'format' => $format,
                    'change_pct' => $change($value, $previous),
                    'bench' => $b ? ['value' => round($b['value'], 4), 'scope' => $b['scope']] : null];
        };
        return [
            // Impressions are only tracked since launch: no number rather than a misleading 0
            $kpi('impressions', $tracking['impressions_full'] || $cur['impressions'] > 0 ? $cur['impressions'] : null, $prev['impressions'] ?: null, 'count'),
            $kpi('ctr', $curRates['ctr'], $prevRates['ctr'], 'rate', 'ctr'),
            $kpi('views', $cur['views'], $prev['views'], 'count'),
            $kpi('unique_visitors', $cur['unique_visitors'], $prev['unique_visitors'], 'count'),
            $kpi('add_to_cart_rate', $curRates['view_to_cart'], $prevRates['view_to_cart'], 'rate', 'view_to_cart'),
            $kpi('cart_to_order', $curRates['cart_to_order'], $prevRates['cart_to_order'], 'rate', 'cart_to_order'),
            $kpi('revenue_per_visit', $rpv($cur), $rpv($prev), 'money'),
        ];
    }

    private function funnel(array $cur, array $rates, \Closure $bench, array $tracking): array
    {
        $steps = [
            ['key' => 'impressions', 'value' => $cur['impressions'], 'tracked' => $tracking['impressions_full'] || $cur['impressions'] > 0],
            ['key' => 'clicks',      'value' => $cur['clicks'],      'tracked' => true],
            ['key' => 'views',       'value' => $cur['views'],       'tracked' => true],
            ['key' => 'carts',       'value' => $cur['carts'],       'tracked' => true],
            ['key' => 'checkouts',   'value' => $cur['checkouts'],   'tracked' => $tracking['checkout_full'] || $cur['checkouts'] > 0],
            ['key' => 'orders',      'value' => $cur['orders'],      'tracked' => true],
        ];
        // Transitions judged against a benchmark; the leak is the one furthest below it
        $judged = [
            ['from' => 'impressions', 'to' => 'clicks', 'rate' => $rates['ctr'], 'metric' => 'ctr'],
            ['from' => 'views', 'to' => 'carts', 'rate' => $rates['view_to_cart'], 'metric' => 'view_to_cart'],
            ['from' => 'carts', 'to' => 'orders', 'rate' => $rates['cart_to_order'], 'metric' => 'cart_to_order'],
        ];
        $transitions = [];
        $leak = null;
        foreach ($judged as $t) {
            $b = $t['rate'] !== null ? $bench($t['metric']) : null;
            $ratio = $b && $b['value'] > 0 ? $t['rate'] / $b['value'] : null;
            $transitions[] = $t + ['bench' => $b ? round($b['value'], 4) : null, 'scope' => $b['scope'] ?? null,
                                   'ratio' => $ratio !== null ? round($ratio, 2) : null];
            if ($ratio !== null && $ratio < 1 && (!$leak || $ratio < $leak['ratio'])) {
                $leak = ['from' => $t['from'], 'to' => $t['to'], 'ratio' => round($ratio, 2), 'scope' => $b['scope'], 'basis' => 'benchmark'];
            }
        }
        // No benchmark below 1: point at the biggest relative drop instead
        if (!$leak) {
            $best = null;
            foreach ([['views', 'carts'], ['carts', 'orders']] as [$a, $z]) {
                if ($cur[$a] < 10) continue;
                $drop = 1 - $cur[$z] / $cur[$a];
                if (!$best || $drop > $best['drop']) $best = ['from' => $a, 'to' => $z, 'drop' => $drop];
            }
            if ($best) $leak = ['from' => $best['from'], 'to' => $best['to'], 'ratio' => null, 'scope' => null, 'basis' => 'drop'];
        }
        return ['steps' => $steps, 'transitions' => $transitions, 'leak' => $leak];
    }

    private function traffic(int $sellerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        $bySource = DB::table('product_daily_traffic')->where('seller_id', $sellerId)->whereBetween('date', $range)
            ->groupBy('traffic_source')
            ->selectRaw('traffic_source as k, SUM(impressions) impressions, SUM(clicks) clicks, SUM(views) views, SUM(add_to_cart) carts, SUM(orders) orders')
            ->get()->keyBy('k');
        $sources = [];
        foreach (TrafficSource::all() as $src) {
            $r = $bySource[$src] ?? null;
            $views = (int) ($r->views ?? 0);
            $orders = (int) ($r->orders ?? 0);
            $sources[] = ['source' => $src, 'impressions' => (int) ($r->impressions ?? 0), 'views' => $views,
                          'carts' => (int) ($r->carts ?? 0), 'orders' => $orders,
                          'conversion' => $views >= 10 ? round($orders / $views, 4) : null];
        }
        $byDevice = DB::table('product_daily_traffic')->where('seller_id', $sellerId)->whereBetween('date', $range)
            ->groupBy('device')->selectRaw('device as k, SUM(views) views, SUM(orders) orders')->get();
        $totalViews = max(1, (int) $byDevice->sum('views'));
        $devices = $byDevice->filter(fn ($d) => $d->views > 0)->map(fn ($d) => [
            'device' => $d->k, 'views' => (int) $d->views, 'orders' => (int) $d->orders,
            'share' => round($d->views / $totalViews, 4),
            'conversion' => $d->views >= 10 ? round($d->orders / $d->views, 4) : null,
        ])->sortByDesc('views')->values()->all();
        return ['sources' => $sources, 'devices' => $devices];
    }

    // ── Products ─────────────────────────────────────────────────────────────

    private function products(int $sellerId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $pFrom, CarbonImmutable $pTo,
                              int $days, array $facts, array $tracking): array
    {
        if (!$facts) return [];
        $sum = fn (CarbonImmutable $a, CarbonImmutable $b) => DB::table('product_daily_stats')->where('seller_id', $sellerId)
            ->whereBetween('date', [$a->format('Y-m-d'), $b->format('Y-m-d')])->groupBy('product_id')
            ->selectRaw('product_id, SUM(impressions) impressions, SUM(clicks) clicks, SUM(views) views, SUM(add_to_cart) carts,
                         SUM(checkout_started) checkouts, SUM(orders) orders')
            ->get()->keyBy('product_id');
        $cur = $sum($from, $to);
        $prev = $sum($pFrom, $pTo);

        // The shop's own previous period: the last-resort benchmark
        $prevTotals = ['impressions' => (int) $prev->sum('impressions') ?: null, 'clicks' => (int) $prev->sum('clicks'),
                       'views' => (int) $prev->sum('views'), 'carts' => (int) $prev->sum('carts'), 'orders' => (int) $prev->sum('orders')];
        $ownPrev = FunnelDiagnosis::rates($prevTotals)
                 + ['views_per_product' => $prevTotals['views'] > 0 ? $prevTotals['views'] / count($facts) : null];

        $out = [];
        foreach ($facts as $pid => $f) {
            $s = $cur[$pid] ?? null;
            $m = [
                'impressions' => $tracking['impressions_full'] ? (int) ($s->impressions ?? 0) : null,
                'clicks'      => (int) ($s->clicks ?? 0),
                'views'       => (int) ($s->views ?? 0),
                'carts'       => (int) ($s->carts ?? 0),
                'checkouts'   => $tracking['checkout_full'] ? (int) ($s->checkouts ?? 0) : null,
                'orders'      => (int) ($s->orders ?? 0),
            ];
            $d = $this->diagnosis->diagnose($m, $f + ['period_days' => $days], $this->bench->forProduct($f['category_id'], $f['subcategory_id'], $days, $ownPrev));
            $out[] = $d + [
                'product' => ['id' => $pid, 'name' => $f['name'], 'slug' => $f['slug'], 'image' => $f['image'], 'price' => $f['price']],
                'views'   => $m['views'],
            ];
        }
        return $out;
    }
}
