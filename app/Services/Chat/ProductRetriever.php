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
 * HARD filters (a product that breaks one is never returned):
 *   sub_ids    real subcategory ids of the detected concept ("shoes" → Sneakers, Formal shoes ...)
 *   category   category slug
 *   gender     women ⇒ women + unisex, men ⇒ men + unisex (NULL gender never matches), kids ⇒ kids
 *   price      on the FINAL price (after the active promotion)
 *   colors / sizes   variant option values (name as a fallback for colors)
 *   brand, only_promo
 * Free-text keywords only RANK products, they never widen a hard filter.
 *
 * Price = what the customer pays:
 *   base  = lowest in-stock active variant price (price_override ?? price),
 *           or products.price when the product has no in-stock variants
 *   final = base priced by PromotionService (same promotion, current-price
 *           and flash quota rules as the storefront and checkout)
 * The best CANDIDATES rows are fetched by relevance, priced in one batch, then
 * min/max filters and price sorting apply to `final`.
 */
class ProductRetriever
{
    /** Products shown to the customer (4–6 is the target). */
    public const LIMIT = 6;

    /** At most this many products of one seller in the results, when possible. */
    private const PER_SELLER = 2;

    /** Rows priced per search before price filters / sorting pick the LIMIT shown. */
    private const CANDIDATES = 300;

    /** Per-request cache so search() and nearest() share one search-engine call. */
    private array $semanticMemo = [];

    public function __construct(private ChatLexicon $lex) {}

    /**
     * @return array{products: array, semantic: bool, total: int, unisex: int}
     */
    public function search(array $filters, array $excludeIds = []): array
    {
        $keywords = $filters['keywords'] ?? [];
        $semantic = $this->semanticScores($filters['keywords_en'] ?? '' ?: implode(' ', $keywords), $filters);

        $query = $this->pricedQuery($keywords, $semantic, $filters, $excludeIds);

        if (!$keywords && !$semantic && empty($filters['category']) && empty($filters['sub_ids'])) {
            // No product words at all (e.g. "something under 30 DT"): popular items in range.
            $query->orderByDesc('x.views');
        }

        $sort = $filters['sort'] ?? 'relevance';
        if ($sort === 'rating') {
            $query->orderByDesc('x.rating')->orderByDesc('x.rating_count');
        } elseif ($sort === 'newest') {
            $query->orderByDesc('x.created_at');
        } elseif ($sort === 'popular') {
            $query->orderByDesc('x.views');
        }
        $query->orderByDesc('x.relevance')->orderByDesc('x.rating')->orderByDesc('x.views')->orderByDesc('x.created_at');

        $rows = $this->withinPrice(
            $this->price($query->limit(self::CANDIDATES)->get()),
            $filters['min_price'] ?? null,
            $filters['max_price'] ?? null,
        );

        if (!empty($filters['only_promo'])) {
            $rows = $rows->filter(fn ($r) => $r->old_price !== null && $r->old_price > $r->final_price);
        }

        // Stable sorts: equal prices keep their relevance order
        $rows = match ($sort) {
            'price_asc'  => $rows->sortBy('final_price'),
            'price_desc' => $rows->sortByDesc('final_price'),
            // relevance → promo → rating → popularity (stable)
            'relevance'  => $rows->sort(fn ($a, $b) => [$b->relevance, (int) ($b->old_price !== null), (float) $b->rating]
                                                   <=> [$a->relevance, (int) ($a->old_price !== null), (float) $a->rating]),
            default      => $rows,
        };

        $total = $rows->count();
        $rows  = $this->diversify($rows->values(), self::LIMIT);

        return [
            'products' => $this->format($rows),
            'semantic' => (bool) $semantic,
            'total'    => $total,
            'unisex'   => $rows->where('gender', 'unisex')->count(),
        ];
    }

