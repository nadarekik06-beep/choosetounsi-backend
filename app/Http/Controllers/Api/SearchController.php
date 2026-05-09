<?php
// app/Http/Controllers/Api/SearchController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SearchController
 *
 * Handles AI-powered semantic search and image similarity search.
 *
 * Flow:
 *   1. Validate input from Next.js frontend
 *   2. Forward to Python AI service (localhost:8001)
 *   3. Receive product IDs + scores from Python
 *   4. Fetch full product data from MySQL
 *   5. Apply business filters (price, category, stock)
 *   6. Preserve AI ranking order in the final response
 *   7. Return enriched JSON to frontend
 *
 * Fallback: if Python service is unreachable, falls back to MySQL LIKE search.
 */
class SearchController extends Controller
{
    /**
     * The base URL of the Python AI service.
     * Configured via AI_SERVICE_URL in .env
     */
    private string $aiServiceUrl;

    /**
     * HTTP timeout in seconds for AI service calls.
     * Text search: fast (~100ms), set low timeout.
     * Image search: slightly slower (~200ms), allow more.
     */
    private int $textTimeout  = 10;
    private int $imageTimeout = 15;

    public function __construct()
    {
        $this->aiServiceUrl = config('services.ai.url', 'http://localhost:8001');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEXT SEARCH
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/search/text
     *
     * Request body:
     *   {
     *     "query":        "chaussures sport rouge",   // required
     *     "limit":        20,                          // optional, default 20
     *     "category_id":  1,                           // optional filter
     *     "min_price":    0,                           // optional filter
     *     "max_price":    500,                         // optional filter
     *   }
     *
     * Response:
     *   {
     *     "success": true,
     *     "source":  "ai",                 // "ai" or "fallback"
     *     "query":   "chaussures sport rouge",
     *     "count":   15,
     *     "products": [ { product data ... }, ... ]
     *   }
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

        $query     = trim($validated['query']);
        $limit     = $validated['limit'] ?? 20;
        $filters   = array_filter([
            'category_id' => $validated['category_id'] ?? null,
            'min_price'   => $validated['min_price']   ?? null,
            'max_price'   => $validated['max_price']   ?? null,
        ]);

        // ── Try AI service first ──────────────────────────────────────────
        try {
            $aiResponse = Http::timeout($this->textTimeout)
                ->post("{$this->aiServiceUrl}/search/text", [
                    'query' => $query,
                    'limit' => 50, // Fetch more from AI, filter down in MySQL
                ]);

            if ($aiResponse->successful()) {
                $aiData    = $aiResponse->json();
                $aiResults = $aiData['results'] ?? [];

                if (!empty($aiResults)) {
                    // Extract product IDs in AI-ranked order
                    $productIds = array_column($aiResults, 'product_id');
                    $scoreMap   = array_column($aiResults, 'score', 'product_id');

                    $products = $this->fetchProductsByIds($productIds, $filters, $limit);

                    return response()->json([
                        'success'  => true,
                        'source'   => 'ai',
                        'query'    => $query,
                        'count'    => count($products),
                        'products' => $products,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning("AI service unavailable for text search: " . $e->getMessage());
        }

        // ── Fallback: MySQL LIKE search ───────────────────────────────────
        $products = $this->fallbackTextSearch($query, $filters, $limit);

        return response()->json([
            'success'  => true,
            'source'   => 'fallback',
            'query'    => $query,
            'count'    => count($products),
            'products' => $products,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IMAGE SEARCH
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/search/image
     *
     * Request: multipart/form-data
     *   image: <file>         // required, JPEG/PNG/WebP
     *   limit: 20             // optional
     *
     * Response: same structure as searchText, source will be "ai" or error
     */
    public function searchImage(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,jpg,png,webp|max:10240', // max 10MB
            'limit' => 'sometimes|integer|min:1|max:30',
        ]);

        $limit = $request->input('limit', 20);

        try {
            $imageFile = $request->file('image');

            $aiResponse = Http::timeout($this->imageTimeout)
                ->attach(
                    'file',
                    file_get_contents($imageFile->getRealPath()),
                    $imageFile->getClientOriginalName()
                )
                ->post("{$this->aiServiceUrl}/search/image");

            if ($aiResponse->successful()) {
                $aiData    = $aiResponse->json();
                $aiResults = $aiData['results'] ?? [];

                if (!empty($aiResults)) {
                    $productIds = array_column($aiResults, 'product_id');
                    $products   = $this->fetchProductsByIds($productIds, [], $limit);

                    return response()->json([
                        'success'  => true,
                        'source'   => 'ai',
                        'query'    => '[image search]',
                        'count'    => count($products),
                        'products' => $products,
                    ]);
                }

                return response()->json([
                    'success'  => true,
                    'source'   => 'ai',
                    'query'    => '[image search]',
                    'count'    => 0,
                    'products' => [],
                    'message'  => 'No similar products found for this image.',
                ]);
            }

            throw new \Exception("AI service returned: " . $aiResponse->status());

        } catch (\Exception $e) {
            Log::error("Image search failed: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Image search is temporarily unavailable. Please try text search.',
            ], 503);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch full product data for a list of product IDs.
     * Preserves the AI ranking order.
     * Applies optional business filters.
     * Featured products get a slight positional boost within AI ranking.
     *
     * @param  array $productIds  Ordered list of product IDs from AI service
     * @param  array $filters     Optional: category_id, min_price, max_price
     * @param  int   $limit       Max results to return
     * @return array
     */
    private function fetchProductsByIds(array $productIds, array $filters, int $limit): array
    {
        if (empty($productIds)) {
            return [];
        }

        // Build the base query
        $query = DB::table('products as p')
            ->select([
                'p.id',
                'p.name',
                'p.slug',
                'p.description',
                'p.price',
                'p.stock',
                'p.views',
                'p.featured',
                'c.name as category_name',
                'c.slug as category_slug',
                'sub.name as subcategory_name',
                'sub.slug as subcategory_slug',
                'pi.image_path as primary_image',
            ])
            ->leftJoin('categories as c',    'c.id',  '=', 'p.category_id')
            ->leftJoin('subcategories as sub','sub.id','=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')
                     ->where('pi.is_primary', '=', 1);
            })
            ->whereIn('p.id', $productIds)
            ->where('p.is_approved', 1)
            ->where('p.is_active',   1)
            ->whereNull('p.deleted_at');

        // Apply optional filters
        if (!empty($filters['category_id'])) {
            $query->where('p.category_id', $filters['category_id']);
        }
        if (!empty($filters['min_price'])) {
            $query->where('p.price', '>=', $filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $query->where('p.price', '<=', $filters['max_price']);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            return [];
        }

        // Re-sort by AI ranking order (MySQL IN clause doesn't guarantee order)
        $indexed = $products->keyBy('id');
        $ordered = [];

        foreach ($productIds as $id) {
            if ($indexed->has($id)) {
                $product   = $indexed->get($id);
                $ordered[] = $this->formatProduct($product);
            }
        }

        // Boost featured products within AI ranking
        // (slight nudge only — don't break semantic order)
        usort($ordered, function ($a, $b) use ($productIds) {
            $posA = array_search($a['id'], $productIds);
            $posB = array_search($b['id'], $productIds);
            // Featured products get a 3-position boost
            $scoreA = $posA - ($a['featured'] ? 3 : 0);
            $scoreB = $posB - ($b['featured'] ? 3 : 0);
            return $scoreA - $scoreB;
        });

        return array_slice($ordered, 0, $limit);
    }

    /**
     * Format a product row for the API response.
     * Builds the full image URL.
     */
    private function formatProduct(object $product): array
    {
        $imageUrl = null;
        if ($product->primary_image) {
            $imageUrl = config('app.url') . '/storage/' . $product->primary_image;
        }

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
            'primary_image'    => $imageUrl,
        ];
    }

    /**
     * Fallback MySQL LIKE search when the AI service is unavailable.
     * Scores results by name match, description match, term spread,
     * views, and featured flag — then sorts by that score descending.
     */
    private function fallbackTextSearch(string $query, array $filters, int $limit): array
    {
        $terms = array_filter(
            explode(' ', $query),
            fn($t) => strlen($t) >= 2
        );

        $dbQuery = DB::table('products as p')
            ->select([
                'p.id', 'p.name', 'p.slug', 'p.description',
                'p.price', 'p.stock', 'p.views', 'p.featured',
                'c.name as category_name',      'c.slug as category_slug',
                'sub.name as subcategory_name', 'sub.slug as subcategory_slug',
                'pi.image_path as primary_image',
                // Relevance score: name match > description match, boosted by views + featured
                DB::raw("
                    (
                        CASE WHEN p.name        LIKE ? THEN 60 ELSE 0 END +
                        CASE WHEN p.description LIKE ? THEN 20 ELSE 0 END +
                        CASE WHEN p.name        LIKE ? THEN 30 ELSE 0 END +
                        (LEAST(p.views, 500) / 500 * 15) +
                        CASE WHEN p.featured = 1 THEN 10 ELSE 0 END
                    ) as relevance_score
                "),
            ])
            ->addBinding(["%{$query}%", "%{$query}%", "%" . implode("%", $terms) . "%"], 'select')
            ->leftJoin('categories as c',      'c.id',   '=', 'p.category_id')
            ->leftJoin('subcategories as sub', 'sub.id', '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')
                     ->where('pi.is_primary', '=', 1);
            })
            ->where('p.is_approved', 1)
            ->where('p.is_active',   1)
            ->whereNull('p.deleted_at')
            ->where(function ($q) use ($terms, $query) {
                $q->where('p.name',        'LIKE', "%{$query}%")
                  ->orWhere('p.description', 'LIKE', "%{$query}%");
                foreach ($terms as $term) {
                    $q->orWhere('p.name', 'LIKE', "%{$term}%");
                }
            });

        if (!empty($filters['category_id'])) {
            $dbQuery->where('p.category_id', $filters['category_id']);
        }
        if (!empty($filters['min_price'])) {
            $dbQuery->where('p.price', '>=', $filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $dbQuery->where('p.price', '<=', $filters['max_price']);
        }

        $products = $dbQuery
            ->orderByDesc('relevance_score')
            ->orderByDesc('p.featured')
            ->orderByDesc('p.views')
            ->limit($limit)
            ->get();

        return $products->map(fn($p) => $this->formatProduct($p))->toArray();
    }
}