<?php

namespace App\Services\GrowthRadar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cross-seller aggregates (category price ranges, conversion rates, traffic
 * pattern). Each one is returned only above the privacy floor in
 * config('growth.privacy'): at least `min_sellers` shops AND, for rates,
 * at least `min_events` events. Below it the method returns null and the
 * card falls back to the seller's own data or is not shown. Never a shop
 * name, never one shop's numbers.
 *
 * Memoized for one run (one instance per growth:compute).
 */
class Benchmarks
{
    private array $memo = [];

    public function __construct(private CarbonImmutable $today) {}

    public static function floor(string $key): int
    {
        return (int) config("growth.privacy.$key");
    }

    /**
     * Price range of live products in the subcategory (or, if too thin, the category).
     * @return array{scope: string, p25: float, median: float, p75: float, products: int, sellers: int}|null
     */
    public function priceRange(int $categoryId, ?int $subcategoryId): ?array
    {
        return $this->memo["price:$categoryId:$subcategoryId"] ??= (function () use ($categoryId, $subcategoryId) {
            $scopes = $subcategoryId ? [['subcategory', 'subcategory_id', $subcategoryId], ['category', 'category_id', $categoryId]]
                                     : [['category', 'category_id', $categoryId]];
            foreach ($scopes as [$scope, $col, $id]) {
                $rows = DB::table('products')
                    ->where($col, $id)->whereNull('deleted_at')->where('is_active', true)->where('is_approved', true)
                    ->where(fn ($q) => $q->whereNull('is_pack')->orWhere('is_pack', false))
                    ->where('price', '>', 0)
                    ->get(['seller_id', 'price']);
                if ($rows->count() < self::floor('min_products') || $rows->pluck('seller_id')->unique()->count() < self::floor('min_sellers')) {
                    continue;
                }
                $prices = $rows->pluck('price')->map(fn ($p) => (float) $p)->sort()->values()->all();
                return [
                    'scope'    => $scope,
                    'p25'      => round(self::quantile($prices, 0.25), 1),
                    'median'   => round(self::quantile($prices, 0.5), 1),
                    'p75'      => round(self::quantile($prices, 0.75), 1),
                    'products' => count($prices),
                    'sellers'  => $rows->pluck('seller_id')->unique()->count(),
                ];
            }
            return null;
        })();
    }

