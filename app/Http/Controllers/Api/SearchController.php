<?php
// app/Http/Controllers/Api/SearchController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * SearchController
 *
 * Returns results in 3 sections for text search:
 *
 *   sections.direct        — products whose name/slug directly matches the query (top AI hits)
 *   sections.same_category — other products in the same category as the direct hits
 *   sections.related       — remaining AI results from other categories
 *
 * The frontend renders each section with its own header and divider.
 *
 * Also handles:
 *   - Fuzzy typo correction (via Python AI service Levenshtein corrector)
 *   - did_you_mean field returned to frontend for the yellow banner
 *   - Autocomplete suggestions endpoint
 *   - Image search (unchanged, flat response)
 */
class SearchController extends Controller
{
    private string $aiServiceUrl;
    private int    $textTimeout    = 10;
    private int    $imageTimeout   = 15;
    private int    $suggestTimeout = 3;

    public function __construct()
    {
        $this->aiServiceUrl = config('services.ai.url', 'http://localhost:8001');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTOCOMPLETE SUGGESTIONS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/search/suggestions?q=bask&limit=8
     */
    public function suggestions(Request $request)
    {
        $q     = trim($request->input('q', ''));
        $limit = (int) $request->input('limit', 8);

        if (strlen($q) < 2) {
            return response()->json(['suggestions' => [], 'query' => $q]);
        }

        $cacheKey = 'search_suggest_' . md5($q . '_' . $limit);

        $suggestions = Cache::remember($cacheKey, 600, function () use ($q, $limit) {
            try {
                $res = Http::timeout($this->suggestTimeout)
                    ->get("{$this->aiServiceUrl}/search/suggest", ['q' => $q, 'limit' => $limit]);
                if ($res->successful()) {
                    return $res->json()['suggestions'] ?? [];
                }
            } catch (\Exception $e) {
                Log::debug("Suggest unavailable: " . $e->getMessage());
            }
            return $this->fallbackSuggestions($q, $limit);
        });

        return response()->json(['suggestions' => $suggestions, 'query' => $q]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEXT SEARCH
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/search/text
     *
     * Response shape:
     * {
     *   "success":      true,
     *   "source":       "ai" | "fallback",
     *   "query":        "bascetball",
     *   "did_you_mean": "basketball" | null,
     *   "count":        12,
     *   "sections": {
     *     "direct":        [...],   // ← exact/top matches
     *     "same_category": [...],   // ← other products in same category
     *     "related":       [...]    // ← other AI results
     *   }
     * }
     */
    public function searchText(Request $request)
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
            'category_id' => $validated['category_id'] ?? null,
            'min_price'   => $validated['min_price']   ?? null,
            'max_price'   => $validated['max_price']   ?? null,
        ]);

        // ── Try AI service first ──────────────────────────────────────────
        try {
            $aiResponse = Http::timeout($this->textTimeout)
                ->post("{$this->aiServiceUrl}/search/text", [
                    'query' => $query,
                    'limit' => 60, // Fetch extra, we'll section them
                ]);

            if ($aiResponse->successful()) {
                $aiData      = $aiResponse->json();
                $aiResults   = $aiData['results']          ?? [];
                $didYouMean  = $aiData['corrected_query']  ?? null;

                if (!empty($aiResults)) {
                    $productIds = array_column($aiResults, 'product_id');
                    $products   = $this->fetchProductsByIds($productIds, $filters, $limit);

                    // ── Build 3 sections ─────────────────────────────────
                    $sections = $this->splitIntoSections($products, $query);

                    $total = count($sections['direct'])
                           + count($sections['same_category'])
                           + count($sections['related']);

                    return response()->json([
                        'success'      => true,
                        'source'       => 'ai',
                        'query'        => $query,
                        'did_you_mean' => $didYouMean,
                        'count'        => $total,
                        'sections'     => $sections,
                    ]);
                }

                // AI returned no results
                return response()->json([
                    'success'      => true,
                    'source'       => 'ai',
                    'query'        => $query,
                    'did_you_mean' => null,
                    'count'        => 0,
                    'sections'     => ['direct' => [], 'same_category' => [], 'related' => []],
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("AI service unavailable for text search: " . $e->getMessage());
        }

        // ── Fallback: MySQL LIKE + PHP fuzzy correction ───────────────────
        $phpCorrected = $this->phpFuzzyCorrect($query);
        $searchQuery  = $phpCorrected ?? $query;
        $didYouMean   = ($phpCorrected && $phpCorrected !== $query) ? $phpCorrected : null;

        $products = $this->fallbackTextSearch($searchQuery, $filters, $limit);

        if (empty($products) && $didYouMean) {
            $products   = $this->fallbackTextSearch($query, $filters, $limit);
            $didYouMean = null;
        }

        $sections = $this->splitIntoSections($products, $searchQuery);
        $total    = count($sections['direct']) + count($sections['same_category']) + count($sections['related']);

        return response()->json([
            'success'      => true,
            'source'       => 'fallback',
            'query'        => $query,
            'did_you_mean' => $didYouMean,
            'count'        => $total,
            'sections'     => $sections,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IMAGE SEARCH (unchanged)
    // ─────────────────────────────────────────────────────────────────────────

    public function searchImage(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,jpg,png,webp|max:10240',
            'limit' => 'sometimes|integer|min:1|max:30',
        ]);

        $limit = $request->input('limit', 20);

        try {
            $imageFile = $request->file('image');

            $aiResponse = Http::timeout($this->imageTimeout)
                ->attach('file', file_get_contents($imageFile->getRealPath()), $imageFile->getClientOriginalName())
                ->post("{$this->aiServiceUrl}/search/image");

            if ($aiResponse->successful()) {
                $aiData    = $aiResponse->json();
                $aiResults = $aiData['results'] ?? [];

                if (!empty($aiResults)) {
                    $productIds = array_column($aiResults, 'product_id');
                    $products   = $this->fetchProductsByIds($productIds, [], $limit);
                    return response()->json([
                        'success'  => true, 'source' => 'ai',
                        'query'    => '[image search]',
                        'count'    => count($products),
                        'products' => $products,
                    ]);
                }

                return response()->json([
                    'success' => true, 'source' => 'ai', 'query' => '[image search]',
                    'count' => 0, 'products' => [],
                    'message' => 'No similar products found for this image.',
                ]);
            }

            throw new \Exception("AI service returned: " . $aiResponse->status());

        } catch (\Exception $e) {
            Log::error("Image search failed: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Image search is temporarily unavailable.'], 503);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION BUILDER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Split a flat product list into 3 semantic sections.
     *
     * direct:        Products whose name closely matches the query string
     *                (name LIKE "%query%" or high position in AI ranking = first 4)
     *
     * same_category: Products in the same primary category as the direct hits,
     *                but not themselves direct hits
     *
     * related:       Everything else — different category, still AI-relevant
     *
     * Why this approach:
     *   - The AI returns products ranked by semantic similarity, not by category.
     *   - "bascetball" → basketball is #1, hoodies are #2 because CLIP embeds
     *     "sports equipment" as nearby. We want to show basketball first,
     *     then separate the hoodies clearly as "also in sports" or "related".
     */
    private function splitIntoSections(array $products, string $query): array
    {
        if (empty($products)) {
            return ['direct' => [], 'same_category' => [], 'related' => []];
        }

        $queryLower = strtolower(trim($query));
        $queryTerms = array_filter(explode(' ', $queryLower), fn($t) => strlen($t) >= 2);

        $direct       = [];
        $sameCategory = [];
        $related      = [];

        // ── Pass 1: identify direct hits ─────────────────────────────────
        // A product is "direct" if:
        //   (a) Its name contains the query or a query term, OR
        //   (b) It's in the top 2 AI results AND its category matches the first result's category
        $directCategoryIds = [];

        foreach ($products as $i => $product) {
            $nameLower  = strtolower($product['name'] ?? '');
            $nameMatch  = str_contains($nameLower, $queryLower);

            // Also check individual terms for multi-word queries
            if (!$nameMatch) {
                foreach ($queryTerms as $term) {
                    if (str_contains($nameLower, $term)) { $nameMatch = true; break; }
                }
            }

            // Top 2 AI results are always "direct" (they are the closest semantic matches)
            $isTopResult = $i < 2;

            if ($nameMatch || $isTopResult) {
                $direct[] = $product;
                if (!empty($product['category_slug'])) {
                    $directCategoryIds[$product['category_slug']] = true;
                }
            }
        }

        // If no direct hits found (very low name match), treat top 3 AI results as direct
        if (empty($direct) && !empty($products)) {
            $topCount = min(3, count($products));
            for ($i = 0; $i < $topCount; $i++) {
                $direct[] = $products[$i];
                if (!empty($products[$i]['category_slug'])) {
                    $directCategoryIds[$products[$i]['category_slug']] = true;
                }
            }
        }

        $directIds = array_column($direct, 'id');

        // ── Pass 2: split remaining into same-category vs related ─────────
        foreach ($products as $product) {
            if (in_array($product['id'], $directIds)) continue; // already in direct

            $catSlug = $product['category_slug'] ?? '';

            if ($catSlug && isset($directCategoryIds[$catSlug])) {
                $sameCategory[] = $product;
            } else {
                $related[] = $product;
            }
        }

        // Cap section sizes to keep the page clean
        return [
            'direct'        => array_slice($direct, 0, 6),
            'same_category' => array_slice($sameCategory, 0, 8),
            'related'       => array_slice($related, 0, 8),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function phpFuzzyCorrect(string $query): ?string
    {
        $words = explode(' ', strtolower(trim($query)));
        if (count($words) > 4) return null;

        $vocab = Cache::remember('search_php_vocab', 3600, function () {
            return DB::table('products')
                ->where('is_approved', 1)->where('is_active', 1)->whereNull('deleted_at')
                ->pluck('name')
                ->flatMap(fn($name) => explode(' ', strtolower($name)))
                ->filter(fn($w) => strlen($w) >= 3)
                ->unique()->values()->toArray();
        });

        if (empty($vocab)) return null;

        $correctedWords = [];
        $anyCorrected   = false;

        foreach ($words as $word) {
            if (strlen($word) < 4) { $correctedWords[] = $word; continue; }
            $bestMatch = $word; $bestScore = 0;
            foreach ($vocab as $vocabWord) {
                if (abs(strlen($vocabWord) - strlen($word)) > 3) continue;
                similar_text($word, $vocabWord, $pct);
                if ($pct > $bestScore && $pct >= 75 && $vocabWord !== $word) {
                    $bestScore = $pct; $bestMatch = $vocabWord;
                }
            }
            if ($bestMatch !== $word) $anyCorrected = true;
            $correctedWords[] = $bestMatch;
        }

        return $anyCorrected ? implode(' ', $correctedWords) : null;
    }

    private function fallbackSuggestions(string $q, int $limit): array
    {
        return DB::table('products')
            ->where('is_approved', 1)->where('is_active', 1)->whereNull('deleted_at')
            ->where('name', 'LIKE', $q . '%')
            ->orderByDesc('views')->limit($limit)
            ->pluck('name')
            ->map(fn($n) => strtolower($n))->unique()->values()->toArray();
    }

    private function fetchProductsByIds(array $productIds, array $filters, int $limit): array
    {
        if (empty($productIds)) return [];

        $query = DB::table('products as p')
            ->select([
                'p.id', 'p.name', 'p.slug', 'p.description',
                'p.price', 'p.stock', 'p.views', 'p.featured',
                'c.name as category_name',      'c.slug as category_slug',
                'sub.name as subcategory_name', 'sub.slug as subcategory_slug',
                'pi.image_path as primary_image',
            ])
            ->leftJoin('categories as c',     'c.id',   '=', 'p.category_id')
            ->leftJoin('subcategories as sub', 'sub.id', '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', '=', 1);
            })
            ->whereIn('p.id', $productIds)
            ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at');

        if (!empty($filters['category_id'])) $query->where('p.category_id', $filters['category_id']);
        if (!empty($filters['min_price']))   $query->where('p.price', '>=', $filters['min_price']);
        if (!empty($filters['max_price']))   $query->where('p.price', '<=', $filters['max_price']);

        $products = $query->get();
        if ($products->isEmpty()) return [];

        // Preserve AI ranking order
        $indexed = $products->keyBy('id');
        $ordered = [];
        foreach ($productIds as $id) {
            if ($indexed->has($id)) {
                $ordered[] = $this->formatProduct($indexed->get($id));
            }
        }

        // Featured boost within AI order
        usort($ordered, function ($a, $b) use ($productIds) {
            $posA = array_search($a['id'], $productIds) - ($a['featured'] ? 3 : 0);
            $posB = array_search($b['id'], $productIds) - ($b['featured'] ? 3 : 0);
            return $posA - $posB;
        });

        return array_slice($ordered, 0, $limit);
    }

    private function formatProduct(object $product): array
    {
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
        ];
    }

    private function fallbackTextSearch(string $query, array $filters, int $limit): array
    {
        $terms = array_filter(explode(' ', $query), fn($t) => strlen($t) >= 2);

        $dbQuery = DB::table('products as p')
            ->select([
                'p.id', 'p.name', 'p.slug', 'p.description',
                'p.price', 'p.stock', 'p.views', 'p.featured',
                'c.name as category_name',      'c.slug as category_slug',
                'sub.name as subcategory_name', 'sub.slug as subcategory_slug',
                'pi.image_path as primary_image',
                DB::raw("(
                    CASE WHEN p.name        LIKE ? THEN 60 ELSE 0 END +
                    CASE WHEN p.description LIKE ? THEN 20 ELSE 0 END +
                    CASE WHEN p.name        LIKE ? THEN 30 ELSE 0 END +
                    (LEAST(p.views, 500) / 500 * 15) +
                    CASE WHEN p.featured = 1 THEN 10 ELSE 0 END
                ) as relevance_score"),
            ])
            ->addBinding(["%{$query}%", "%{$query}%", "%" . implode("%", $terms) . "%"], 'select')
            ->leftJoin('categories as c',      'c.id',   '=', 'p.category_id')
            ->leftJoin('subcategories as sub', 'sub.id', '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', '=', 1);
            })
            ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->where(function ($q) use ($terms, $query) {
                $q->where('p.name', 'LIKE', "%{$query}%")->orWhere('p.description', 'LIKE', "%{$query}%");
                foreach ($terms as $term) $q->orWhere('p.name', 'LIKE', "%{$term}%");
            });

        if (!empty($filters['category_id'])) $dbQuery->where('p.category_id', $filters['category_id']);
        if (!empty($filters['min_price']))   $dbQuery->where('p.price', '>=', $filters['min_price']);
        if (!empty($filters['max_price']))   $dbQuery->where('p.price', '<=', $filters['max_price']);

        return $dbQuery->orderByDesc('relevance_score')->orderByDesc('p.featured')->orderByDesc('p.views')
            ->limit($limit)->get()->map(fn($p) => $this->formatProduct($p))->toArray();
    }
}