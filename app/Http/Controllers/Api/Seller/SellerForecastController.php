<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\ForecastEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
/**
 * SellerForecastController — FIXED VERSION
 *
 * Root causes fixed:
 *  1. Cache defaulted to 6h with no invalidation → reduced to 30min, default refresh=true
 *  2. Regional & similar data was cached 24h → now always fresh (no cache)
 *  3. No mechanism to clear cache after new orders → clearForecastCache() added
 *  4. New route: DELETE /seller/analytics/forecast/cache for manual invalidation
 *  5. New route: GET /seller/analytics/forecast/cache-age to show "last updated X min ago"
 */
class SellerForecastController extends Controller
{
    private ForecastEngine $engine;

    private const TTL_FORECAST = 1800; // 30 minutes
    private const TTL_EVENTS   = 3600; // 1 hour

    public function __construct(ForecastEngine $engine)
    {
        $this->engine = $engine;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1. FULL FORECAST — fixed cache logic
    // ═══════════════════════════════════════════════════════════════════════

    public function fullForecast(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|min:1',
            'months'     => 'nullable|integer|min:1|max:12',
            'refresh'    => 'nullable|boolean',
        ]);

        $productId = (int) $request->product_id;
        $sellerId  = auth()->id();
        $months    = (int) $request->input('months', 6);

        // FIX: default is NOW refresh=true (always recompute unless explicitly cached)
        // Frontend sends refresh=false only when it wants to read a recent cache
        $forceRefresh = (bool) $request->input('refresh', true);

        $cacheKey = "forecast_v2_{$sellerId}_{$productId}_{$months}";

        // Use cache only when NOT forcing refresh AND cache exists AND is fresh
        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $ageSeconds = isset($cached['computed_at'])
                ? now()->diffInSeconds($cached['computed_at'])
                : self::TTL_FORECAST + 1;