    /**
     * Orders per view and add-to-carts per view over the window, for a category
     * (null = whole platform).
     * @return array{scope: string, rate: float, cart_rate: float, views: int, sellers: int}|null
     */
    public function conversion(?int $categoryId): ?array
    {
        return $this->memo["conv:$categoryId"] ??= (function () use ($categoryId) {
            $since = $this->today->subDays((int) config('growth.window_days'))->utc();
            $events = DB::table('user_interactions')
                ->whereIn('event_type', ['view', 'cart_add'])
                ->where('created_at', '>=', $since)->where('created_at', '<', $this->today->utc())
                ->whereNotNull('seller_id')
                ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                ->selectRaw("SUM(event_type = 'view') as views, SUM(event_type = 'cart_add') as carts,
                             COUNT(DISTINCT CASE WHEN event_type = 'view' THEN seller_id END) as sellers")
                ->first();
            $views = (int) ($events->views ?? 0);
            if ($views < self::floor('min_events') || (int) $events->sellers < self::floor('min_sellers')) return null;

            // Orders of products that were actually seen in the window (orders placed without
            // tracked views — imports, old data — would inflate the rate).
            $seen = DB::table('user_interactions')->select('product_id')->where('event_type', 'view')
                ->where('created_at', '>=', $since)->where('created_at', '<', $this->today->utc())->whereNotNull('product_id');
            $orders = (int) DB::table('order_items as oi')
                ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->whereIn('so.status', config('forecast.sale_statuses'))
                ->where('so.created_at', '>=', $since)->where('so.created_at', '<', $this->today->utc())
                ->whereIn('oi.product_id', $seen)
                ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
                ->selectRaw('COUNT(DISTINCT so.id) as n')->value('n');

            return [
                'scope'     => $categoryId ? 'category' : 'platform',
                'rate'      => min($orders / $views, (float) config('growth.max_benchmark_rate')),
                'cart_rate' => (int) $events->carts / $views,
                'views'     => $views,
                'sellers'   => (int) $events->sellers,
            ];
        })();
    }

    /** Category conversion when it passes the floor, else the platform's, else null. */
    public function conversionFor(int $categoryId): ?array
    {
        return $this->conversion($categoryId) ?? $this->conversion(null);
    }

    /**
     * Median views per live product over the window, across shops.
     * @return array{median: float, sellers: int}|null
     */
    public function viewsPerProduct(): ?array
    {
        return $this->memo['vpp'] ??= (function () {
            $since = $this->today->subDays((int) config('growth.window_days'))->utc();
            $rows = DB::table('products as p')
                ->leftJoin('user_interactions as i', function ($j) use ($since) {
                    $j->on('i.product_id', '=', 'p.id')->where('i.event_type', 'view')
                      ->where('i.created_at', '>=', $since)->where('i.created_at', '<', $this->today->utc());
                })
                ->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.is_approved', true)->whereNotNull('p.seller_id')
                ->groupBy('p.id', 'p.seller_id')
                ->selectRaw('p.seller_id, COUNT(i.id) as views')
                ->get();
            $sellers = $rows->pluck('seller_id')->unique()->count();
            if ($sellers < self::floor('min_sellers') || $rows->sum('views') < self::floor('min_events')) return null;
            $v = $rows->pluck('views')->map(fn ($x) => (float) $x)->sort()->values()->all();
            return ['median' => self::quantile($v, 0.5), 'sellers' => $sellers];
        })();
    }

    /**
     * Weekly net revenue per live product in a category (seasonal impact for shops
     * without sales of their own).
     */
    public function weeklyRevenuePerProduct(int $categoryId): ?float
    {
        return $this->memo["wrpp:$categoryId"] ??= (function () use ($categoryId) {
            $days = 8 * 7;
            $since = $this->today->subDays($days)->utc();
            $sales = DB::table('order_items as oi')
                ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->whereIn('so.status', config('forecast.sale_statuses'))
                ->where('so.created_at', '>=', $since)->where('p.category_id', $categoryId)
                ->selectRaw('SUM(COALESCE(oi.net_total, oi.total, 0)) as revenue, COUNT(DISTINCT so.id) as orders, COUNT(DISTINCT so.seller_id) as sellers')
                ->first();
            if ((int) $sales->orders < self::floor('min_events') || (int) $sales->sellers < self::floor('min_sellers')) return null;
            $products = DB::table('products')->where('category_id', $categoryId)->whereNull('deleted_at')
                ->where('is_active', true)->where('is_approved', true)->count();
            return $products > 0 ? (float) $sales->revenue / $products / ($days / 7) : null;
        })();
    }

    /**
     * When buyers are on the site: share of views + purchases by weekday and hour
     * (local time) over the last weeks, across all shops.
     * @return array{day: int, hour: int, lift: float, days: float[], hours: float[], events: int}|null
     *         day: 1 = Monday … 7 = Sunday
     */
    public function traffic(): ?array
    {
        return $this->memo['traffic'] ??= (function () {
            $weeks = (int) config('growth.timing.weeks');
            $offset = $this->today->utcOffset();
            $rows = DB::table('user_interactions')
                ->whereIn('event_type', ['view', 'purchase'])
                ->where('created_at', '>=', $this->today->subWeeks($weeks)->utc())
                ->where('created_at', '<', $this->today->utc())
                ->selectRaw("WEEKDAY(DATE_ADD(created_at, INTERVAL {$offset} MINUTE)) as wd,
                             HOUR(DATE_ADD(created_at, INTERVAL {$offset} MINUTE)) as hr,
                             SUM(CASE WHEN event_type = 'purchase' THEN 5 ELSE 1 END) as w,
                             COUNT(*) as n")
                ->groupBy('wd', 'hr')->get();
            $events = (int) $rows->sum('n');
            $sellers = (int) DB::table('user_interactions')->whereIn('event_type', ['view', 'purchase'])
                ->where('created_at', '>=', $this->today->subWeeks($weeks)->utc())
                ->distinct()->count('seller_id');
            if ($events < (int) config('growth.timing.min_events') || $sellers < self::floor('min_sellers')) return null;

            $days = array_fill(0, 7, 0.0);
            $hours = array_fill(0, 24, 0.0);
            $total = (float) $rows->sum('w');
            foreach ($rows as $r) {
                $days[(int) $r->wd]  += $r->w / $total;
                $hours[(int) $r->hr] += $r->w / $total;
            }
            $bestDay  = array_keys($days, max($days))[0];
            // Best 3-hour evening/day block, starting one hour before its peak hour
            $bestHour = array_keys($hours, max($hours))[0];
            return [
                'day'    => $bestDay + 1,
                'hour'   => max(0, $bestHour - 1),
                'lift'   => round($days[$bestDay] * 7 * $hours[$bestHour] * 24, 2),
                'day_lift'  => round($days[$bestDay] * 7, 2),
                'hour_lift' => round($hours[$bestHour] * 24, 2),
                'days'   => array_map(fn ($x) => round($x, 4), $days),
                'hours'  => array_map(fn ($x) => round($x, 4), $hours),
                'events' => $events,
            ];
        })();
    }

    /** Next start at the platform's best weekday/hour, at least $minDays from today (local). */
    public function bestStart(int $minDays = 0): CarbonImmutable
    {
        $t = $this->traffic();
        $start = $this->today->addDays($minDays);
        if (!$t) return $start->setTime(9, 0);
        while ((int) $start->format('N') !== $t['day']) $start = $start->addDay();
        return $start->setTime($t['hour'], 0);
    }

    /** Linear-interpolated quantile of a sorted list. */
    public static function quantile(array $sorted, float $q): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        $pos = ($n - 1) * $q;
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);
        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
    }
}
