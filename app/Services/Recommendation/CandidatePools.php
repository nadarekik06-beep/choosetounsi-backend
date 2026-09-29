<?php

namespace App\Services\Recommendation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide data the feed needs, computed once and cached for
 * feed.pool_cache_minutes. Nothing here depends on the viewer, so one
 * cache entry serves every homepage load.
 */
class CandidatePools
{
    const MAX_CATALOG = 5000;

    /** Weight of each event in the 7-day trending score. */
    const TREND_WEIGHTS = ['view' => 1, 'click' => 2, 'favorite_add' => 3, 'cart_add' => 4, 'purchase' => 5];

    /** One actor can't push a product's trend score above this (anti-spam). */
    const TREND_CAP_PER_ACTOR = 10;

    private ?array $memo = null;

    /**
     * @return array{
     *   products: array<int, object>,   id => {id, category_id, subcategory_id, seller_id, price, views, created_ts}
     *   trend: array<int, float>,       id => 7-day weighted score
     *   popularity: array<int, float>,  id => 0..1
     *   rating: array<int, array>,      id => [avg, count, bayes]
     *   brands: array<int, string>,     id => normalized brand
     *   plans: array<int, string>,      seller_id => black|red|free
     *   categories: array<int, object>, id => {id, name, name_fr, name_ar, slug}
     * }
     */
    public function get(): array
    {
        return $this->memo ??= Cache::remember(
            'reco:pools:v2',
            now()->addMinutes((int) config('recommendations.feed.pool_cache_minutes', 10)),
            fn () => $this->build()
        );
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget('reco:pools:v2');
    }

