<?php
// app/Http/Controllers/Api/SearchController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PromotionService;
use App\Services\Recommendation\InteractionTracker;
use App\Services\Search\FingerprintClient;
use App\Services\Search\FingerprintIndex;
use App\Services\Search\ImageSearch;
use App\Services\Search\ProductSearch;
use App\Services\Search\SearchUnavailable;
use App\Services\Search\Shops;
use App\Services\Search\Suggestions;
use App\Support\Localization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Storefront search API (the search logic lives in App\Services\Search).
 *
 * Text search returns three sections:
 *   sections.direct        — real matches only (query found in the name, category or brand/attributes)
 *   sections.same_category — weaker matches in the categories of the direct hits, then other
 *                            products of the top hit's subcategory
 *   sections.related       — the remaining weak matches (description only, some words only)
 * plus did_you_mean (the corrected query that was searched instead; send exact=true to search
 * the original as typed).
 */
class SearchController extends Controller
{
    const DIRECT_MAX = 24;
    const SECTION_MAX = 8;

    /** GET /api/search/suggestions?q=bask&limit=8 — product names + shops whose name matches ("shops"). */
    public function suggestions(Request $request, Suggestions $suggestions, Shops $shops)
    {
        $q     = trim((string) $request->input('q', ''));
        $limit = max(1, min(20, (int) $request->input('limit', 8)));

        if (mb_strlen($q) < 2) {
            return response()->json(['suggestions' => [], 'query' => $q]);
        }

        $key = 'search_suggest:' . Localization::locale() . ':' . md5(mb_strtolower($q) . "_$limit");
        $list = Cache::remember($key, 300, fn () => $suggestions->for($q, $limit));
        $shops = Cache::remember('search_suggest_shops:' . md5(mb_strtolower($q)), 300, fn () => $this->shopCards($shops->match($q)));

        return response()->json(['suggestions' => $list, 'shops' => $shops, 'query' => $q]);
    }

    /**
     * POST /api/search/text
     *
     * {
     *   "success": true, "source": "keyword", "query": "ensembel",
     *   "did_you_mean": "ensemble" | null, "count": 12,
     *   "shops": [{ "id": 25, "name": "Dar El Moda", "avatar": "…", "products_count": 20 }],
     *   "sections": { "direct": [...], "same_category": [...], "related": [...] }
     * }
     */
    public function searchText(Request $request, ProductSearch $search)
    {
        $validated = $request->validate([
            'query'       => 'required|string|min:1|max:500',
            'limit'       => 'sometimes|integer|min:1|max:50',
            'category_id' => 'sometimes|integer|exists:categories,id',
            'min_price'   => 'sometimes|numeric|min:0',
            'max_price'   => 'sometimes|numeric|min:0',
            'exact'       => 'sometimes|boolean',
        ]);

        $query   = trim($validated['query']);
        $limit   = $validated['limit'] ?? 40;
        $filters = array_filter([
            'min_price' => $validated['min_price'] ?? null,
            'max_price' => $validated['max_price'] ?? null,
        ]);

        $result   = $search->search($query, $validated['category_id'] ?? null, true, !($validated['exact'] ?? false));
        $sections = $this->sections($result, $filters, $limit);

        $this->trackSearch($request, $query, $sections);
        return response()->json([
            'success'      => true,
            'source'       => 'keyword',
            'query'        => $query,
            'did_you_mean' => $result['did_you_mean'],
            'shops'        => $this->shopCards($result['shops']),
            'count'        => array_sum(array_map('count', $sections)),
            'sections'     => $sections,
        ]);
    }