    /**
     * Search with honest, ordered relaxation: promo → brand → color → size → price (+30 %).
     * Gender and category (concept) are never relaxed.
     *
     * @return array{products: array, relaxed: string[], filters: array, total: int, unisex: int, semantic: bool}
     */
    public function searchRelaxed(array $filters, array $excludeIds = []): array
    {
        $result  = $this->search($filters, $excludeIds);
        $relaxed = [];

        $steps = [
            'promo' => fn (array $f) => !empty($f['only_promo']) ? array_replace($f, ['only_promo' => false]) : null,
            'brand' => fn (array $f) => !empty($f['brand']) ? array_replace($f, ['brand' => null]) : null,
            'color' => fn (array $f) => !empty($f['colors']) ? array_replace($f, ['colors' => []]) : null,
            'size'  => fn (array $f) => !empty($f['sizes']) ? array_replace($f, ['sizes' => []]) : null,
            'price' => function (array $f) {
                if ((empty($f['max_price']) && empty($f['min_price'])) || !empty($f['refine_price'])) {
                    return null;
                }
                return array_replace($f, [
                    'max_price' => !empty($f['max_price']) ? round($f['max_price'] * 1.3, 3) : null,
                    'min_price' => !empty($f['min_price']) ? round($f['min_price'] * 0.7, 3) : null,
                ]);
            },
        ];

        foreach ($steps as $name => $relax) {
            if ($result['products']) {
                break;
            }
            $next = $relax($filters);
            if ($next === null) {
                continue;
            }
            $filters   = $next;
            $relaxed[] = $name;
            $result    = $this->search($filters, $excludeIds);
        }

        return $result + ['relaxed' => $relaxed, 'filters' => $filters];
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
        if (!$keywords && empty($filters['category']) && empty($filters['sub_ids'])) {
            return ['count' => 0, 'min' => null, 'max' => null, 'category' => null, 'category_ar' => null, 'category_fr' => null, 'category_slug' => null];
        }

        $semantic = $this->semanticScores($filters['keywords_en'] ?? '' ?: implode(' ', $keywords), $filters);
        $rows     = $this->price($this->pricedQuery($keywords, $semantic, $filters, [])
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

    /** At most PER_SELLER per seller while there are other sellers; the rest only fills up. */
    private function diversify(Collection $rows, int $limit): Collection
    {
        $picked = collect();
        $left   = collect();
        $count  = [];
        foreach ($rows as $r) {
            $n = $count[$r->seller_id] ?? 0;
            if ($n < self::PER_SELLER && $picked->count() < $limit) {
                $picked->push($r);
                $count[$r->seller_id] = $n + 1;
            } else {
                $left->push($r);
            }
        }
        // Not enough sellers: fill up with the skipped ones (keeping the ranking)
        foreach ($left as $r) {
            if ($picked->count() >= $limit) {
                break;
            }
            $picked->push($r);
        }

        return $picked->values();
    }

    // ── Query ─────────────────────────────────────────────────────────────

    private function pricedQuery(array $keywords, array $semantic, array $filters, array $excludeIds): Builder
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
            ->select('p.id', 'p.name', 'p.translations', 'p.slug', 'p.seller_id', 'p.price', 'p.views', 'p.gender', 'p.created_at', 'c.name as category_name', 'c.name_ar as category_name_ar', 'c.name_fr as category_name_fr', 'c.slug as category_slug', 'sc.slug as subcategory_slug')
            ->selectRaw('(SELECT MIN(COALESCE(v.price_override, p.price)) FROM product_variants v
                          WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0) AS variant_min')
            ->selectRaw('(SELECT v.id FROM product_variants v
                          WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0
                          ORDER BY COALESCE(v.price_override, p.price), v.id LIMIT 1) AS variant_min_id')
            ->selectRaw('(SELECT COUNT(DISTINCT COALESCE(v.price_override, p.price)) FROM product_variants v
                          WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0) AS variant_prices')
            ->selectRaw("(SELECT AVG(r.rating) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating")
            ->selectRaw("(SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_count");

        if (!empty($filters['category'])) {
            $inner->where('c.slug', $filters['category']);
        }
        if ($excludeIds) {
            $inner->whereNotIn('p.id', $excludeIds);
        }

        // ── Hard filters ────────────────────────────────────────────────
        $hardText = false;
        if (!empty($filters['sub_ids'])) {
            $inner->whereIn('p.subcategory_id', array_map('intval', $filters['sub_ids']));
        } elseif (!empty($filters['concept_text'])) {
            // The concept has no real subcategory: its own words must be in the product name
            $hardText = true;
            $inner->where(function ($q) use ($filters) {
                foreach ($filters['concept_text'] as $word) {
                    $like = '%' . addcslashes($word, '%_\\') . '%';
                    $q->orWhere('p.name', 'like', $like)->orWhere('p.translations', 'like', $like);
                }
            });
        }

        $this->genderFilter($inner, $filters['gender'] ?? null, !empty($filters['gender_strict']));
        $this->colorFilter($inner, $filters['colors'] ?? []);
        $this->sizeFilter($inner, $filters['sizes'] ?? []);
        if (!empty($filters['brand'])) {
            $inner->whereExists(function ($q) use ($filters) {
                $q->select(DB::raw(1))->from('product_attribute_values as pav')
                    ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
                    ->whereColumn('pav.product_id', 'p.id')
                    ->where('a.slug', 'brand')
                    ->where('pav.value', 'like', '%' . addcslashes($filters['brand'], '%_\\') . '%');
            });
        }

        // ── Relevance: keyword hits (name weighs most) + semantic similarity ──
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

        // Words only widen the search when no hard product filter exists
        $hardProduct = !empty($filters['sub_ids']) || $hardText;
        if (!$hardProduct && ($likeParts || $semantic)) {
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

    /**
     * women ⇒ women + unisex, men ⇒ men + unisex. A product with no gender never matches.
     * kids ⇒ gender kids, or any product of the Kids & Baby category.
     * Applied only when `gender_strict` is set (the concept or the search is gendered).
     */
    private function genderFilter(Builder $q, ?string $gender, bool $strict): void
    {
        if (!$gender || !$strict) {
            return;
        }
        if ($gender === 'kids') {
            $q->where(fn ($w) => $w->where('p.gender', 'kids')->orWhere('c.slug', 'kids-baby'));
            return;
        }
        if (in_array($gender, ['women', 'men'], true)) {
            $q->whereIn('p.gender', [$gender, 'unisex']);
        }
    }

    private function colorFilter(Builder $q, array $colors): void
    {
        if (!$colors) {
            return;
        }
        $words = [];
        foreach ($colors as $color) {
            foreach ($this->lex->config("colors.{$color}.db", []) as $w) {
                $words[] = $w;
            }
        }
        if (!$words) {
            return;
        }
        $q->where(function ($w) use ($words) {
            $w->whereExists(function ($e) use ($words) {
                $e->select(DB::raw(1))->from('product_variants as v')
                    ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'v.id')
                    ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
                    ->join('attributes as a', 'a.id', '=', 'ao.attribute_id')
                    ->whereColumn('v.product_id', 'p.id')
                    ->where('v.is_active', true)->where('v.stock', '>', 0)
                    ->where('a.slug', 'color')
                    ->where(function ($l) use ($words) {
                        foreach ($words as $word) {
                            $like = '%' . addcslashes($word, '%_\\') . '%';
                            $l->orWhere('ao.value', 'like', $like)->orWhere('ao.value_ar', 'like', $like);
                        }
                    });
            });
            foreach ($words as $word) {
                $w->orWhere('p.name', 'like', '%' . addcslashes($word, '%_\\') . '%');
            }
        });
    }

    private function sizeFilter(Builder $q, array $sizes): void
    {
        if (!$sizes) {
            return;
        }
        $q->whereExists(function ($e) use ($sizes) {
            $e->select(DB::raw(1))->from('product_variants as v')
                ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'v.id')
                ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
                ->join('attributes as a', 'a.id', '=', 'ao.attribute_id')
                ->whereColumn('v.product_id', 'p.id')
                ->where('v.is_active', true)->where('v.stock', '>', 0)
                ->whereIn('a.slug', ['size', 'shoe-size'])
                ->whereIn('ao.value', $sizes);
        });
    }

    /** Adds base_price, final_price, old_price and promo fields, via PromotionService. */
    private function price(Collection $rows): Collection
    {
        $promotions = app(PromotionService::class);
        $active     = $promotions->activePromotionsFor($rows->pluck('id')->all());

        return $rows->each(function ($r) use ($promotions, $active) {
            $variantId = $r->variant_min !== null ? (int) $r->variant_min_id : null;
            $base      = (float) ($r->variant_min ?? $r->price);
            // The chatbot quotes the prices the client sees (delivery included)
            $pricing   = app(\App\Services\Pricing\PricingService::class)->priceWith($r, $base, $variantId, $active[$r->id] ?? null);
            $base      = $pricing['price'];

            $r->base_price    = $base;
            $r->final_price   = $pricing['final_price'];
            $r->old_price     = $pricing['promo_type'] ? $pricing['original_price'] : null;
            $r->promo_type    = $pricing['promo_type'];
            $r->promo_ends_at = $pricing['ends_at'];
            $r->discount_percent = (int) $pricing['discount_percent'];
        });
    }

    private function withinPrice(Collection $rows, ?float $min, ?float $max): Collection
    {
        return $rows->filter(fn ($r) => ($min === null || $r->final_price >= $min)
                                     && ($max === null || $r->final_price <= $max));
    }

    // ── Semantic search (optional) ────────────────────────────────────────

    /**
     * product_id => score from the storefront search, used only to RANK inside
     * the filtered set (never to widen it). Skipped when a hard product filter
     * exists — the filters already decide what can be shown.
     */
    private function semanticScores(string $query, array $filters = []): array
    {
        $query = trim($query);
        if ($query === '' || !empty($filters['sub_ids'])) {
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
            $final  = (float) $r->final_price;
            $gender = $r->gender;
            $r      = \App\Support\Localization::productRow($r, ['name']);

            return [
                'id'           => (int) $r->id,
                'name'         => $r->name,
                'price'        => round($final, 3),
                'old_price'    => $r->old_price !== null && $r->old_price > $final ? round($r->old_price, 3) : null,
                // Same "-X%" as every other screen (PromotionService::percentOff)
                'discount_percent' => $r->old_price !== null && $r->old_price > $final ? (int) ($r->discount_percent ?? 0) : 0,
                'price_from'   => (int) $r->variant_prices > 1,
                'image'        => $images[$r->id] ?? null,
                'seller'       => $sellers[$r->seller_id] ?? null,
                'rating'       => $r->rating !== null ? round((float) $r->rating, 1) : null,
                'rating_count' => (int) $r->rating_count,
                'flash_sale'   => $r->promo_type === 'flash_sale',
                'flash_ends_at' => $r->promo_type === 'flash_sale' ? $r->promo_ends_at : null,
                'gender'       => $gender,
                'subcategory'  => $r->subcategory_slug ?? null,
                'category'     => $r->category_name ? ['name' => $r->category_name, 'name_ar' => $r->category_name_ar, 'name_fr' => $r->category_name_fr, 'slug' => $r->category_slug] : null,
                'url'          => '/products/' . $r->slug,
            ];
        })->values()->all();
    }
}
