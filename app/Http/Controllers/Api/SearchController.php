<?php
// app/Http/Controllers/Api/SearchController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PromotionService;
use App\Services\Recommendation\InteractionTracker;
use App\Services\Search\ImageSearch;
use App\Services\Search\ProductSearch;
use App\Services\Search\SearchUnavailable;
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
 *   sections.related       — the remaining weak matches (description only, some words only), or,
 *                            with alternatives=true, the closest products by meaning when no word matched
 * plus did_you_mean (the corrected query that was searched instead; send exact=true to search
 * the original as typed) and source ("keyword" | "semantic").
 */
class SearchController extends Controller
{
    const DIRECT_MAX = 24;
    const SECTION_MAX = 8;

    /** GET /api/search/suggestions?q=bask&limit=8 */
    public function suggestions(Request $request, Suggestions $suggestions)
    {
        $q     = trim((string) $request->input('q', ''));
        $limit = max(1, min(20, (int) $request->input('limit', 8)));

        if (mb_strlen($q) < 2) {
            return response()->json(['suggestions' => [], 'query' => $q]);
        }

        $key = 'search_suggest:' . Localization::locale() . ':' . md5(mb_strtolower($q) . "_$limit");
        $list = Cache::remember($key, 300, fn () => $suggestions->for($q, $limit));

        return response()->json(['suggestions' => $list, 'query' => $q]);
    }

    /**
     * POST /api/search/text
     *
     * {
     *   "success": true, "source": "keyword" | "semantic", "query": "ensembel",
     *   "did_you_mean": "ensemble" | null, "alternatives": false, "count": 12,
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
            'source'       => $result['source'],
            'query'        => $query,
            'did_you_mean' => $result['did_you_mean'],
            'alternatives' => $result['alternatives'],
            'count'        => array_sum(array_map('count', $sections)),
            'sections'     => $sections,
        ]);
    }

    /** POST /api/search/image (multipart "image") */
    public function searchImage(Request $request, ImageSearch $search)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,jpg,png,webp|max:10240',
            'limit' => 'sometimes|integer|min:1|max:30',
        ]);

        try {
            $scores = $search->search(file_get_contents($request->file('image')->getRealPath()), (int) $request->input('limit', 20));
        } catch (SearchUnavailable $e) {
            Log::warning('[Search] Image search unavailable: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => __('messages.search.image_unavailable')], 503);
        }

        $products = $this->fetchProductsByIds(array_keys($scores), [], count($scores) ?: 1);
        return response()->json(array_filter([
            'success'  => true,
            'source'   => 'ai',
            'query'    => '[image search]',
            'count'    => count($products),
            'products' => $products,
            'message'  => $products ? null : __('messages.search.no_similar'),
        ], fn ($v) => $v !== null));
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
            $relatedIds = array_merge($weak, array_keys($result['semantic']));
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
}
