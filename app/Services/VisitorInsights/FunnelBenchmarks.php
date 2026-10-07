<?php

namespace App\Services\VisitorInsights;

use App\Services\GrowthRadar\Benchmarks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Category benchmarks from product_daily_stats: medians across live products
 * (each product counts once, only with a large enough sample for that rate).
 *
 * Privacy: same floor as Growth Radar (config('growth.privacy')) — a scope is
 * published only with ≥ min_sellers shops and ≥ min_events views. Below it
 * there is no row, and lookups fall back:
 *   subcategory → category → platform → the seller's own previous period.
 * Every resolved value says which one it came from (`scope`).
 */
class FunnelBenchmarks
{
    public const METRICS = ['ctr', 'view_to_cart', 'cart_to_order', 'conversion', 'views_per_product',
                            'impressions_per_product', 'median_price', 'median_delivery_fee'];

    /** @var array<string, ?object> */
    private array $rows = [];

    /** Rebuild every scope for each window, ending on $end (inclusive). @return int rows written */
    public function build(CarbonImmutable $end): int
    {
        $written = 0;
        foreach (config('funnel.periods') as $days) {
            $from = $end->subDays($days - 1)->format('Y-m-d');
            $sums = DB::table('product_daily_stats')
                ->where('date', '>=', $from)->where('date', '<=', $end->format('Y-m-d'))
                ->groupBy('product_id')
                ->selectRaw('product_id, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(views) as views,
                             SUM(add_to_cart) as carts, SUM(orders) as orders')
                ->get()->keyBy('product_id');

            // Every live product counts (zero traffic included, for "views per product")
            $products = DB::table('products')
                ->whereNull('deleted_at')->where('is_active', true)->where('is_approved', true)->whereNotNull('seller_id')
                ->where(fn ($q) => $q->whereNull('is_pack')->orWhere('is_pack', false))
                ->where('created_at', '<=', $end->endOfDay()->utc())
                ->get(['id', 'seller_id', 'category_id', 'subcategory_id', 'price', 'delivery_fee'])
                ->map(function ($p) use ($sums) {
                    $s = $sums[$p->id] ?? null;
                    foreach (['impressions', 'clicks', 'views', 'carts', 'orders'] as $f) $p->$f = (int) ($s->$f ?? 0);
                    return $p;
                });

            $groups = ['platform' => [0 => $products]];
            foreach ($products->groupBy('category_id') as $id => $g) if ($id) $groups['category'][$id] = $g;
            foreach ($products->groupBy('subcategory_id') as $id => $g) if ($id) $groups['subcategory'][$id] = $g;

            $keep = [];
            foreach ($groups as $scope => $byId) {
                foreach ($byId as $id => $g) {
                    $row = $this->summarize($g);
                    if (!$row) continue;
                    DB::table('funnel_benchmarks')->updateOrInsert(
                        ['scope' => $scope, 'scope_id' => (int) $id, 'window_days' => $days],
                        $row + ['end_date' => $end->format('Y-m-d'), 'updated_at' => now(), 'created_at' => now()]
                    );
                    $keep[] = "$scope:$id";
                    $written++;
                }
            }
            // Scopes that dropped below the floor must not keep an old number
            DB::table('funnel_benchmarks')->where('window_days', $days)->get(['id', 'scope', 'scope_id'])
                ->reject(fn ($r) => in_array("$r->scope:$r->scope_id", $keep, true))
                ->each(fn ($r) => DB::table('funnel_benchmarks')->where('id', $r->id)->delete());
        }
        $this->rows = [];
        return $written;
    }

    private function summarize(Collection $g): ?array
    {
        $sellers = $g->pluck('seller_id')->unique()->count();
        $views = (int) $g->sum('views');
        if ($sellers < Benchmarks::floor('min_sellers') || $views < Benchmarks::floor('min_events')) return null;

        $median = function (Collection $values, int $min = 3): ?float {
            $v = $values->filter(fn ($x) => $x !== null)->map(fn ($x) => (float) $x)->sort()->values()->all();
            return count($v) >= $min ? round(Benchmarks::quantile($v, 0.5), 6) : null;
        };
        $minV = (int) config('funnel.bench_min_views');
        $minI = (int) config('funnel.bench_min_impressions');
        $minC = (int) config('funnel.bench_min_carts');

        return [
            'products'                => $g->count(),
            'sellers'                 => $sellers,
            'views'                   => $views,
            'ctr'                     => $median($g->filter(fn ($p) => $p->impressions >= $minI)->map(fn ($p) => min(1, $p->clicks / $p->impressions))),
            'view_to_cart'            => $median($g->filter(fn ($p) => $p->views >= $minV)->map(fn ($p) => min(1, $p->carts / $p->views))),
            'cart_to_order'           => $median($g->filter(fn ($p) => $p->carts >= $minC)->map(fn ($p) => min(1, $p->orders / $p->carts))),
            'conversion'              => $median($g->filter(fn ($p) => $p->views >= $minV)->map(fn ($p) => min(1, $p->orders / $p->views))),
            'views_per_product'       => $median($g->map(fn ($p) => $p->views), 5),
            'impressions_per_product' => $median($g->map(fn ($p) => $p->impressions), 5),
            'median_price'            => $median($g->map(fn ($p) => (float) $p->price > 0 ? $p->price : null)),
            'median_delivery_fee'     => $median($g->map(fn ($p) => $p->delivery_fee)),
        ];
    }

    // ── Lookups ─────────────────────────────────────────────────────────────

    private function row(string $scope, int $id, int $days): ?object
    {
        $k = "$scope:$id:$days";
        if (!array_key_exists($k, $this->rows)) {
            $this->rows[$k] = DB::table('funnel_benchmarks')
                ->where('scope', $scope)->where('scope_id', $id)->where('window_days', $days)->first();
        }
        return $this->rows[$k];
    }

    /**
     * One metric for a product's (sub)category, with the fallback chain.
     * $own = the seller's previous-period value of the same metric (last resort).
     * @return array{value: float, scope: string}|null
     */
    public function resolve(string $metric, ?int $categoryId, ?int $subcategoryId, int $days, ?float $own = null): ?array
    {
        $chain = [];
        if ($subcategoryId) $chain[] = ['subcategory', $subcategoryId];
        if ($categoryId)    $chain[] = ['category', $categoryId];
        $chain[] = ['platform', 0];
        foreach ($chain as [$scope, $id]) {
            $v = $this->row($scope, $id, $days)?->$metric;
            if ($v !== null) return ['value' => (float) $v, 'scope' => $scope];
        }
        return $own !== null ? ['value' => $own, 'scope' => 'own_previous'] : null;
    }

    /** Resolver bound to one product, for FunnelDiagnosis. */
    public function forProduct(?int $categoryId, ?int $subcategoryId, int $days, array $ownPrevious = []): \Closure
    {
        return fn (string $metric) => $this->resolve($metric, $categoryId, $subcategoryId, $days, $ownPrevious[$metric] ?? null);
    }
}
