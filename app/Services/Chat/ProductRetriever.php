<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Services\PromotionService;
use App\Services\Search\ProductSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Step 2 of the chatbot: find REAL products for a set of filters.
 *
 * Only approved + active products, in stock (product or at least one active
 * variant), from active seller accounts.
 *
 * Price = what the customer pays:
 *   base  = lowest in-stock active variant price (price_override ?? price),
 *           or products.price when the product has no in-stock variants
 *   final = base priced by PromotionService (same promotion, 30-day reference
 *           and flash quota rules as the storefront and checkout)
 * The best CANDIDATES rows are fetched by relevance, priced in one batch, then
 * min/max filters and price sorting apply to `final`.
 *
 * Relevance from the storefront search (App\Services\Search\ProductSearch: synonyms,
 * typos, multilingual vectors) is added when available; SQL keyword matching always runs.
 */
class ProductRetriever
{
    public const LIMIT = 8;

    /** Rows priced per search before price filters / sorting pick the LIMIT shown. */
    private const CANDIDATES = 300;

    /** Per-request cache so search() and nearest() share one search-engine call. */
    private array $semanticMemo = [];

    /**
     * @return array{products: array, semantic: bool}
     */
    public function search(array $filters, array $excludeIds = []): array
    {
        $keywords = $filters['keywords'] ?? [];
        $semantic = $this->semanticScores($filters['keywords_en'] ?? '' ?: implode(' ', $keywords));

        $query = $this->pricedQuery($keywords, $semantic, $filters['category'] ?? null, $excludeIds);

        if (!$keywords && !$semantic && empty($filters['category'])) {
            // No product words at all (e.g. "something under 30 DT"): popular items in range.
            $query->orderByDesc('x.views');
        }

        if (($filters['sort'] ?? null) === 'rating') {
            $query->orderByDesc('x.rating')->orderByDesc('x.rating_count');
        }
        $query->orderByDesc('x.relevance')->orderByDesc('x.rating')->orderByDesc('x.views');

        $rows = $this->withinPrice(
            $this->price($query->limit(self::CANDIDATES)->get()),
            $filters['min_price'] ?? null,
            $filters['max_price'] ?? null,
        );

        // Stable sorts: equal prices keep their relevance order
        $rows = match ($filters['sort'] ?? 'relevance') {
            'price_asc'  => $rows->sortBy('final_price'),
            'price_desc' => $rows->sortByDesc('final_price'),
            default      => $rows,
        };
        $rows = $rows->take(self::LIMIT)->values();

        return ['products' => $this->format($rows), 'semantic' => (bool) $semantic];
    }

    /**
     * Same search without the price filter — used to tell the user honestly
     * what exists outside their range ("we have 3 from 89 DT").
     *
     * @return array{count: int, min: ?float, max: ?float, category: ?string, category_ar: ?string, category_slug: ?string}
     */
    public function nearest(array $filters): array
    {
        $keywords = $filters['keywords'] ?? [];
        if (!$keywords && empty($filters['category'])) {
            return ['count' => 0, 'min' => null, 'max' => null, 'category' => null, 'category_ar' => null, 'category_slug' => null];
        }

        $semantic = $this->semanticScores($filters['keywords_en'] ?? '' ?: implode(' ', $keywords));
        $rows     = $this->price($this->pricedQuery($keywords, $semantic, $filters['category'] ?? null, [])
            ->limit(50)->get());

        $topSlug = $rows->pluck('category_slug')->filter()->countBy()->sortDesc()->keys()->first();
        $top     = $topSlug ? $rows->firstWhere('category_slug', $topSlug) : null;

        return [
            'count'         => $rows->count(),
            'min'           => $rows->isEmpty() ? null : (float) $rows->min('final_price'),
            'max'           => $rows->isEmpty() ? null : (float) $rows->max('final_price'),
            'category'      => $top->category_name ?? null,
            'category_ar'   => $top->category_name_ar ?? null,
            'category_fr'   => $top->category_name_fr ?? null,
            'category_slug' => $topSlug,
        ];
    }

    // ── Query ─────────────────────────────────────────────────────────────