    /** Filter arbitrary ids (e.g. favourites) down to products that may be shown right now. */
    public function eligibleIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $ok = $this->eligibleQuery()->whereIn('p.id', $ids)->pluck('p.id')->map(fn ($i) => (int) $i)->flip();
        return array_values(array_filter($ids, fn ($id) => isset($ok[(int) $id])));
    }

    private function build(): array
    {
        $products = $this->eligibleQuery()
            ->select('p.id', 'p.category_id', 'p.subcategory_id', 'p.seller_id', 'p.price', 'p.views', 'p.created_at')
            ->orderByDesc('p.views')
            ->orderByDesc('p.id')
            ->limit(self::MAX_CATALOG)
            ->get()
            ->mapWithKeys(fn ($p) => [(int) $p->id => (object) [
                'id'             => (int) $p->id,
                'category_id'    => $p->category_id ? (int) $p->category_id : null,
                'subcategory_id' => $p->subcategory_id ? (int) $p->subcategory_id : null,
                'seller_id'      => $p->seller_id ? (int) $p->seller_id : null,
                'price'          => (float) $p->price,
                'views'          => (int) $p->views,
                'created_ts'     => $p->created_at ? strtotime($p->created_at) : 0,
            ]])
            ->all();

        $ids   = array_keys($products);
        $trend = $this->trendScores();

        // Popularity blends recent momentum with lifetime views (log-scaled, 0..1).
        $raw = [];
        foreach ($products as $id => $p) {
            $raw[$id] = log1p(($trend[$id] ?? 0) + 0.1 * $p->views);
        }
        $max = $raw ? max($raw) : 0;
        $popularity = array_map(fn ($v) => $max > 0 ? round($v / $max, 4) : 0.0, $raw);

        return [
            'products'   => $products,
            'sellers'    => count(array_unique(array_filter(array_column($products, 'seller_id')))),
            'trend'      => array_intersect_key($trend, $products),
            'sales'      => array_intersect_key($this->unitsSold(), $products),
            'reserved'   => $this->reservedByOtherHomeRows(),
            'popularity' => $popularity,
            'rating'     => $this->ratings($ids),
            'brands'     => app(InterestProfileService::class)->brandMap($ids),
            'plans'      => $this->sellerPlans(array_unique(array_filter(array_column($products, 'seller_id')))),
            'categories' => DB::table('categories')->where('is_active', true)
                ->orderBy('order')->get(['id', 'name', 'name_fr', 'name_ar', 'slug'])->keyBy('id')->all(),
            'built_at'   => now()->timestamp,
        ];
    }

    /** Approved, active, not deleted, in stock (product or any active variant), seller account active. */
    private function eligibleQuery()
    {
        return DB::table('products as p')
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->where('p.stock', '>', 0)->orWhereExists(fn ($v) => $v->select(DB::raw(1))
                ->from('product_variants as v')
                ->whereColumn('v.product_id', 'p.id')
                ->where('v.is_active', true)
                ->where('v.stock', '>', 0)))
            ->where(fn ($q) => $q->whereNull('p.seller_id')->orWhereExists(fn ($u) => $u->select(DB::raw(1))
                ->from('users as u')
                ->whereColumn('u.id', 'p.seller_id')
                ->where('u.is_active', true)));
    }

    private function trendScores(): array
    {
        $case = 'CASE event_type';
        foreach (self::TREND_WEIGHTS as $event => $w) {
            $case .= " WHEN '{$event}' THEN {$w}";
        }
        $case .= ' ELSE 0 END';

        $perActor = DB::table('user_interactions')
            ->where('created_at', '>=', now()->subDays((int) config('recommendations.feed.trending_days', 7)))
            ->whereNotNull('product_id')
            ->whereIn('event_type', array_keys(self::TREND_WEIGHTS))
            ->groupBy('product_id', DB::raw('COALESCE(user_id, session_id)'))
            ->select('product_id', DB::raw("LEAST(SUM({$case}), " . self::TREND_CAP_PER_ACTOR . ') as s'));

        return DB::query()->fromSub($perActor, 't')
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(s) as score'))
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->product_id => (float) $r->score])
            ->all();
    }

    /** product_id => units sold in non-cancelled orders over the best-seller window. */
    private function unitsSold(): array
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->whereNotNull('oi.product_id')
            ->where('o.created_at', '>=', now()->subDays((int) config('recommendations.feed.best_seller_days', 90)))
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->where(fn ($q) => $q->whereNull('so.status')->orWhereNotIn('so.status', ['cancelled', 'refunded']))
            ->groupBy('oi.product_id')
            ->get(['oi.product_id', DB::raw('SUM(oi.quantity) as units')])
            ->mapWithKeys(fn ($r) => [(int) $r->product_id => (int) $r->units])
            ->all();
    }

    /**
     * Products the homepage already shows outside the feed — Flash deals (every product in a
     * live flash sale) and the Brand collection (the 8 newest platform products) — mirroring
     * FlashDealsSection / BrandCollectionSection, so the feed doesn't repeat them.
     *
     * @return array<int, string> product_id => row
     */
    private function reservedByOtherHomeRows(): array
    {
        $flash = \App\Models\Promotion::where('status', 'active')
            ->where('type', 'flash_sale')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->with(['products' => fn ($q) => $q->where('is_approved', true)->where('is_active', true)->select('products.id')])
            ->get()
            ->flatMap(fn ($promo) => $promo->products->pluck('id'));

        $brand = \App\Models\Product::availableBrand()->orderByDesc('created_at')->limit(8)->pluck('id');

        return $brand->mapWithKeys(fn ($id) => [(int) $id => 'brand_collection'])
            ->union($flash->mapWithKeys(fn ($id) => [(int) $id => 'flash_deals']))
            ->all();
    }

    private function ratings(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $rows = DB::table('reviews')->where('status', 'approved')->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->get(['product_id', DB::raw('AVG(rating) as avg'), DB::raw('COUNT(*) as n')]);
        if ($rows->isEmpty()) {
            return [];
        }

        // Bayesian average: a single 5★ review doesn't beat forty 4.7★ ones.
        $prior  = 3.0;
        $global = $rows->sum(fn ($r) => $r->avg * $r->n) / max(1, $rows->sum('n'));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->product_id] = [
                'avg'   => round((float) $r->avg, 2),
                'count' => (int) $r->n,
                'bayes' => round(($prior * $global + $r->avg * $r->n) / ($prior + $r->n), 3),
            ];
        }
        return $out;
    }

    private function sellerPlans(array $sellerIds): array
    {
        if (empty($sellerIds)) {
            return [];
        }
        return DB::table('seller_applications')
            ->whereIn('user_id', $sellerIds)
            ->where('status', 'approved')
            ->get(['user_id', DB::raw("COALESCE(`plan`, 'free') as plan")])
            ->mapWithKeys(fn ($r) => [(int) $r->user_id => (string) $r->plan])
            ->all();
    }
}
