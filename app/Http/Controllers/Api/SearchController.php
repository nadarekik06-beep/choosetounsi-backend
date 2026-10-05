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
 *   sections.direct        — products whose name matches the query
 *   sections.same_category — other matches in the categories of the direct hits
 *   sections.related       — the remaining matches (or, with alternatives=true, the closest
 *                            products when nothing really matched)
 * plus did_you_mean (corrected query actually searched) and source ("ai" | "fallback").
 */
class SearchController extends Controller
{
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
     *   "success": true, "source": "ai" | "fallback", "query": "sabbat",
     *   "did_you_mean": "sneakers" | null, "alternatives": false, "count": 12,
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
        ]);

        $query   = trim($validated['query']);
        $limit   = $validated['limit'] ?? 40;
        $filters = array_filter([
            'min_price' => $validated['min_price'] ?? null,
            'max_price' => $validated['max_price'] ?? null,
        ]);

        $result   = $search->search($query, $validated['category_id'] ?? null);
        $products = $this->fetchProductsByIds(array_keys($result['hits']), $filters, $limit);
        $sections = $this->splitIntoSections($products, $result['hits'], $result['alternatives']);

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

    /**
     * @param array $products formatted products, in search order
     * @param array<int, array{name_match: bool}> $hits
     */
    private function splitIntoSections(array $products, array $hits, bool $alternatives): array
    {
        if (!$products || $alternatives) {
            return ['direct' => [], 'same_category' => [], 'related' => array_slice($products, 0, 8)];
        }

        $direct = array_values(array_filter($products, fn ($p) => $hits[$p['id']]['name_match'] ?? false));
        if (!$direct) {
            $direct = array_slice($products, 0, 3);
        }
        $direct = array_slice($direct, 0, 6);

        $directIds  = array_column($direct, 'id');
        $categories = array_flip(array_filter(array_column($direct, 'category_slug')));
        $sameCategory = $related = [];
        foreach ($products as $p) {
            if (in_array($p['id'], $directIds, true)) {
                continue;
            }
            if (isset($categories[$p['category_slug'] ?? ''])) {
                $sameCategory[] = $p;
            } else {
                $related[] = $p;
            }
        }

        return [
            'direct'        => $direct,
            'same_category' => array_slice($sameCategory, 0, 8),
            'related'       => array_slice($related, 0, 8),
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