            if ($ageSeconds < self::TTL_FORECAST) {
                $cached['_cache_hit']         = true;
                $cached['_cache_age_seconds'] = $ageSeconds;
                return response()->json(['success' => true, 'data' => $cached]);
            }
        }

        // Always recompute
        $result = $this->engine->forecast($productId, $sellerId, [
            'months'         => $months,
            'include_events' => true,
        ]);

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 404);
        }

        $result['_cache_hit']         = false;
        $result['_cache_age_seconds'] = 0;

        Cache::put($cacheKey, $result, self::TTL_FORECAST);
        $this->writeForecastCacheDB($productId, $sellerId, $months, $result);

        return response()->json(['success' => true, 'data' => $result]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2. REGIONAL DEMAND — always fresh, no cache
    // ═══════════════════════════════════════════════════════════════════════

    public function regionalDemand(Request $request)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);

        // FIX: removed 24h cache entirely — this query runs in <100ms and must be live
        $result = $this->engine->regionalDemand(
            (int) $request->product_id,
            auth()->id()
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. SIMILAR PRODUCTS — always fresh, no cache
    // ═══════════════════════════════════════════════════════════════════════

    public function similarProducts(Request $request)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);

        $productId = (int) $request->product_id;
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        $main = DB::table('products as p')
            ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $productId)
            ->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.name, p.price, p.category_id, p.subcategory_id,
                          c.name as category_name, s.name as subcategory_name")
            ->first();

        if (!$main) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $priceMin = (float)$main->price * 0.5;
        $priceMax = (float)$main->price * 2.0;

        $similarQuery = DB::table('products as p')
            ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', true);
            })
            ->where('p.id', '!=', $productId)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->where('p.price', '>=', $priceMin)
            ->where('p.price', '<=', $priceMax);

        $main->subcategory_id
            ? $similarQuery->where('p.subcategory_id', $main->subcategory_id)
            : $similarQuery->where('p.category_id', $main->category_id);

        $similar = $similarQuery
            ->selectRaw("p.id, p.name, p.price, p.stock, p.views,
                          c.name as category_name, s.name as subcategory_name,
                          pi.image_path as primary_image")
            ->limit(20)->get();

        if ($similar->isEmpty()) {
            return response()->json(['success' => true, 'data' => ['has_data' => false, 'similar' => [], 'insights' => []]]);
        }

        $salesByProduct = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.product_id', $similar->pluck('id')->toArray())
            ->whereIn('o.status', ['pending', 'processing', 'completed', 'delivered'])
            ->where('o.created_at', '>=', now()->subMonths(6))
            ->selectRaw("oi.product_id, SUM(oi.quantity) as total_units,
                          COUNT(DISTINCT oi.order_id) as total_orders, SUM({$totalExpr}) as total_revenue")
            ->groupBy('oi.product_id')->get()->keyBy('product_id');

        $storageBase = config('app.url') . '/storage/';
        $enriched = $similar->map(function ($p) use ($salesByProduct, $storageBase) {
            $sales = $salesByProduct->get($p->id);
            return [
                'id'               => $p->id,
                'name'             => $p->name,
                'price'            => (float) $p->price,
                'stock'            => (int)   $p->stock,
                'views'            => (int)   $p->views,
                'category_name'    => $p->category_name,
                'subcategory_name' => $p->subcategory_name,
                'primary_image'    => $p->primary_image ? $storageBase . $p->primary_image : null,
                'monthly_units'    => $sales ? round((float)$sales->total_units / 6, 1) : 0,
                'total_orders_6m'  => (int) ($sales->total_orders ?? 0),
                'total_revenue_6m' => round((float) ($sales->total_revenue ?? 0), 3),
            ];
        })->sortByDesc('monthly_units')->values()->toArray();

        $marketMedianUnits = collect($enriched)->median('monthly_units');
        $ownSales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $productId)
            ->whereIn('o.status', ['pending', 'processing', 'completed', 'delivered'])
            ->where('o.created_at', '>=', now()->subMonths(6))
            ->selectRaw("SUM(oi.quantity) as total_units")->first();

        $ownMonthlyUnits = $ownSales ? round((float)$ownSales->total_units / 6, 1) : 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'has_data'                    => true,
                'similar'                     => $enriched,
                'count'                       => count($enriched),
                'market_avg_price'            => round($similar->avg('price'), 3),
                'market_median_monthly_units' => round($marketMedianUnits, 1),
                'own_monthly_units'           => $ownMonthlyUnits,
                'relative_position_pct'       => $marketMedianUnits > 0 ? round(($ownMonthlyUnits / $marketMedianUnits) * 100, 1) : 0,
                'top_competitor'              => $enriched[0] ?? null,
                'insights'                    => $this->buildSimilarInsights(
                    $ownMonthlyUnits, $marketMedianUnits,
                    (float) $main->price, round($similar->avg('price'), 3),
                    $enriched[0] ?? null
                ),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4. UPCOMING EVENTS (1h cache — events are static)
    // ═══════════════════════════════════════════════════════════════════════
public function upcomingEvents(Request $request)
{
    $request->validate([
        'category_slug' => 'nullable|string',
        'product_id'    => 'nullable|integer|min:1',
    ]);
 
    $categorySlug = $request->query('category_slug');
    $productId    = $request->query('product_id') ? (int) $request->query('product_id') : null;
    $sellerId     = auth()->id();
 
    // ── Resolve category from product if not supplied ────────────────────
    $categoryId   = null;
    $categoryName = null;
    $productName  = null;
    $avgMonthly   = 0.0;
 
    if ($productId) {
        $sellerCol = $this->sellerCol();
        $product   = \Illuminate\Support\Facades\DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $productId)
            ->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.category_id, p.name as product_name, c.name as category_name, c.slug as category_slug")
            ->first();
 
        if ($product) {
            $categoryId   = (int) $product->category_id;
            $categoryName = $product->category_name ?? null;
            $productName  = $product->product_name  ?? null;
            if (!$categorySlug && $product->category_slug) {
                $categorySlug = $product->category_slug;
            }
 
            // Seller's own avg monthly sales for this product (last 6 months)
            $salesRow = \Illuminate\Support\Facades\DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('oi.product_id', $productId)
                ->whereIn('o.status', ['pending', 'processing', 'completed', 'delivered'])
                ->where('o.created_at', '>=', \Carbon\Carbon::now()->subMonths(6))
                ->selectRaw("SUM(oi.quantity) as total_units, COUNT(DISTINCT DATE_FORMAT(o.created_at, '%Y-%m')) as active_months")
                ->first();
 
            if ($salesRow && (int) $salesRow->active_months > 0) {
                $avgMonthly = round((float) $salesRow->total_units / (int) $salesRow->active_months, 1);
            }
        }
    }
 
    $cacheKey = "forecast_events_v2_{$categorySlug}_{$categoryId}_{$productId}";
 
    if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
        return response()->json(['success' => true, 'data' => \Illuminate\Support\Facades\Cache::get($cacheKey)]);
    }
 
    // ── Load upcoming events ─────────────────────────────────────────────
    $query = \Illuminate\Support\Facades\DB::table('product_event_signals')
        ->where('is_active', true)
        ->where('ends_at', '>=', now()->format('Y-m-d'))
        ->orderBy('starts_at');
 
    if ($categorySlug) {
        $query->where(function ($q) use ($categorySlug) {
            $q->whereNull('affected_categories')
              ->orWhereJsonContains('affected_categories', $categorySlug);
        });
    }
 
    $rawEvents = $query->get();
 
    // ── Season → month mapping (matches SellerAIController) ─────────────
    $seasonMonthMap = [
        'ramadan'        => [],          // Islamic — variable
        'eid'            => [],          // Islamic — variable
        'summer'         => [6, 7, 8],
        'school'         => [8, 9],
        'economy'        => [12, 1],
        'tourism'        => [6, 7, 8],
        'winter'         => [12, 1, 2],
        'spring'         => [3, 4, 5],
        'autumn'         => [9, 10, 11],
    ];
 
    // Tunisia category-type seasonal behavior matrix
    // Used when no real category order data exists
    $categoryBaselineMatrix = [
        // event_type => [category_keyword => multiplier]
        'ramadan'  => [
            'default'      => 1.42,
            'food'         => 1.75, 'alimentation' => 1.75, 'épicerie' => 1.70,
            'clothing'     => 1.55, 'mode' => 1.55, 'vêtement' => 1.55,
            'electronics'  => 1.20, 'électronique' => 1.20,
            'beauty'       => 1.35, 'beauté' => 1.35,
            'home'         => 1.38, 'maison' => 1.38,
            'sports'       => 0.90, 'sport' => 0.90,
            'school'       => 0.85, 'scolaire' => 0.85,
        ],
        'eid'      => [
            'default'      => 1.55,
            'clothing'     => 1.90, 'mode' => 1.90, 'vêtement' => 1.90,
            'gifts'        => 1.80, 'cadeaux' => 1.80,
            'food'         => 1.65, 'alimentation' => 1.65,
            'beauty'       => 1.50, 'beauté' => 1.50,
            'electronics'  => 1.30, 'électronique' => 1.30,
            'home'         => 1.40, 'maison' => 1.40,
            'sports'       => 1.10, 'sport' => 1.10,
        ],
        'summer'   => [
            'default'      => 1.18,
            'clothing'     => 1.45, 'mode' => 1.45,
            'beauty'       => 1.30, 'beauté' => 1.30,
            'sports'       => 1.50, 'sport' => 1.50,
            'electronics'  => 0.95, 'électronique' => 0.95,
            'school'       => 0.70, 'scolaire' => 0.70,
            'home'         => 1.20, 'maison' => 1.20,
        ],
        'school'   => [
            'default'      => 1.28,
            'school'       => 2.10, 'scolaire' => 2.10, 'stationery' => 2.10,
            'electronics'  => 1.55, 'électronique' => 1.55,
            'clothing'     => 1.45, 'mode' => 1.45, 'vêtement' => 1.45,
            'bags'         => 1.70, 'sacs' => 1.70,
            'shoes'        => 1.55, 'chaussures' => 1.55,
            'food'         => 0.90, 'alimentation' => 0.90,
        ],
        'economy'  => [
            'default'      => 1.22,
            'electronics'  => 1.45, 'électronique' => 1.45,
            'clothing'     => 1.38, 'mode' => 1.38,
            'home'         => 1.30, 'maison' => 1.30,
            'gifts'        => 1.50, 'cadeaux' => 1.50,
        ],
        'tourism'  => [
            'default'      => 1.15,
            'clothing'     => 1.30, 'mode' => 1.30,
            'beauty'       => 1.25, 'beauté' => 1.25,
            'home'         => 1.20, 'maison' => 1.20,
        ],
    ];
 
    /**
     * Look up the best matching multiplier from the matrix.
     * Tries lowercase category name against each keyword.
     */
    $resolveCategoryMultiplier = function (string $eventType, ?string $catName) use ($categoryBaselineMatrix): float {
        $matrix = $categoryBaselineMatrix[$eventType] ?? null;
        if (!$matrix) return 1.0;
 
        $default = (float) ($matrix['default'] ?? 1.0);
        if (!$catName) return $default;
 
        $lower = strtolower($catName);
        foreach ($matrix as $keyword => $mult) {
            if ($keyword === 'default') continue;
            if (str_contains($lower, $keyword)) {
                return (float) $mult;
            }
        }
        return $default;
    };
 
    /**
     * Compute real category-level seasonal multiplier from order history.
     * Returns null if <10 data points (fall back to baseline).
     */
    $computeRealMultiplier = function (string $eventType, ?int $catId, array $monthNums) use ($seasonMonthMap): ?array {
        if (!$catId || empty($monthNums)) return null;
 
        $inSeason = \Illuminate\Support\Facades\DB::table('order_items as oi')
            ->join('orders as o',   'o.id',  '=', 'oi.order_id')
            ->join('products as p', 'p.id',  '=', 'oi.product_id')
            ->where('p.category_id', $catId)
            ->whereIn('o.status', ['pending', 'processing', 'completed', 'delivered'])
            ->whereIn(\Illuminate\Support\Facades\DB::raw('MONTH(o.created_at)'), $monthNums)
            ->where('o.created_at', '>=', \Carbon\Carbon::now()->subMonths(24))
            ->selectRaw("AVG(oi.quantity) as avg_qty, COUNT(*) as cnt")
            ->first();
 
        $offSeason = \Illuminate\Support\Facades\DB::table('order_items as oi')
            ->join('orders as o',   'o.id',  '=', 'oi.order_id')
            ->join('products as p', 'p.id',  '=', 'oi.product_id')
            ->where('p.category_id', $catId)
            ->whereIn('o.status', ['pending', 'processing', 'completed', 'delivered'])
            ->whereNotIn(\Illuminate\Support\Facades\DB::raw('MONTH(o.created_at)'), $monthNums)
            ->where('o.created_at', '>=', \Carbon\Carbon::now()->subMonths(24))
            ->selectRaw("AVG(oi.quantity) as avg_qty, COUNT(*) as cnt")
            ->first();
 
        $sQty = (float) ($inSeason->avg_qty  ?? 0);
        $oQty = (float) ($offSeason->avg_qty ?? 0);
        $cnt  = (int)   ($inSeason->cnt      ?? 0);
 
        if ($cnt < 10 || $sQty <= 0 || $oQty <= 0) return null;
 
        return [
            'multiplier'  => round($sQty / $oQty, 3),
            'data_points' => $cnt,
        ];
    };
 
    /**
     * Compute confidence score (0-100) for a prediction.
     */
    $computeConfidence = function (
        float $avgMonthly,
        ?array $realData,
        bool $hasCategoryData
    ): array {
        $score  = 30; // base
        $label  = 'low';
 
        if ($realData !== null) {
            // Real category data
            $score += min(40, (int) round($realData['data_points'] / 5));
            $score += 20; // category intelligence bonus
        } elseif ($hasCategoryData) {
            $score += 10; // category matrix matched
        }
 
        if ($avgMonthly > 0) {
            $score += min(20, (int) round($avgMonthly * 2));
        }
 
        $score = min(95, $score);
        $label = $score >= 70 ? 'high' : ($score >= 45 ? 'medium' : 'low');
 
        return ['score' => $score, 'label' => $label];
    };
 
    // ── Batch Groq call — one prompt covers all events ───────────────────
    // We generate explanations in batch to minimise latency.
    $groqKey = config('services.groq.key', env('GROQ_API_KEY', ''));
    $eventExplanations = [];
 
    if (!empty($groqKey) && $rawEvents->isNotEmpty()) {
        try {
            $eventList = $rawEvents->map(fn($ev) => [
                'slug'  => $ev->event_slug,
                'name'  => $ev->event_name,
                'type'  => $ev->event_type,
            ])->values()->toJson();
 
            $catContext = $categoryName ? "Category: {$categoryName}." : '';
            $prodContext= $productName  ? "Product: {$productName}."   : '';
 
            $res = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => "Bearer {$groqKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(12)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'llama3-8b-8192',
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => "You are a Tunisian e-commerce advisor. {$catContext} {$prodContext}\n"
                            . "For each event, write ONE short sentence (max 18 words) explaining WHY demand changes for this product/category during the event.\n"
                            . "Be specific. Reference the category if provided. No generic sentences.\n"
                            . "Respond ONLY with valid JSON: {\"explanations\": [{\"slug\": \"...\", \"text\": \"...\"}]}\n"
                            . "No markdown. No extra text.",
                    ],
                    [
                        'role'    => 'user',
                        'content' => "Events: {$eventList}",
                    ],
                ],
                'max_tokens'  => 400,
                'temperature' => 0.3,
            ]);
 
            if ($res->successful()) {
                $raw   = $res->json('choices.0.message.content', '');
                $clean = preg_replace('/```json|```/i', '', $raw);
                $s     = strpos($clean, '{');
                $e     = strrpos($clean, '}');
                if ($s !== false && $e !== false) {
                    $parsed = json_decode(substr($clean, $s, $e - $s + 1), true);
                    foreach ($parsed['explanations'] ?? [] as $ex) {
                        if (!empty($ex['slug']) && !empty($ex['text'])) {
                            $eventExplanations[$ex['slug']] = $ex['text'];
                        }
                    }
                }
            }
        } catch (\Throwable $e2) {
            \Illuminate\Support\Facades\Log::warning('[ForecastEvents] Groq batch: ' . $e2->getMessage());
        }
    }
 
    // ── Fallback explanations per event type ─────────────────────────────
    $fallbackExplanations = [
        'ramadan'  => 'Ramadan drives strong spending on gifting, fashion, and food across all Tunisian regions.',
        'eid'      => 'Eid Al-Fitr sparks a major shopping surge — families buy new clothing, gifts, and food.',
        'summer'   => 'Summer season boosts demand for outdoor, fashion, and lifestyle categories.',
        'school'   => 'Back-to-school creates strong demand for stationery, clothing, electronics, and bags.',
        'economy'  => 'Year-end promotions and new-year spending lift demand across all categories.',
        'tourism'  => 'Tourism season boosts coastal demand for fashion, beauty, and home products.',
    ];
 
    // ── Build enriched event objects ─────────────────────────────────────
    $enrichedEvents = $rawEvents->map(function ($ev) use (
        $categoryId, $categoryName, $avgMonthly,
        $seasonMonthMap, $resolveCategoryMultiplier, $computeRealMultiplier, $computeConfidence,
        $eventExplanations, $fallbackExplanations
    ) {
        $eventType  = $ev->event_type;
        $monthNums  = $seasonMonthMap[$eventType] ?? [];
 
        // 1. Try real data multiplier
        $realData   = ($computeRealMultiplier)($eventType, $categoryId, $monthNums);
 
        // 2. Category-aware baseline
        $baselineMultiplier = ($resolveCategoryMultiplier)($eventType, $categoryName);
 
        // 3. Final dynamic multiplier
        $hasCategoryData = $categoryName !== null;
        if ($realData !== null) {
            // Blend: 70% real, 30% category baseline (smooths outliers)
            $dynamicMultiplier = round($realData['multiplier'] * 0.70 + $baselineMultiplier * 0.30, 2);
            $multiplierSource  = 'real_data';
            $dataPoints        = $realData['data_points'];
        } else {
            $dynamicMultiplier = round($baselineMultiplier, 2);
            $multiplierSource  = $hasCategoryData ? 'category_baseline' : 'tunisia_baseline';
            $dataPoints        = 0;
        }
 
        // Clamp to sensible range
        $dynamicMultiplier = max(0.80, min(3.50, $dynamicMultiplier));
 
        // 4. Predicted sales units
        // If seller has their own avg monthly, use it; otherwise show "+" pct
        $predictedUnits = null;
        if ($avgMonthly > 0) {
            $predictedUnits = (int) round($avgMonthly * $dynamicMultiplier);
        }
 
        // 5. Demand increase % vs baseline
        $demandIncreasePct = round(($dynamicMultiplier - 1.0) * 100, 1);
 
        // 6. Confidence
        $confidence = ($computeConfidence)($avgMonthly, $realData, $hasCategoryData);
 
        // 7. AI explanation
        $explanation = $eventExplanations[$ev->event_slug]
            ?? $fallbackExplanations[$eventType]
            ?? "This event typically influences demand for products in this category.";
 
        // 8. Top regions
        $topRegions = json_decode($ev->top_regions ?? '[]', true) ?: [];
 
        // 9. Stock recommendation
        $stockAction = match(true) {
            $dynamicMultiplier >= 1.5  => 'Increase stock significantly',
            $dynamicMultiplier >= 1.2  => 'Increase stock moderately',
            $dynamicMultiplier >= 1.05 => 'Monitor stock closely',
            default                    => 'Stock levels likely sufficient',
        };
 
        return [
            // Original fields — unchanged so existing cards still work
            'slug'        => $ev->event_slug,
            'name'        => $ev->event_name,
            'type'        => $ev->event_type,
            'starts_at'   => $ev->starts_at,
            'ends_at'     => $ev->ends_at,
            'boost_score' => $dynamicMultiplier,  // replaced with dynamic value
            'top_regions' => $topRegions,
            'days_until'  => max(0, (int) now()->diffInDays($ev->starts_at, false)),
 
            // NEW enrichment fields
            'dynamic_multiplier'    => $dynamicMultiplier,
            'multiplier_source'     => $multiplierSource,
            'demand_increase_pct'   => $demandIncreasePct,
            'predicted_units'       => $predictedUnits,   // null if no product selected
            'data_points'           => $dataPoints,
            'confidence_score'      => $confidence['score'],
            'confidence_label'      => $confidence['label'],
            'ai_explanation'        => $explanation,
            'stock_action'          => $stockAction,
            'top_regions'           => $topRegions,
            'category_aware'        => $hasCategoryData,
        ];
    });
 
    \Illuminate\Support\Facades\Cache::put($cacheKey, $enrichedEvents, self::TTL_EVENTS * 2); // 2h
    return response()->json(['success' => true, 'data' => $enrichedEvents]);
}

    // ═══════════════════════════════════════════════════════════════════════
    // 5. AI EXPLAIN
    // ═══════════════════════════════════════════════════════════════════════

    public function aiExplain(Request $request)
    {
        $request->validate([
            'product_id'    => 'required|integer',
            'forecast_data' => 'required|array',
            'regional_data' => 'nullable|array',
            'language'      => 'nullable|in:fr,ar,en',
        ]);

        $language     = $request->input('language', 'fr');
        $forecastData = $request->input('forecast_data');
        $projections  = collect($forecastData['projections'] ?? []);
        $peakMonth    = $projections->sortByDesc('predicted_units')->first();
        $totalUnits   = $projections->sum('predicted_units');
        $demandScore  = $forecastData['demand_score'] ?? 0;
        $trend        = $forecastData['overall_trend'] ?? 'stable';
        $topRegion    = $request->input('regional_data.top_region.wilaya');
        $productName  = $forecastData['product_name'] ?? 'Product';
        $categoryName = $forecastData['category_name'] ?? '';
        $stockRec     = $forecastData['stock_recommendation_3m'] ?? 0;
        $confidence   = $forecastData['confidence_label'] ?? 'medium';
        $eventMonths  = $projections->filter(fn($p) => !empty($p['event_name']))
            ->map(fn($p) => "{$p['label']} ({$p['event_name']})")->implode(', ');

        $langInstr = match($language) {
            'ar'    => 'Respond in Arabic.',
            'fr'    => 'Respond in French. Practical advice for a Tunisian seller.',
            default => 'Respond in English.',
        };

        try {
            $groqKey = config('services.groq.key');
            if (empty($groqKey)) throw new \Exception('No key');

            $res = Http::withHeaders([
                'Authorization' => "Bearer {$groqKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(20)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => config('services.groq.model', 'llama3-8b-8192'),
                'messages'    => [
                    ['role' => 'system', 'content' => "You are a Tunisian e-commerce advisor. {$langInstr} Respond ONLY with valid JSON."],
                    ['role' => 'user',   'content' => "Explain: {$productName} ({$categoryName}), {$totalUnits} units/6mo, trend:{$trend}, score:{$demandScore}/100, peak:{$peakMonth['label']}, stock_needed:{$stockRec}, confidence:{$confidence}, events:{$eventMonths}, top_region:{$topRegion}.\nJSON: {\"summary\":\"...\",\"main_opportunity\":\"...\",\"main_risk\":\"...\",\"seasonal_tip\":\"...\"}"],
                ],
                'max_tokens'  => 350,
                'temperature' => 0.3,
            ]);

            if (!$res->successful()) throw new \Exception('Groq ' . $res->status());
            $raw   = $res->json('choices.0.message.content', '');
            $clean = preg_replace('/```json|```/i', '', $raw);
            $s = strpos($clean, '{'); $e = strrpos($clean, '}');
            if ($s === false || $e === false) throw new \Exception('No JSON');
            $parsed = json_decode(substr($clean, $s, $e - $s + 1), true);
            if (!$parsed) throw new \Exception('Parse fail');
            return response()->json(['success' => true, 'data' => $parsed]);

        } catch (\Throwable $e) {
            Log::warning('[Forecast::aiExplain] ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => [
                'summary'          => "Forecast: {$totalUnits} units/6 months. Trend: {$trend}. Score: {$demandScore}/100.",
                'main_opportunity' => "Peak in {$peakMonth['label']} — prepare stock early.",
                'main_risk'        => "Keep {$stockRec} units available for the next 3 months.",
                'seasonal_tip'     => $eventMonths ? "Key periods: {$eventMonths}." : "No major events in period.",
            ]]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6. INVALIDATE CACHE — call after new orders
    // ═══════════════════════════════════════════════════════════════════════

    public function invalidateCache(Request $request)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);
        $productId = (int) $request->product_id;
        $sellerId  = auth()->id();
        self::clearForecastCache($productId, $sellerId);
        return response()->json(['success' => true, 'message' => 'Cache cleared.']);
    }

    /**
     * Static helper — usable from Observers/Jobs without HTTP context.
     *
     * Example in SellerOrderController after status update:
     *   SellerForecastController::clearForecastCache($productId, $sellerId);
     */
    public static function clearForecastCache(int $productId, int $sellerId): void
    {
        for ($m = 1; $m <= 12; $m++) {
            Cache::forget("forecast_v2_{$sellerId}_{$productId}_{$m}");
        }
        DB::table('forecast_cache')->where('product_id', $productId)->delete();
        Log::info("[ForecastCache] Cleared: product={$productId} seller={$sellerId}");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 7. CACHE AGE — "last updated X min ago"
    // ═══════════════════════════════════════════════════════════════════════

    public function cacheAge(Request $request)
    {
        $request->validate(['product_id' => 'required|integer|min:1']);
        $productId = (int) $request->product_id;
        $sellerId  = auth()->id();

        $row = DB::table('forecast_cache')
            ->where('product_id', $productId)
            ->where('cache_key', "seller_{$sellerId}_6m")
            ->orderByDesc('updated_at')
            ->first();

        if (!$row) {
            return response()->json(['success' => true, 'data' => ['computed_at' => null, 'age_minutes' => null, 'is_stale' => true]]);
        }

        $ageMinutes = now()->diffInMinutes($row->updated_at);
        return response()->json(['success' => true, 'data' => [
            'computed_at' => $row->updated_at,
            'age_minutes' => $ageMinutes,
            'is_stale'    => $ageMinutes > 30,
        ]]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function writeForecastCacheDB(int $productId, int $sellerId, int $months, array $result): void
    {
        try {
            DB::table('forecast_cache')->updateOrInsert(
                ['product_id' => $productId, 'cache_key' => "seller_{$sellerId}_{$months}m"],
                [
                    'payload'          => json_encode(['demand_score' => $result['demand_score'] ?? 0, 'overall_trend' => $result['overall_trend'] ?? 'stable', 'data_points' => $result['data_points'] ?? 0]),
                    'expires_at'       => now()->addMinutes(30),
                    'computed_by'      => $result['computed_by'] ?? 'laravel',
                    'data_points'      => $result['data_points'] ?? 0,
                    'confidence_score' => $result['confidence'] ?? 0,
                    'updated_at'       => now(),
                    'created_at'       => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[ForecastCache] DB write: ' . $e->getMessage());
        }
    }

    private function buildSimilarInsights(float $ownUnits, float $marketMedian, float $ownPrice, float $marketAvgPrice, ?array $topSeller): array
    {
        $insights = [];
        if ($marketMedian > 0) {
            $ratio = $ownUnits / $marketMedian;
            if ($ratio < 0.5)      $insights[] = ['type' => 'warning',     'message' => "Sales are " . round((1 - $ratio) * 100) . "% below similar products."];
            elseif ($ratio > 1.5)  $insights[] = ['type' => 'positive',    'message' => "Outperforming " . round($ratio * 100 - 100) . "% above similar products."];
            else                   $insights[] = ['type' => 'neutral',     'message' => "Sales are in line with similar products."];
        }
        if ($ownPrice > $marketAvgPrice * 1.20)   $insights[] = ['type' => 'warning',     'message' => "Price is " . round(($ownPrice / $marketAvgPrice - 1) * 100) . "% above market average."];
        elseif ($ownPrice < $marketAvgPrice * 0.80) $insights[] = ['type' => 'opportunity', 'message' => "Price is below market average — room to increase 10–15%."];
        if ($topSeller && $topSeller['monthly_units'] > 0) $insights[] = ['type' => 'info', 'message' => "Top performer: '{$topSeller['name']}' — {$topSeller['monthly_units']} units/month."];
        return $insights;
    }

    private function sellerCol(): string
    {
        static $col = null;
        if ($col) return $col;
        $cols = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM products'));
        return $col = in_array('seller_id', $cols) ? 'seller_id' : 'user_id';
    }

    private function totalExpr(): string
    {
        $cols = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM order_items'));
        $parts = [];
        if (in_array('total', $cols)) $parts[] = 'oi.total';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols)) $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols)) $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}