    private function pricedQuery(array $keywords, array $semantic, ?string $category, array $excludeIds): Builder
    {
        $inner = DB::table('products as p')
            ->join('users as u', 'u.id', '=', 'p.seller_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as sc', 'sc.id', '=', 'p.subcategory_id')
            ->whereNull('p.deleted_at')
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->where('u.is_active', true)
            ->where(function ($q) {
                $q->where('p.stock', '>', 0)->orWhereExists(function ($v) {
                    $v->select(DB::raw(1))->from('product_variants as v')
                        ->whereColumn('v.product_id', 'p.id')
                        ->where('v.is_active', true)
                        ->where('v.stock', '>', 0);
                });
            })
            ->select('p.id', 'p.name', 'p.translations', 'p.slug', 'p.seller_id', 'p.price', 'p.views', 'c.name as category_name', 'c.name_ar as category_name_ar', 'c.name_fr as category_name_fr', 'c.slug as category_slug')
            ->selectRaw('(SELECT MIN(COALESCE(v.price_override, p.price)) FROM product_variants v
                          WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0) AS variant_min')
            ->selectRaw('(SELECT v.id FROM product_variants v
                          WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0
                          ORDER BY COALESCE(v.price_override, p.price), v.id LIMIT 1) AS variant_min_id')
            ->selectRaw('(SELECT COUNT(DISTINCT COALESCE(v.price_override, p.price)) FROM product_variants v
                          WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0) AS variant_prices')
            ->selectRaw("(SELECT AVG(r.rating) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating")
            ->selectRaw("(SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_count");

        if ($category) {
            $inner->where('c.slug', $category);
        }
        if ($excludeIds) {
            $inner->whereNotIn('p.id', $excludeIds);
        }

        // Relevance: keyword hits (name weighs most) + semantic similarity.
        $scoreSql  = [];
        $bindings  = [];
        $likeParts = [];
        foreach ($keywords as $kw) {
            $like = '%' . addcslashes($kw, '%_\\') . '%';
            $scoreSql[] = '(CASE WHEN p.name LIKE ? THEN 3 ELSE 0 END)'
                . ' + (CASE WHEN c.name LIKE ? OR c.name_ar LIKE ? OR sc.name LIKE ? OR sc.name_ar LIKE ? THEN 2 ELSE 0 END)'
                . ' + (CASE WHEN p.short_description LIKE ? OR p.description LIKE ? THEN 1 ELSE 0 END)';
            array_push($bindings, $like, $like, $like, $like, $like, $like, $like);
            $likeParts[] = $like;
        }
        if ($semantic) {
            $cases = [];
            foreach ($semantic as $id => $score) {
                $cases[] = 'WHEN ' . (int) $id . ' THEN ' . round($score * 6, 3);
            }
            $scoreSql[] = '(CASE p.id ' . implode(' ', $cases) . ' ELSE 0 END)';
        }
        $inner->selectRaw('(' . ($scoreSql ? implode(' + ', $scoreSql) : '0') . ') AS relevance', $bindings);

        if ($likeParts || $semantic) {
            $inner->where(function ($q) use ($likeParts, $semantic) {
                foreach ($likeParts as $like) {
                    $q->orWhere('p.name', 'like', $like)
                      ->orWhere('p.short_description', 'like', $like)
                      ->orWhere('p.description', 'like', $like)
                      ->orWhere('p.translations', 'like', $like)
                      ->orWhere('c.name', 'like', $like)
                      ->orWhere('c.name_fr', 'like', $like)
                      ->orWhere('c.name_ar', 'like', $like)
                      ->orWhere('sc.name', 'like', $like)
                      ->orWhere('sc.name_ar', 'like', $like);
                }
                if ($semantic) {
                    $q->orWhereIn('p.id', array_keys($semantic));
                }
            });
        }

        return DB::query()->fromSub($inner, 'x')->select('x.*');
    }

    /** Adds base_price, final_price, old_price and promo fields, via PromotionService. */
    private function price(Collection $rows): Collection
    {
        $promotions = app(PromotionService::class);
        $active     = $promotions->activePromotionsFor($rows->pluck('id')->all());

        return $rows->each(function ($r) use ($promotions, $active) {
            $variantId = $r->variant_min !== null ? (int) $r->variant_min_id : null;
            $base      = (float) ($r->variant_min ?? $r->price);
            $pricing   = $promotions->priceWith($r, $base, $variantId, $active[$r->id] ?? null);

            $r->base_price    = $base;
            $r->final_price   = $pricing['final_price'];
            $r->old_price     = $pricing['promo_type'] ? $pricing['original_price'] : null;
            $r->promo_type    = $pricing['promo_type'];
            $r->promo_ends_at = $pricing['ends_at'];
        });
    }

    private function withinPrice(Collection $rows, ?float $min, ?float $max): Collection
    {
        return $rows->filter(fn ($r) => ($min === null || $r->final_price >= $min)
                                     && ($max === null || $r->final_price <= $max));
    }

    // ── Semantic search (optional) ────────────────────────────────────────

    /**
     * product_id => score from the FastAPI MiniLM/FAISS index, or [] when the
     * service is down / slow. A failure is remembered for 60 s so a dead
     * service doesn't add its timeout to every chat message.
     */
    private function semanticScores(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->semanticMemo[$query] ??= $this->fetchSemantic($query);
    }

    /** Relevance from the storefront search bar (MySQL keyword search, synonyms, typo correction). */
    private function fetchSemantic(string $query): array
    {
        $minScore = (float) config('services.ai.chat_min_score', 0.35);
        $scores = app(ProductSearch::class)->scores(mb_substr($query, 0, 100), 30);

        return array_filter($scores, fn ($score) => $score >= $minScore);
    }

    // ── Output ────────────────────────────────────────────────────────────

    private function format($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->all();

        $images = DB::table('product_images')
            ->whereIn('product_id', $ids)
            ->orderByDesc('is_primary')->orderBy('order')
            ->get(['product_id', 'image_path'])
            ->unique('product_id')
            ->mapWithKeys(fn ($img) => [$img->product_id => Storage::url($img->image_path)]);

        $sellers = User::whereIn('id', $rows->pluck('seller_id')->unique())->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->storefrontBranding()['business_name']]);

        return $rows->map(function ($r) use ($images, $sellers) {
            $final = (float) $r->final_price;
            $r     = \App\Support\Localization::productRow($r, ['name']);

            return [
                'id'           => (int) $r->id,
                'name'         => $r->name,
                'price'        => round($final, 3),
                'old_price'    => $r->old_price !== null && $r->old_price > $final ? round($r->old_price, 3) : null,
                'price_from'   => (int) $r->variant_prices > 1,
                'image'        => $images[$r->id] ?? null,
                'seller'       => $sellers[$r->seller_id] ?? null,
                'rating'       => $r->rating !== null ? round((float) $r->rating, 1) : null,
                'rating_count' => (int) $r->rating_count,
                'flash_sale'   => $r->promo_type === 'flash_sale',
                'flash_ends_at' => $r->promo_type === 'flash_sale' ? $r->promo_ends_at : null,
                'category'     => $r->category_name ? ['name' => $r->category_name, 'name_ar' => $r->category_name_ar, 'name_fr' => $r->category_name_fr, 'slug' => $r->category_slug] : null,
                'url'          => '/products/' . $r->slug,
            ];
        })->values()->all();
    }
}