    /**
     * POST /api/search/image (multipart "image": the cropped photo, ~512 px; throttled)
     *
     * {
     *   "success": true, "source": "ai", "search_id": 42, "count": 9, "fallback": false,
     *   "predicted_category": {"id": 2, "type": "subcategory", "name": "Robe", "slug": "dress", "category": {...}, "confident": true} | null,
     *   "sections": { "exact": [cards], "similar": [cards] }
     * }
     * Each card also has match ("exact" | "similar"), similarity, and, when the best photo is a
     * color photo, matched_color_id + image_url (that photo). fallback=true: nothing passed the
     * threshold, "similar" holds the closest products of the predicted category.
     * 503 {"success": false, "code": "unavailable"} when the AI service is down or slow.
     */
    public function searchImage(Request $request, ImageSearch $search)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        $start = microtime(true);
        $bytes = file_get_contents($request->file('image')->getRealPath());
        try {
            $result = $search->search($bytes);
        } catch (SearchUnavailable $e) {
            Log::warning('[ImageSearch] Unavailable: ' . $e->getMessage());
            $rejected = in_array($e->status, [413, 422], true);
            return response()->json([
                'success' => false,
                'code'    => $rejected ? 'unreadable' : 'unavailable',
                'message' => __($rejected ? 'messages.search.no_similar' : 'messages.search.image_unavailable'),
            ], $rejected ? 422 : 503);
        }

        $hits   = $result['exact'] + $result['similar'];
        $cards  = collect($this->fetchProductsByIds(array_keys($hits), [], count($hits) ?: 1))->keyBy('id');
        $photos = DB::table('product_images')->whereIn('id', array_column($hits, 'image_id'))->pluck('image_path', 'id');

        $section = function (array $ids, string $match) use ($cards, $hits, $photos) {
            $out = [];
            foreach ($ids as $id) {
                if (!$card = $cards->get($id)) {
                    continue;
                }
                $hit = $hits[$id];
                $card['match'] = $match;
                $card['similarity'] = $hit['similarity'];
                // Best photo is a color photo: show that color and preselect it on the product page.
                if ($hit['color_option_id'] && isset($photos[$hit['image_id']])) {
                    $card['matched_color_id'] = $hit['color_option_id'];
                    $card['image_url'] = config('app.url') . '/storage/' . $photos[$hit['image_id']];
                }
                $out[] = $card;
            }
            return $out;
        };
        $sections = [
            'exact'   => $section(array_keys($result['exact']), 'exact'),
            'similar' => $section(array_keys($result['similar']), 'similar'),
        ];

        $searchId = $this->logImageSearch($request, $bytes, $result, $sections, $start);
        return response()->json(array_filter([
            'success'            => true,
            'source'             => 'ai',
            'search_id'          => $searchId,
            'count'              => count($sections['exact']) + count($sections['similar']),
            'fallback'           => $result['fallback'],
            'predicted_category' => $this->predictedCategory($result['prediction']),
            'sections'           => $sections,
            'message'            => $sections['exact'] || $sections['similar'] ? null : __('messages.search.no_similar'),
        ], fn ($v) => $v !== null));
    }

    /** GET /api/search/image/status — camera button: is photo search usable right now? */
    public function imageStatus(FingerprintClient $client)
    {
        $available = $client->health() !== null && FingerprintIndex::hasPhotos();
        return response()->json(['available' => $available]);
    }

    /** POST /api/search/image/click {search_id, product_id, rank} — which result was opened (first click only). */
    public function imageClick(Request $request)
    {
        $data = $request->validate([
            'search_id'  => 'required|integer|min:1',
            'product_id' => 'required|integer|min:1',
            'rank'       => 'sometimes|integer|min:1|max:100',
        ]);
        DB::table('image_search_logs')->where('id', $data['search_id'])->whereNull('clicked_at')
            ->where('created_at', '>=', now()->subDay())
            ->update(['clicked_product_id' => $data['product_id'], 'clicked_rank' => $data['rank'] ?? null, 'clicked_at' => now()]);
        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Search result → the three sections, as product cards (one query for all of them). */
    private function sections(array $result, array $filters, int $limit): array
    {
        $direct = array_slice(array_keys($result['direct']), 0, min(self::DIRECT_MAX, $limit));
        $weak   = array_keys($result['weak']);

        // Weak matches in the categories of the best results stay next to them; the rest is "related".
        $sameIds = $relatedIds = [];
        if ($direct) {
            $rows = DB::table('products')->whereIn('id', array_merge($direct, $weak))->get(['id', 'category_id', 'subcategory_id'])->keyBy('id');
            $categories = $rows->only($direct)->pluck('category_id')->filter()->unique()->all();
            foreach ($weak as $id) {
                if (in_array($rows[$id]->category_id ?? null, $categories, true)) {
                    $sameIds[] = $id;
                } else {
                    $relatedIds[] = $id;
                }
            }
            // Few of them: more products from the top hit's subcategory.
            $sub = $rows[$direct[0]]->subcategory_id ?? null;
            if ($sub && count($sameIds) < self::SECTION_MAX) {
                $sameIds = array_merge($sameIds, DB::table('products')
                    ->where('subcategory_id', $sub)->whereNotIn('id', array_merge($direct, $sameIds))
                    ->where('is_approved', 1)->where('is_active', 1)->whereNull('deleted_at')
                    ->orderByDesc('views')->limit(self::SECTION_MAX - count($sameIds))->pluck('id')->all());
            }
        } else {
            $relatedIds = $weak;
        }
        $sameIds    = array_slice($sameIds, 0, self::SECTION_MAX);
        $relatedIds = array_slice($relatedIds, 0, self::SECTION_MAX);

        $cards = collect($this->fetchProductsByIds(array_merge($direct, $sameIds, $relatedIds), $filters, 100))->keyBy('id');
        $pick  = fn (array $ids) => array_values(array_filter(array_map(fn ($id) => $cards->get($id), $ids)));

        return [
            'direct'        => $pick($direct),
            'same_category' => $pick($sameIds),
            'related'       => $pick($relatedIds),
        ];
    }

    /** Live product cards for the given ids, in that order (price filters on the price paid). */
    private function fetchProductsByIds(array $productIds, array $filters, int $limit): array
    {
        if (empty($productIds)) {
            return [];
        }

        $products = DB::table('products as p')
            ->select([
                'p.id', 'p.name', 'p.slug', 'p.description', 'p.translations',
                'p.price', 'p.stock', 'p.views', 'p.featured',
                'c.name as category_name',      'c.slug as category_slug',
                'c.name_fr as category_name_fr', 'c.name_ar as category_name_ar',
                'sub.name as subcategory_name', 'sub.slug as subcategory_slug',
                'sub.name_fr as subcategory_name_fr', 'sub.name_ar as subcategory_name_ar',
                'pi.image_path as primary_image',
            ])
            ->leftJoin('categories as c',     'c.id',   '=', 'p.category_id')
            ->leftJoin('subcategories as sub', 'sub.id', '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', '=', 1);
            })
            ->whereIn('p.id', $productIds)
            ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->get();
        if ($products->isEmpty()) {
            return [];
        }

        $pricing = app(PromotionService::class)->priceMany($products);
        $indexed = $products->filter(fn ($p) => $this->withinPrice($pricing[$p->id], $filters))->keyBy('id');

        $ordered = [];
        foreach ($productIds as $id) {
            if ($indexed->has($id)) {
                $ordered[] = $this->formatProduct($indexed->get($id), $pricing[$id]);
            }
        }
        return \App\Services\ProductCardImages::attach(array_slice($ordered, 0, $limit));
    }

    /** Search card; $pricing is the PromotionService block (final price, promo badge…). */
    private function formatProduct(object $product, array $pricing): array
    {
        $product->category_name    = Localization::column($product, 'category_name');
        $product->subcategory_name = Localization::column($product, 'subcategory_name');
        $product = Localization::productRow($product, ['name', 'description']);

        return [
            'id'               => $product->id,
            'name'             => $product->name,
            'slug'             => $product->slug,
            'description'      => $product->description,
            'price'            => (float) $product->price,
            'stock'            => (int)   $product->stock,
            'views'            => (int)   ($product->views ?? 0),
            'featured'         => (bool)  ($product->featured ?? false),
            'category_name'    => $product->category_name,
            'category_slug'    => $product->category_slug,
            'subcategory_name' => $product->subcategory_name,
            'subcategory_slug' => $product->subcategory_slug,
            'primary_image'    => $product->primary_image
                ? config('app.url') . '/storage/' . $product->primary_image
                : null,
        ] + $pricing;
    }

    /** min/max price filters compare the price the customer pays (after promotion). */
    private function withinPrice(array $pricing, array $filters): bool
    {
        $price = $pricing['final_price'];
        if (!empty($filters['min_price']) && $price < (float) $filters['min_price']) return false;
        if (!empty($filters['max_price']) && $price > (float) $filters['max_price']) return false;
        return true;
    }

    /** Personalization signal: remember what was searched and which category it pointed at. */
    private function trackSearch(Request $request, string $query, array $sections): void
    {
        $top = $sections['direct'][0] ?? $sections['same_category'][0] ?? null;
        $slug = $top['category_slug'] ?? null;
        $categoryId = $slug
            ? Cache::remember("category_id_by_slug:{$slug}", 3600, fn () => DB::table('categories')->where('slug', $slug)->value('id'))
            : null;

        app(InteractionTracker::class)->recordFromRequest($request, 'search', null, [
            'search_query' => $query,
            'category_id'  => $categoryId ? (int) $categoryId : null,
        ]);
    }

    /** Shop cards for the search bar: [{id, name, avatar, products_count}] (link: /sellers/{id}). */
    private function shopCards(array $shops): array
    {
        return array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'avatar' => $s['avatar'],
                                     'products_count' => $s['products']], $shops);
    }

    /** The detected category as the storefront shows it (localized), null when there is none. */
    private function predictedCategory(?array $prediction): ?array
    {
        if (!$prediction || !$prediction['category_id']) {
            return null;
        }
        $category = DB::table('categories')->where('id', $prediction['category_id'])->first(['id', 'name', 'name_fr', 'name_ar', 'slug']);
        if (!$category) {
            return null;
        }
        $sub = $prediction['subcategory_id']
            ? DB::table('subcategories')->where('id', $prediction['subcategory_id'])->first(['id', 'name', 'name_fr', 'name_ar', 'slug'])
            : null;
        $main = $sub ?? $category;
        return [
            'id'        => (int) $main->id,
            'type'      => $sub ? 'subcategory' : 'category',
            'name'      => Localization::column($main, 'name'),
            'slug'      => $main->slug,
            'category'  => ['id' => (int) $category->id, 'name' => Localization::column($category, 'name'), 'slug' => $category->slug],
            'confident' => (bool) $prediction['confident'],
        ];
    }

    /** One row per photo search (image_search_logs), to tune the thresholds later. */
    private function logImageSearch(Request $request, string $bytes, array $result, array $sections, float $start): ?int
    {
        try {
            $p = $result['prediction'];
            return DB::table('image_search_logs')->insertGetId([
                'user_id'                  => optional($request->user('sanctum'))->id,
                'image_hash'               => sha1($bytes),
                'predicted_category_id'    => $p['category_id'] ?? null,
                'predicted_subcategory_id' => $p['subcategory_id'] ?? null,
                'category_confidence'      => $p['margin'] ?? null,
                'category_confident'       => (bool) ($p['confident'] ?? false),
                'query_color'              => $result['color'],
                'top_scores'               => json_encode($result['top']),
                'exact_count'              => count($sections['exact']),
                'similar_count'            => count($sections['similar']),
                'fallback'                 => $result['fallback'],
                'cached'                   => $result['cached'],
                'duration_ms'              => (int) round((microtime(true) - $start) * 1000),
                'created_at'               => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ImageSearch] Not logged: ' . $e->getMessage());
            return null;
        }
    }
}
