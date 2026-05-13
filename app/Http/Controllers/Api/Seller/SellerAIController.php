<?php
// app/Http/Controllers/Api/Seller/SellerAIController.php
//
// ONLY salesPredictor() is modified in this file.
// All other methods (priceOptimizer, descriptionGenerator,
// quickDescription, recommender) remain EXACTLY as they were.

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\MarketIntelligenceService;
use App\Services\PriceNormalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class SellerAIController extends Controller
{
    private string $groqApiUrl = 'https://api.groq.com/openai/v1/chat/completions';
    private string $groqModel  = 'llama3-8b-8192';

    private function groqKey(): string
    {
        return config('services.groq.key', env('GROQ_API_KEY', ''));
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
        $cols  = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM order_items'));
        $parts = [];
        if (in_array('total',      $cols)) $parts[] = 'oi.total';
        if (in_array('subtotal',   $cols)) $parts[] = 'oi.subtotal';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function callGroq(string $system, string $user, int $maxTokens = 700): ?string
    {
        $key = $this->groqKey();
        if (empty($key)) {
            Log::warning('[SellerAI] GROQ_API_KEY not configured');
            return null;
        }

        try {
            $res = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ])->timeout(25)->post($this->groqApiUrl, [
                'model'       => $this->groqModel,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'max_tokens'  => $maxTokens,
                'temperature' => 0.3,
            ]);

            if (!$res->successful()) {
                Log::warning('[SellerAI] Groq error ' . $res->status() . ': ' . $res->body());
                return null;
            }

            return $res->json('choices.0.message.content');
        } catch (\Throwable $e) {
            Log::error('[SellerAI] ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1. PRICE OPTIMIZER — UNCHANGED
    // ═══════════════════════════════════════════════════════════════════════
   public function priceOptimizer(Request $request)
{
    $request->validate(['product_id' => 'required|integer']);

    $sellerId  = auth()->id();
    $sellerCol = $this->sellerCol();
    $totalExpr = $this->totalExpr();

    $product = DB::table('products as p')
        ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
        ->where("p.{$sellerCol}", $sellerId)
        ->where('p.id', $request->product_id)
        ->whereNull('p.deleted_at')
        ->selectRaw("p.id, p.name, p.price, p.stock, p.views, p.category_id, c.name as category_name")
        ->first();

    if (!$product) {
        return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
    }

    $salesHistory = DB::table('order_items as oi')
        ->join('orders as o', 'o.id', '=', 'oi.order_id')
        ->where('oi.product_id', $request->product_id)
        ->whereIn('o.status', ['completed', 'delivered'])
        ->selectRaw("
            COUNT(DISTINCT oi.order_id) as total_orders,
            SUM(oi.quantity)            as total_units,
            SUM({$totalExpr})           as total_revenue,
            AVG(oi.unit_price)          as avg_sold_price
        ")
        ->first();

    $productPrice = (float)$product->price;
    $priceLow     = $productPrice * 0.25;
    $priceHigh    = $productPrice * 4.0;

    // ── Internal platform competitors (always runs) ───────────────────────
    $priceStdDevRow = DB::table('products as p')
        ->where('p.category_id', $product->category_id)
        ->where('p.id', '!=', $request->product_id)
        ->where('p.is_approved', true)
        ->where('p.is_active', true)
        ->whereNull('p.deleted_at')
        ->where('p.price', '>=', $priceLow)
        ->where('p.price', '<=', $priceHigh)
        ->selectRaw("AVG(p.price) as avg_price, STDDEV(p.price) as std_price, COUNT(*) as count")
        ->first();

    $catAvgRaw   = (float)($priceStdDevRow->avg_price ?? 0);
    $catStd      = (float)($priceStdDevRow->std_price ?? 0);
    $lowerBound  = $catStd > 0 ? max($priceLow, $catAvgRaw - 2.0 * $catStd) : $priceLow;
    $upperBound  = $catStd > 0 ? min($priceHigh, $catAvgRaw + 2.0 * $catStd) : $priceHigh;

    $similarProducts = DB::table('products as p')
        ->where('p.category_id', $product->category_id)
        ->where('p.id', '!=', $request->product_id)
        ->where('p.is_approved', true)
        ->where('p.is_active', true)
        ->whereNull('p.deleted_at')
        ->where('p.price', '>=', $lowerBound)
        ->where('p.price', '<=', $upperBound)
        ->selectRaw("AVG(p.price) as avg_price, MIN(p.price) as min_price, MAX(p.price) as max_price, COUNT(*) as count")
        ->first();

    $monthlySales = DB::table('order_items as oi')
        ->join('orders as o', 'o.id', '=', 'oi.order_id')
        ->where('oi.product_id', $request->product_id)
        ->whereIn('o.status', ['completed', 'delivered'])
        ->where('o.created_at', '>=', \Carbon\Carbon::now()->subMonths(6))
        ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(oi.quantity) as units")
        ->groupBy('month')
        ->orderBy('month')
        ->get();

    $conversionRate  = 0;
    if (($product->views ?? 0) > 0 && ($salesHistory->total_orders ?? 0) > 0) {
        $conversionRate = round(($salesHistory->total_orders / $product->views) * 100, 2);
    }

    $totalUnits      = (int)($salesHistory->total_units    ?? 0);
    $totalRevenue    = round((float)($salesHistory->total_revenue ?? 0), 3);
    $competitorCount = (int)($similarProducts->count       ?? 0);
    $catAvgPrice     = round((float)($similarProducts->avg_price ?? 0), 3);
    $catMinPrice     = round((float)($similarProducts->min_price ?? 0), 3);
    $catMaxPrice     = round((float)($similarProducts->max_price ?? 0), 3);
    $trendStr        = $monthlySales->map(fn($r) => "{$r->month}: {$r->units} units")->implode(', ') ?: 'No sales yet';

    // ── Tier 2: Serper market data ────────────────────────────────────────
    $marketReport = ['has_data' => false];
    try {
        $marketSvc    = new \App\Services\MarketIntelligenceService(new \App\Services\PriceNormalizationService());
        $marketReport = $marketSvc->analyze($product->name, $product->category_name ?? 'General', $productPrice);
    } catch (\Throwable $e) {
        Log::warning("[SellerAI::priceOptimizer] Market intelligence failed: " . $e->getMessage());
    }

    $hasMarketData  = (bool)($marketReport['has_data'] ?? false);
    $safeMarketAvg  = $hasMarketData ? (float)$marketReport['market_avg']  : 0.0;
    $safeCatAvg     = $catAvgPrice > 0 ? $catAvgPrice : 0.0;

    // The best reference price: Serper real data > platform avg > current price
    $bestRef = $safeMarketAvg > 0 ? $safeMarketAvg
             : ($safeCatAvg > 0   ? $safeCatAvg
             : $productPrice);

    $psycho = static function (float $n): float {
        if ($n <= 1) return $n;
        return floor($n) - 0.100;
    };

    // ── Build Groq prompt — REAL DATA ONLY ────────────────────────────────
    // KEY RULE: Groq receives real prices. It ANALYZES. It does NOT invent.

    if ($hasMarketData) {
        $dataPoints    = $marketReport['data_points'];
        $sourcesCount  = $marketReport['sources_count'];
        $sourcesDetail = $marketReport['sources_detail'] ?? $marketReport['by_source'] ?? [];

        $bySourceStr = '';
        foreach ($sourcesDetail as $src) {
            $bySourceStr .= "\n    • {$src['source']}: {$src['count']} listings, avg {$src['avg']} TND (range {$src['min']}–{$src['max']} TND)";
        }

        $marketSection = <<<EOT

REAL TUNISIAN MARKET DATA — collected from {$sourcesCount} sources, {$dataPoints} actual search results:
- Market average price:  {$marketReport['market_avg']} TND
- Market median price:   {$marketReport['market_median']} TND
- Market price range:    {$marketReport['market_min']} – {$marketReport['market_max']} TND
- Confidence level:      {$marketReport['confidence']} ({$marketReport['confidence_score']}/100)
- Seller positioning:    {$marketReport['positioning']} ({$marketReport['positioning_pct']}% vs market avg)
- Psychological price:   {$marketReport['psycho_price']} TND
By source:{$bySourceStr}

CONSTRAINT: Use the market_avg ({$marketReport['market_avg']} TND) as the anchor for ALL price fields.
suggested_price must be close to this market avg unless the seller's data strongly justifies deviation.
EOT;

    } elseif ($safeCatAvg > 0) {
        $marketSection = <<<EOT

TUNISIAN MARKET DATA — External search returned 0 results (Serper key may be unconfigured).
INTERNAL PLATFORM DATA (real, from ChooseTounsi database):
- Platform competitors in this category: {$competitorCount} products
- Platform avg price:  {$safeCatAvg} TND
- Platform price range: {$catMinPrice} – {$catMaxPrice} TND

CONSTRAINT: Use platform avg ({$safeCatAvg} TND) as the pricing anchor.
DO NOT invent external market prices. State clearly this is platform-only data.
EOT;

    } else {
        $marketSection = <<<EOT

TUNISIAN MARKET DATA: No external or internal competitor data available.
CONSTRAINT: Base ALL price recommendations on the current price ({$productPrice} TND) ±30%.
DO NOT invent competitor prices or claim to know market rates.
Be explicit in reasoning that this is based on general Tunisian market knowledge, not real data.
EOT;
    }

    $systemPrompt = <<<EOT
You are a Tunisian e-commerce pricing strategist for ChooseTounsi.
You receive REAL market data collected from Tunisian websites (Tayara, Mytek, Tunisianet).

ABSOLUTE RULES:
1. NEVER invent prices. All price fields must derive from the real data provided.
2. suggested_price must be within the market range provided (or ±30% of current price if no data).
3. If market data exists, market_avg_price = the provided market_avg exactly.
4. If no market data, state this clearly in reasoning. Do not fabricate market knowledge.
5. platforms_compared must list only platforms from the data — never add platforms not in the data.
6. All prices in TND. No zeros. No nulls.
7. Respond with ONLY valid JSON. No markdown. No text outside JSON.
EOT;

    $userPrompt = <<<EOT
Generate a pricing recommendation for this ChooseTounsi product.

PRODUCT:
- Name: {$product->name}
- Category: {$product->category_name}
- Current price: {$productPrice} TND
- Stock: {$product->stock} | Views: {$product->views} | Conversion: {$conversionRate}%
- Units sold: {$totalUnits} | Revenue: {$totalRevenue} TND
- 6-month trend: {$trendStr}
{$marketSection}

Return ONLY this JSON:
{
  "suggested_price": <number — from real market data or ±30% of current>,
  "competitive_price": <number — matches market/platform avg exactly>,
  "premium_price": <number — justified premium ceiling>,
  "min_profitable_price": <number — floor, ~85% of current price>,
  "market_avg_price": <number — copy the provided market_avg if available, else estimate clearly>,
  "confidence": "high"|"medium"|"low",
  "risk": "low"|"medium"|"high",
  "strategy": "<strategy name>",
  "reasoning": "<2-3 sentences. State what data you used. Never claim real-time data you don't have>",
  "expected_impact": "<one sentence>",
  "market_positioning": "underpriced"|"competitive"|"overpriced",
  "competitor_summary": "<one sentence. Name only platforms from the provided data>",
  "overpriced_warning": <string or null>,
  "opportunity_note": <string or null>,
  "psychological_tip": "<specific charm pricing suggestion>",
  "platforms_compared": [<only platforms from the actual data provided>],
  "min_price": <number>,
  "max_price": <number>
}
EOT;

    $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 750);
    $aiResult = null;

    if ($aiRaw) {
        try {
            $clean = preg_replace('/```json|```/i', '', $aiRaw);
            $start = strpos($clean, '{');
            $end   = strrpos($clean, '}');
            if ($start !== false && $end !== false) {
                $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
                if ($parsed) {
                    $priceFields = ['suggested_price','competitive_price','premium_price',
                                   'min_profitable_price','market_avg_price','min_price','max_price'];
                    $valid = true;
                    foreach ($priceFields as $f) {
                        if (empty($parsed[$f]) || (float)$parsed[$f] <= 0) { $valid = false; break; }
                    }
                    if ($valid) $aiResult = $parsed;
                    else Log::warning('[SellerAI] Groq returned zero price fields — math fallback.');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SellerAI::priceOptimizer] JSON parse: ' . $e->getMessage());
        }
    }

    // ── Math fallback (no Groq or parse failure) ──────────────────────────
    if (!$aiResult) {
        $demandBoost  = $totalUnits > 50 ? 1.06 : ($totalUnits > 10 ? 1.03 : 1.0);
        $rawSuggested = $bestRef * $demandBoost;
        $suggested    = round(max($productPrice * 0.80, min($productPrice * 1.20, $rawSuggested)), 3);
        $competitive  = round($bestRef, 3);
        $premium      = round($suggested * 1.15, 3);
        $minProfit    = round($productPrice * 0.85, 3);
        $minPrice     = round($productPrice * 0.80, 3);
        $maxPrice     = round($productPrice * 1.25, 3);

        $positioningPct = $hasMarketData ? (float)($marketReport['positioning_pct'] ?? 0) : 0;
        $positioning    = $hasMarketData ? ($marketReport['positioning'] ?? 'competitive') : 'competitive';
        $psychoTip      = $psycho($suggested);

        $platformsUsed = [];
        if ($hasMarketData) {
            foreach (($marketReport['sources_detail'] ?? $marketReport['by_source'] ?? []) as $src) {
                $platformsUsed[] = $src['source'];
            }
        }

        if ($hasMarketData) {
            $reasonBase = "Based on {$marketReport['data_points']} real Tunisian market data points from " . implode(', ', $platformsUsed) . " (avg: {$marketReport['market_avg']} TND)";
        } elseif ($safeCatAvg > 0) {
            $reasonBase = "Based on {$competitorCount} ChooseTounsi platform listings in this category (avg: {$safeCatAvg} TND)";
        } else {
            $reasonBase = "No market data available — recommendation based on general Tunisian market knowledge for {$product->category_name}";
        }

        $aiResult = [
            'suggested_price'      => $suggested,
            'competitive_price'    => $competitive,
            'premium_price'        => $premium,
            'min_profitable_price' => $minProfit,
            'market_avg_price'     => round($bestRef, 3),
            'confidence'           => $hasMarketData ? ($marketReport['confidence'] ?? 'medium') : ($safeCatAvg > 0 ? 'medium' : 'low'),
            'risk'                 => 'low',
            'strategy'             => $totalUnits === 0 ? 'Competitive entry pricing' : 'Market-aligned pricing',
            'reasoning'            => "{$reasonBase}. Your current price of {$productPrice} TND is {$positioning}.",
            'expected_impact'      => $totalUnits === 0
                ? "A competitive entry price should attract first buyers on ChooseTounsi."
                : "Aligning with market pricing maintains conversion while optimizing revenue.",
            'market_positioning'   => $positioning,
            'competitor_summary'   => $hasMarketData
                ? implode(', ', $platformsUsed) . " show {$marketReport['data_points']} listings ranging {$marketReport['market_min']}–{$marketReport['market_max']} TND."
                : ($safeCatAvg > 0
                    ? "ChooseTounsi shows {$competitorCount} competitors (avg {$safeCatAvg} TND)."
                    : "No competitor data found."),
            'overpriced_warning'   => $positioningPct > 15
                ? "Your price is {$positioningPct}% above market average — consider reducing to improve conversion."
                : null,
            'opportunity_note'     => $positioningPct < -10
                ? "Your price is " . abs($positioningPct) . "% below market — you may have room to increase."
                : ($totalUnits === 0 ? "No sales yet — ensure listing has complete images." : null),
            'psychological_tip'    => "Use {$psychoTip} TND instead of {$suggested} TND — charm pricing converts better.",
            'platforms_compared'   => $platformsUsed,
            'min_price'            => $minPrice,
            'max_price'            => $maxPrice,
        ];
    }

    return response()->json([
        'success' => true,
        'data'    => [
            'ai_result'    => $aiResult,
            'data_context' => [
                'product_name'    => $product->name,
                'current_price'   => $productPrice,
                'total_units'     => $totalUnits,
                'total_revenue'   => $totalRevenue,
                'conversion_rate' => $conversionRate,
                'category_avg'    => $safeCatAvg,
                'monthly_trend'   => $monthlySales,
                'market_report'   => [
                    'has_data'         => $hasMarketData,
                    'data_points'      => $marketReport['data_points']      ?? 0,
                    'sources_count'    => $marketReport['sources_count']    ?? 0,
                    'market_avg'       => $marketReport['market_avg']       ?? 0,
                    'market_min'       => $marketReport['market_min']       ?? 0,
                    'market_max'       => $marketReport['market_max']       ?? 0,
                    'confidence'       => $marketReport['confidence']       ?? 'low',
                    'confidence_score' => $marketReport['confidence_score'] ?? 0,
                    'positioning'      => $marketReport['positioning']      ?? 'unknown',
                    'positioning_pct'  => $marketReport['positioning_pct'] ?? 0,
                    'by_source'        => $marketReport['sources_detail']   ?? $marketReport['by_source'] ?? [],
                    'data_source'      => $marketReport['data_source']      ?? 'none',
                ],
            ],
        ],
    ]);
}
    // ═══════════════════════════════════════════════════════════════════════
    // 2. SALES PREDICTOR — ENRICHED WITH FULL PRODUCT DNA
    //    Now fetches: subcategory, variant count, price range,
    //    top-selling variant combos, info attributes (brand/material/gender)
    //    and injects all of it into the Groq prompt for a precise prediction.
    // ═══════════════════════════════════════════════════════════════════════
    // ═══════════════════════════════════════════════════════════════════════
// 2. SALES PREDICTOR — DATA-DRIVEN, DETERMINISTIC
//    Season is read from the product's own DB column (seller declared it
//    at product creation). Groq never guesses anything numeric.
//    All numbers are pre-computed server-side. AI only writes sentences.
// ═══════════════════════════════════════════════════════════════════════
public function salesPredictor(Request $request)
{
    $request->validate(['product_id' => 'required|integer']);

    $sellerId  = auth()->id();
    $sellerCol = $this->sellerCol();
    $totalExpr = $this->totalExpr();

    // ── 1. Core product row — season comes from DB, not from request ──────
    $product = DB::table('products as p')
        ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
        ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
        ->where("p.{$sellerCol}", $sellerId)
        ->where('p.id', $request->product_id)
        ->whereNull('p.deleted_at')
        ->selectRaw("
            p.id, p.name, p.price, p.stock, p.views,
            p.subcategory_id,
            COALESCE(p.season, 'all_seasons') as season,
            c.name  as category_name,
            s.name  as subcategory_name
        ")
        ->first();

    if (!$product) {
        return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
    }

    $season = $product->season;

    // ── 2. Historical monthly sales — 18 months for better trend detection ─
    $monthlySales = DB::table('order_items as oi')
        ->join('orders as o', 'o.id', '=', 'oi.order_id')
        ->where('oi.product_id', $request->product_id)
        ->whereIn('o.status', ['completed', 'delivered'])
        ->where('o.created_at', '>=', Carbon::now()->subMonths(18))
        ->selectRaw("
            DATE_FORMAT(o.created_at, '%Y-%m') as month,
            MONTH(o.created_at) as month_num,
            SUM(oi.quantity) as units,
            COUNT(DISTINCT oi.order_id) as orders,
            SUM({$totalExpr}) as revenue
        ")
        ->groupBy('month', 'month_num')
        ->orderBy('month')
        ->get();

    // ── 3. Lifetime stats ──────────────────────────────────────────────────
    $lifetimeStats = DB::table('order_items as oi')
        ->join('orders as o', 'o.id', '=', 'oi.order_id')
        ->where('oi.product_id', $request->product_id)
        ->whereIn('o.status', ['completed', 'delivered'])
        ->selectRaw("
            SUM(oi.quantity)             as total_units,
            SUM({$totalExpr})            as total_revenue,
            COUNT(DISTINCT oi.order_id)  as total_orders
        ")
        ->first();

    // ── 4. Category-level seasonal intelligence ────────────────────────────
    // For THIS season, how did all products in the same category perform
    // vs. their own average? This gives us a real market-wide multiplier.
    $categoryId = DB::table('products')->where('id', $request->product_id)->value('category_id');

    // Map season slug → calendar month numbers it covers
    $seasonMonths = [
        'summer'         => [6, 7, 8],
        'winter'         => [12, 1, 2],
        'spring'         => [3, 4, 5],
        'autumn'         => [9, 10, 11],
        'ramadan'        => [],   // Variable — use label only
        'eid_al_fitr'    => [],
        'eid_al_adha'    => [],
        'back_to_school' => [8, 9],
        'new_year'       => [12, 1],
        'all_seasons'    => [],
    ];

    $seasonMonthNums = $seasonMonths[$season] ?? [];
    $categorySeasonMultiplier = 1.0;
    $categorySeasonDataPoints = 0;

    if (!empty($seasonMonthNums) && $categoryId) {
        // Average monthly units for same-category products IN season months
        $seasonAvg = DB::table('order_items as oi')
            ->join('orders as o',    'o.id',  '=', 'oi.order_id')
            ->join('products as p',  'p.id',  '=', 'oi.product_id')
            ->where('p.category_id', $categoryId)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->whereIn(DB::raw('MONTH(o.created_at)'), $seasonMonthNums)
            ->where('o.created_at', '>=', Carbon::now()->subMonths(24))
            ->selectRaw("AVG(oi.quantity) as avg_qty, COUNT(*) as cnt")
            ->first();

        // Average monthly units for same-category products OUT of season
        $offAvg = DB::table('order_items as oi')
            ->join('orders as o',    'o.id',  '=', 'oi.order_id')
            ->join('products as p',  'p.id',  '=', 'oi.product_id')
            ->where('p.category_id', $categoryId)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->whereNotIn(DB::raw('MONTH(o.created_at)'), $seasonMonthNums)
            ->where('o.created_at', '>=', Carbon::now()->subMonths(24))
            ->selectRaw("AVG(oi.quantity) as avg_qty, COUNT(*) as cnt")
            ->first();

        $seasonQty = (float)($seasonAvg->avg_qty ?? 0);
        $offQty    = (float)($offAvg->avg_qty    ?? 0);
        $categorySeasonDataPoints = (int)($seasonAvg->cnt ?? 0);

        if ($offQty > 0 && $seasonQty > 0) {
            $categorySeasonMultiplier = round($seasonQty / $offQty, 3);
        }
    }

    // ── 5. Same-season products in category — how do they sell? ───────────
    // Find products in the same category declared with the same season,
    // and compute their average monthly sales. This is the strongest signal.
    $sameSeasonCategoryStats = DB::table('order_items as oi')
        ->join('orders as o',   'o.id',  '=', 'oi.order_id')
        ->join('products as p', 'p.id',  '=', 'oi.product_id')
        ->where('p.category_id', $categoryId)
        ->where('p.season', $season)
        ->where('p.id', '!=', $request->product_id)
        ->whereIn('o.status', ['completed', 'delivered'])
        ->where('o.created_at', '>=', Carbon::now()->subMonths(12))
        ->selectRaw("
            COUNT(DISTINCT p.id) as product_count,
            AVG(oi.quantity)     as avg_qty_per_item,
            SUM(oi.quantity)     as total_qty,
            COUNT(DISTINCT DATE_FORMAT(o.created_at, '%Y-%m')) as active_months
        ")
        ->first();

    $sameSeasonProducts    = (int)($sameSeasonCategoryStats->product_count ?? 0);
    $sameSeasonAvgMonthly  = 0.0;
    if ($sameSeasonCategoryStats && (int)$sameSeasonCategoryStats->active_months > 0) {
        $sameSeasonAvgMonthly = round(
            (float)$sameSeasonCategoryStats->total_qty / (int)$sameSeasonCategoryStats->active_months,
            1
        );
    }

    // ── 6. Variant intelligence ────────────────────────────────────────────
    $variantSummary = DB::table('product_variants as pv')
        ->where('pv.product_id', $request->product_id)
        ->selectRaw("
            COUNT(*)                                                  as total_variants,
            SUM(CASE WHEN pv.is_active = 1 THEN 1 ELSE 0 END)        as active_variants,
            SUM(pv.stock)                                             as total_variant_stock,
            MIN(COALESCE(pv.price_override, {$product->price}))       as price_min,
            MAX(COALESCE(pv.price_override, {$product->price}))       as price_max
        ")
        ->first();

    $hasVariants   = (int)($variantSummary->total_variants ?? 0) > 0;
    $activeVarCnt  = (int)($variantSummary->active_variants ?? 0);
    $totalVarCnt   = (int)($variantSummary->total_variants ?? 0);
    $varStockTotal = (int)($variantSummary->total_variant_stock ?? 0);
    $varPriceMin   = round((float)($variantSummary->price_min ?? $product->price), 3);
    $varPriceMax   = round((float)($variantSummary->price_max ?? $product->price), 3);

    // Top-selling variant combos
    $topVariantSales = collect();
    if ($hasVariants) {
        $topVariantSales = DB::table('order_items as oi')
            ->join('orders as o',    'o.id',  '=', 'oi.order_id')
            ->join('product_variants as pv', 'pv.id', '=', 'oi.variant_id')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->join('attributes as a', 'a.id', '=', 'ao.attribute_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->selectRaw("
                oi.variant_id,
                GROUP_CONCAT(DISTINCT CONCAT(a.slug, ':', ao.value) ORDER BY a.order SEPARATOR ' | ') as combo_label,
                SUM(oi.quantity) as units_sold,
                pv.stock as current_stock
            ")
            ->groupBy('oi.variant_id', 'pv.stock')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get();
    }

    // Stock by axis for stockout risk detection
    $variantStockByAxis = collect();
    if ($hasVariants) {
        $variantStockByAxis = DB::table('product_variants as pv')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->join('attributes as a', 'a.id', '=', 'ao.attribute_id')
            ->where('pv.product_id', $request->product_id)
            ->where('pv.is_active', true)
            ->selectRaw("
                a.slug as attr_slug, a.name as attr_name,
                ao.value as option_value, SUM(pv.stock) as stock_for_option
            ")
            ->groupBy('a.slug', 'a.name', 'ao.value')
            ->orderBy('a.order')
            ->orderByDesc('stock_for_option')
            ->get();
    }

    // ── 7. INFO ATTRIBUTES (brand, material, gender…) ─────────────────────
    $infoAttributes = DB::table('product_attribute_values as pav')
        ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
        ->where('pav.product_id', $request->product_id)
        ->selectRaw("a.slug, a.name, a.type, pav.value")
        ->get();

    $allOptionIds = [];
    foreach ($infoAttributes as $attr) {
        if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
            $decoded = json_decode($attr->value, true);
            if (is_array($decoded)) $allOptionIds = array_merge($allOptionIds, $decoded);
            elseif (is_numeric($attr->value)) $allOptionIds[] = (int)$attr->value;
        }
    }
    $optionLabels = [];
    if (!empty($allOptionIds)) {
        $optionLabels = DB::table('attribute_options')
            ->whereIn('id', array_unique($allOptionIds))
            ->pluck('value', 'id')->toArray();
    }
    $infoAttrLines = [];
    foreach ($infoAttributes as $attr) {
        if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
            $decoded = json_decode($attr->value, true);
            $ids     = is_array($decoded) ? $decoded : (is_numeric($attr->value) ? [(int)$attr->value] : []);
            $labels  = array_filter(array_map(fn($id) => $optionLabels[$id] ?? null, $ids));
            if (!empty($labels)) $infoAttrLines[$attr->slug] = implode(', ', $labels);
        } elseif (!empty($attr->value)) {
            $infoAttrLines[$attr->slug] = $attr->value;
        }
    }

    // ── 8. STATISTICAL ENGINE — all numbers computed here, never by AI ────

    // 8a. Basic monthly metrics
    $avgMonthlySales  = $monthlySales->isNotEmpty()
        ? round($monthlySales->avg('units'), 1)
        : 0.0;
    $lastMonthSales   = (int)($monthlySales->last()?->units   ?? 0);
    $lastMonthRevenue = round((float)($monthlySales->last()?->revenue ?? 0), 3);
    $totalUnits       = (int)($lifetimeStats->total_units   ?? 0);
    $totalRevenue     = round((float)($lifetimeStats->total_revenue ?? 0), 3);
    $totalOrders      = (int)($lifetimeStats->total_orders  ?? 0);
    $convRate         = ($product->views > 0 && $totalOrders > 0)
        ? round(($totalOrders / $product->views) * 100, 2)
        : 0.0;

    // 8b. Momentum: slope of last 3 months
    $recentMonths = $monthlySales->take(-3)->values();
    $momentum = 'stable';
    if ($recentMonths->count() >= 2) {
        $first = (int)$recentMonths->first()->units;
        $last  = (int)$recentMonths->last()->units;
        $delta = $last - $first;
        if ($delta > max(1, $first * 0.10)) $momentum = 'growing';
        elseif ($delta < -max(1, $first * 0.10)) $momentum = 'declining';
    }

    // 8c. Determine the BASE for prediction:
    // Priority: same-season category avg → own avg → 1
    $baseForPrediction = match(true) {
        $avgMonthlySales > 0  => $avgMonthlySales,
        $sameSeasonAvgMonthly > 0 => $sameSeasonAvgMonthly,
        default => 1.0,
    };

    // 8d. Season multiplier — real data first, fallback to curated Tunisia table
    $seasonMultiplierTable = [
        'ramadan'        => 1.40,
        'eid_al_fitr'    => 1.35,
        'eid_al_adha'    => 1.28,
        'back_to_school' => 1.22,
        'new_year'       => 1.18,
        'summer'         => 1.08,
        'winter'         => 1.10,
        'spring'         => 1.05,
        'autumn'         => 1.03,
        'all_seasons'    => 1.0,
    ];

    // Use real category data if we have enough samples (≥10 data points)
    $seasonMultiplier = ($categorySeasonDataPoints >= 10 && $categorySeasonMultiplier > 0)
        ? $categorySeasonMultiplier
        : ($seasonMultiplierTable[$season] ?? 1.0);

    // 8e. Momentum adjustment (±10%)
    $momentumFactor = match($momentum) {
        'growing'  => 1.10,
        'declining'=> 0.90,
        default    => 1.0,
    };

    // 8f. Stock constraint — never predict more than stock can fulfil
    $currentStock = $hasVariants ? $varStockTotal : (int)$product->stock;

    // 8g. PREDICTED UNITS — fully deterministic
    $rawPrediction  = $baseForPrediction * $seasonMultiplier * $momentumFactor;
    $predictedUnits = (int)round(max(1, $rawPrediction));
    $predictedUnits = min($predictedUnits, $currentStock > 0 ? (int)round($currentStock * 1.5) : PHP_INT_MAX);

    // 8h. Growth % vs own average
    $growthPct = $avgMonthlySales > 0
        ? round((($predictedUnits - $avgMonthlySales) / $avgMonthlySales) * 100, 1)
        : round(($seasonMultiplier - 1) * 100, 1);

    // 8i. Trend direction
    $trend = match(true) {
        $growthPct > 5  => 'up',
        $growthPct < -5 => 'down',
        default         => 'stable',
    };

    // 8j. Confidence — based on data richness
    $confidence = match(true) {
        $totalOrders >= 20 && $categorySeasonDataPoints >= 10 => 'high',
        $totalOrders >= 5  || $categorySeasonDataPoints >= 5  => 'medium',
        default                                                => 'low',
    };

    // 8k. Stock recommendation
    $safetyBuffer   = 1.30;
    $stockRec       = (int)round($predictedUnits * $safetyBuffer);
    $stockShortfall = max(0, $stockRec - $currentStock);

    // 8l. Weekly breakdown (real distribution pattern)
    // Seasonal demand curve: builds up in week 2, peaks week 3 for most seasons
    $weeklyWeights = match($season) {
        'ramadan'        => [0.18, 0.30, 0.32, 0.20],
        'eid_al_fitr'    => [0.15, 0.35, 0.35, 0.15],
        'eid_al_adha'    => [0.20, 0.30, 0.30, 0.20],
        'back_to_school' => [0.25, 0.30, 0.28, 0.17],
        default          => [0.23, 0.27, 0.27, 0.23],
    };
    $weeklyBreakdown = [];
    $baselineWeekly  = max(1, (int)round($avgMonthlySales / 4));
    foreach ($weeklyWeights as $i => $w) {
        $weeklyBreakdown[] = [
            'week'      => 'Week ' . ($i + 1),
            'predicted' => max(1, (int)round($predictedUnits * $w)),
            'baseline'  => $baselineWeekly,
        ];
    }
    $bestSellingWeek = $weeklyBreakdown[
        array_search(max(array_column($weeklyBreakdown, 'predicted')), array_column($weeklyBreakdown, 'predicted'))
    ]['week'];

    // 8m. Risk factors — stockout detection
    $riskFactors = [];
    if ($stockShortfall > 0) {
        $riskFactors[] = "Stock shortfall: you need {$stockRec} units but have {$currentStock} — restock {$stockShortfall} units before {$season} starts.";
    }
    if ($topVariantSales->isNotEmpty()) {
        $topVariant = $topVariantSales->first();
        if ((int)$topVariant->current_stock < (int)round($predictedUnits * 0.3)) {
            $riskFactors[] = "Top variant '{$topVariant->combo_label}' has only {$topVariant->current_stock} units — likely stockout in week 2 given {$predictedUnits} predicted orders.";
        }
    }
    if (empty($riskFactors)) {
        $riskFactors[] = $currentStock >= $stockRec
            ? "Stock level ({$currentStock} units) is sufficient for predicted demand."
            : "Monitor stock weekly — demand may spike unexpectedly during peak.";
    }

    // 8n. Promotion ideas — variant-aware
    $promotionIdeas = [];
    if ($topVariantSales->isNotEmpty()) {
        $bottomVariant = $topVariantSales->last();
        $promotionIdeas[] = "Run a {$season} flash sale on '{$bottomVariant->combo_label}' — it has low sales but likely adequate stock.";
    } else {
        $promotionIdeas[] = "Launch a {$season} promo with 8-12% discount in week 2 when demand peaks.";
    }
    $promotionIdeas[] = "Boost your ChooseTounsi sponsorship placement 5 days before {$season} starts.";
    $promotionIdeas[] = $sameSeasonProducts > 0
        ? "Bundle with other {$season}-season products in your store — {$sameSeasonProducts} similar products exist."
        : "Cross-sell with complementary products from your store to increase basket size.";

    // ── 9. AI NARRATION — Groq only writes human sentences, no numbers ────
    // We send all pre-computed values. Groq fills in natural language only.
    $subLabel      = $product->subcategory_name ?? $product->category_name;
    $genderLabel   = $infoAttrLines['gender']   ?? null;
    $brandLabel    = $infoAttrLines['brand']     ?? null;
    $materialLabel = $infoAttrLines['material']  ?? null;
    $attrContext   = implode(', ', array_filter([$genderLabel, $brandLabel, $materialLabel]));

    $topVariantStr = $topVariantSales->isNotEmpty()
        ? $topVariantSales->map(fn($v) => "'{$v->combo_label}' ({$v->units_sold} sold, {$v->current_stock} in stock)")->implode('; ')
        : 'no variant sales data';

    $dataSourceLabel = $categorySeasonDataPoints >= 10
        ? "real category data ({$categorySeasonDataPoints} order samples)"
        : "Tunisia market baseline";

    $systemPrompt =
        "You are a Tunisian e-commerce analyst writing brief, precise insights for sellers.\n"
        . "ALL numeric predictions are already computed. Your ONLY job is:\n"
        . "  1. Write key_factor: 1-2 sentences explaining WHY this specific product/season combination predicts {$predictedUnits} units.\n"
        . "  2. Write advice: 1 concrete sentence with the single most important action.\n"
        . "  3. Write opportunity: 1 sentence about the best untapped upside.\n"
        . "RULES:\n"
        . "  - Do NOT invent or change any numbers. Use only what's given.\n"
        . "  - Reference subcategory '{$subLabel}' and season '{$season}' explicitly.\n"
        . "  - If attrContext is set, weave it in naturally.\n"
        . "  - Respond ONLY with valid JSON. No markdown. No extra fields.";

    $historyStr = $monthlySales->isNotEmpty()
        ? $monthlySales->take(-6)->map(fn($r) => "{$r->month}: {$r->units} units")->implode(', ')
        : 'no sales history';

    $userPrompt  = "Product: {$product->name}\n"
        . "Subcategory: {$subLabel}" . ($attrContext ? " ({$attrContext})" : '') . "\n"
        . "Declared season: {$season}\n"
        . "Season multiplier source: {$dataSourceLabel} → ×{$seasonMultiplier}\n"
        . "Momentum: {$momentum} (factor: ×{$momentumFactor})\n"
        . "Own avg monthly sales: {$avgMonthlySales} units\n"
        . "PREDICTED: {$predictedUnits} units | Growth: {$growthPct}% | Trend: {$trend}\n"
        . "Top variants: {$topVariantStr}\n"
        . "Last 6 months: {$historyStr}\n\n"
        . "Return ONLY this JSON:\n"
        . "{\n"
        . "  \"key_factor\": \"<1-2 sentences>\",\n"
        . "  \"advice\": \"<1 concrete action>\",\n"
        . "  \"opportunity\": \"<1 sentence>\"\n"
        . "}";

    $aiNarration = ['key_factor' => null, 'advice' => null, 'opportunity' => null];

    $aiRaw = $this->callGroq($systemPrompt, $userPrompt, 300);
    if ($aiRaw) {
        try {
            $clean = preg_replace('/```json|```/i', '', $aiRaw);
            $start = strpos($clean, '{');
            $end   = strrpos($clean, '}');
            if ($start !== false && $end !== false) {
                $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
                if ($parsed) $aiNarration = $parsed;
            }
        } catch (\Throwable $e) {
            Log::warning('[SellerAI::salesPredictor] Narration parse failed: ' . $e->getMessage());
        }
    }

    // Fallback narration if Groq fails
    if (empty($aiNarration['key_factor'])) {
        $aiNarration['key_factor'] = "{$season} drives a {$seasonMultiplier}× multiplier for {$subLabel}"
            . ($attrContext ? " ({$attrContext})" : '')
            . " based on {$dataSourceLabel}. Momentum is {$momentum}.";
    }
    if (empty($aiNarration['advice'])) {
        $aiNarration['advice'] = $stockShortfall > 0
            ? "Restock {$stockShortfall} units before {$season} begins — current stock cannot meet predicted demand."
            : "Maintain current stock and activate your ChooseTounsi sponsored listing 5 days before {$season} starts.";
    }
    if (empty($aiNarration['opportunity'])) {
        $aiNarration['opportunity'] = $topVariantSales->isNotEmpty()
            ? "Your top variant drives most sales — ensure it has priority restock before {$season}."
            : "First {$season} with this product — a competitive introductory price will build sales history fast.";
    }

    // ── 10. Assemble final result ──────────────────────────────────────────
    $aiResult = [
        // Deterministic numbers — never from AI
        'predicted_units'     => $predictedUnits,
        'growth_pct'          => $growthPct,
        'trend'               => $trend,
        'confidence'          => $confidence,
        'stock_recommendation'=> (string)$stockRec,
        'best_selling_week'   => $bestSellingWeek,
        'weekly_breakdown'    => $weeklyBreakdown,
        'risk_factors'        => $riskFactors,
        'promotion_ideas'     => $promotionIdeas,

        // AI narration — sentences only, no numbers invented
        'key_factor'          => $aiNarration['key_factor'],
        'advice'              => $aiNarration['advice'],
        'opportunity'         => $aiNarration['opportunity'],
    ];

    // Stock by axis for frontend
    $stockByAxisForFrontend = [];
    if ($variantStockByAxis->isNotEmpty()) {
        foreach ($variantStockByAxis->groupBy('attr_slug') as $slug => $options) {
            $stockByAxisForFrontend[$options->first()->attr_name] = $options
                ->map(fn($o) => ['value' => $o->option_value, 'stock' => (int)$o->stock_for_option])
                ->values()->toArray();
        }
    }

    return response()->json([
        'success' => true,
        'data'    => [
            'ai_result'    => $aiResult,
            'data_context' => [
                'product_name'              => $product->name,
                'season'                    => $season,
                'season_label'              => \App\Models\Product::SEASONS[$season] ?? $season,
                'avg_monthly_sales'         => $avgMonthlySales,
                'last_month_sales'          => $lastMonthSales,
                'last_month_revenue'        => $lastMonthRevenue,
                'monthly_history'           => $monthlySales,
                'current_stock'             => $currentStock,
                'stock_shortfall'           => $stockShortfall,
                'total_units'               => $totalUnits,
                'total_revenue'             => $totalRevenue,
                'momentum'                  => $momentum,
                'views'                     => (int)$product->views,
                'conversion_rate'           => $convRate,
                'subcategory'               => $product->subcategory_name,
                'has_variants'              => $hasVariants,
                'active_variants'           => $activeVarCnt,
                'total_variants'            => $totalVarCnt,
                'variant_price_min'         => $hasVariants ? $varPriceMin : null,
                'variant_price_max'         => $hasVariants ? $varPriceMax : null,
                'top_variant_sales'         => $topVariantSales->map(fn($v) => [
                    'combo'        => $v->combo_label,
                    'units_sold'   => (int)$v->units_sold,
                    'current_stock'=> (int)$v->current_stock,
                ])->values()->toArray(),
                'stock_by_axis'             => $stockByAxisForFrontend,
                'info_attributes'           => $infoAttrLines,
                // Algorithm transparency
                'algorithm'                 => [
                    'base_monthly'              => $baseForPrediction,
                    'season_multiplier'         => $seasonMultiplier,
                    'season_multiplier_source'  => $categorySeasonDataPoints >= 10 ? 'real_data' : 'baseline_table',
                    'category_season_samples'   => $categorySeasonDataPoints,
                    'same_season_products'      => $sameSeasonProducts,
                    'momentum_factor'           => $momentumFactor,
                    'formula'                   => "{$baseForPrediction} × {$seasonMultiplier} × {$momentumFactor} = {$predictedUnits}",
                ],
            ],
        ],
    ]);
}

    // ═══════════════════════════════════════════════════════════════════════
    // 3. DESCRIPTION GENERATOR — UNCHANGED
    // ═══════════════════════════════════════════════════════════════════════
    public function descriptionGenerator(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'tone'       => 'nullable|in:professional,casual,exciting,trust-focused',
            'language'   => 'nullable|in:en,fr,ar',
        ]);

        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();

        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $request->product_id)
            ->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.name, p.price, p.stock, p.description, p.short_description, p.sku, c.name as category_name, s.name as subcategory_name")
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $attributes = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('pav.product_id', $request->product_id)
            ->selectRaw("a.name as attr_name, pav.value")
            ->get()
            ->map(fn($r) => "{$r->attr_name}: {$r->value}")
            ->implode(', ');

        $salesCount = DB::table('order_items')->where('product_id', $request->product_id)->sum('quantity');

        $tone     = $request->input('tone', 'professional');
        $language = $request->input('language', 'fr');

        $langInstructions = match($language) {
            'ar'    => 'Write the title, description, and meta in Arabic (Modern Standard Arabic). Keep keywords in English.',
            'fr'    => 'Write in French. Title, description, and meta in French. Keep keywords in both French and Tunisian transliterations.',
            default => 'Write in English. Optimize for international and Tunisian diaspora buyers.',
        };

        $systemPrompt = "You are an expert SEO copywriter for ChooseTounsi Tunisian e-commerce marketplace.\n{$langInstructions}\nTone: {$tone}. Write compelling product content that converts Tunisian buyers.\nALWAYS respond with ONLY valid JSON. No markdown. No text outside JSON.";

        $userPrompt = "Generate SEO-optimized product content for this ChooseTounsi listing:\n\nPRODUCT:\n- Name: {$product->name}\n- Category: {$product->category_name}\n- Subcategory: {$product->subcategory_name}\n- Price: {$product->price} TND\n- SKU: {$product->sku}\n- Current description: {$product->description}\n- Attributes: {$attributes}\n- Total units sold: {$salesCount}\n\nRespond with ONLY this JSON:\n{\n  \"title\": \"<optimized product title, max 80 chars>\",\n  \"short_description\": \"<compelling hook, 1-2 sentences, max 160 chars>\",\n  \"description\": \"<full SEO description, 150-250 words, include benefits and Tunisian context>\",\n  \"keywords\": [\"<kw1>\",\"<kw2>\",\"<kw3>\",\"<kw4>\",\"<kw5>\",\"<kw6>\"],\n  \"meta_title\": \"<meta title max 60 chars>\",\n  \"meta_description\": \"<meta description max 160 chars>\",\n  \"call_to_action\": \"<one compelling CTA sentence>\"\n}";

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 700);
        $aiResult = null;

        if ($aiRaw) {
            try {
                $clean = preg_replace('/```json|```/i', '', $aiRaw);
                $start = strpos($clean, '{');
                $end   = strrpos($clean, '}');
                if ($start !== false && $end !== false) {
                    $aiResult = json_decode(substr($clean, $start, $end - $start + 1), true);
                }
            } catch (\Throwable $e) {}
        }

        if (!$aiResult) {
            $cat = $product->category_name ?? 'produit';
            $aiResult = [
                'title'             => "{$product->name} — {$cat} authentique tunisien",
                'short_description' => "Découvrez {$product->name}, un incontournable de la catégorie {$cat}. Livraison rapide partout en Tunisie.",
                'description'       => "Faites confiance à {$product->name} pour répondre à vos besoins quotidiens. Ce produit de qualité dans la catégorie {$cat} est conçu pour les consommateurs tunisiens exigeants. ".($attributes?"Caractéristiques: {$attributes}. ":'')."Disponible en stock avec livraison express. Commandez maintenant sur ChooseTounsi et recevez votre commande rapidement. Qualité garantie ou remboursé.",
                'keywords'          => ['tunisien','artisanal',$cat,'choosetounsi','livraison','qualité','authentique','meilleur prix'],
                'meta_title'        => "{$product->name} | {$cat} Tunisie — ChooseTounsi",
                'meta_description'  => "Achetez {$product->name} sur ChooseTounsi. Qualité premium, livraison rapide en Tunisie. Prix: {$product->price} TND.",
                'call_to_action'    => "Commandez maintenant et recevez votre colis sous 24-48h partout en Tunisie!",
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    'product_name'        => $product->name,
                    'category'            => $product->category_name,
                    'current_description' => $product->description,
                    'attributes'          => $attributes,
                    'units_sold'          => (int)$salesCount,
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4. QUICK DESCRIPTION — UNCHANGED
    // ═══════════════════════════════════════════════════════════════════════
    public function quickDescription(Request $request)
    {
        $request->validate([
            'name'              => 'required|string|max:255',
            'category'          => 'nullable|string|max:100',
            'price'             => 'nullable|numeric|min:0',
            'short_description' => 'nullable|string|max:500',
            'attributes'        => 'nullable|array',
            'variants'          => 'nullable|array',
            'image_count'       => 'nullable|integer|min:0',
            'tone'              => 'nullable|in:professional,casual,exciting,trust-focused',
            'language'          => 'nullable|in:en,fr,ar',
        ]);

        $name       = trim($request->name);
        $category   = trim($request->input('category', 'General')) ?: 'General';
        $price      = (float) $request->input('price', 0);
        $shortDesc  = trim($request->input('short_description', ''));
        $attributes = (array) $request->input('attributes', []);
        $variants   = array_slice((array) $request->input('variants', []), 0, 10);
        $imageCount = (int) $request->input('image_count', 0);
        $tone       = $request->input('tone', 'professional');
        $language   = $request->input('language', 'fr');

        $priceLabel = match (true) {
            $price >= 500 => 'Luxury / Ultra-premium',
            $price >= 200 => 'Premium / High-end',
            $price >= 80  => 'Mid-range / Quality',
            $price >= 30  => 'Value / Accessible',
            default       => 'Budget-friendly',
        };

        $attrParts = [];
        foreach ($attributes as $slug => $val) {
            if ($val !== null && $val !== '') {
                $attrParts[] = ucfirst(str_replace('_', ' ', (string) $slug)) . ': ' . $val;
            }
        }
        $attrStr    = implode(' | ', $attrParts);
        $variantStr = !empty($variants) ? implode(', ', $variants) : '';

        $langInstruction = match ($language) {
            'ar'    => 'Write title, short_description, description, meta_title, meta_description in Arabic (Modern Standard Arabic). Keep keywords in English.',
            'fr'    => 'Write title, short_description, description, meta_title, meta_description in French.',
            default => 'Write everything in English. Optimize for Tunisian diaspora and international buyers.',
        };

        $toneInstruction = match ($tone) {
            'casual'        => 'Friendly, conversational, relatable. Simple sentences, everyday language.',
            'exciting'      => 'High energy, bold, enthusiastic. Create urgency and strong desire.',
            'trust-focused' => 'Emphasize reliability, quality guarantees, social proof, and after-sales support.',
            default         => 'Clear, authoritative, benefits-first. Professional and credible.',
        };

        $contextLines = array_filter([
            "PRODUCT NAME: {$name}",
            "CATEGORY: {$category}",
            $price > 0    ? "PRICE: {$price} TND  →  Positioning: {$priceLabel}" : null,
            $imageCount   ? "PRODUCT PHOTOS: {$imageCount} image(s) provided"     : null,
            $variantStr   ? "AVAILABLE VARIANTS: {$variantStr}"                    : null,
            $attrStr      ? "PRODUCT ATTRIBUTES: {$attrStr}"                       : null,
            $shortDesc    ? "SELLER DRAFT (improve this): {$shortDesc}"            : null,
        ]);
        $context = implode("\n", $contextLines);

        $systemPrompt = "You are an expert SEO copywriter for ChooseTounsi, Tunisia's leading multi-vendor e-commerce marketplace.\n{$langInstruction}\nTone: {$toneInstruction}\nALWAYS structure the description as: Hook → Value Proposition → Key Features/Benefits → Trust Elements → Call to Action.\nUse the product data provided to write something SPECIFIC, not generic.\nALWAYS respond with ONLY valid JSON. No markdown fences. No text outside the JSON object.";

        $userPrompt = "Generate a high-conversion, SEO-optimized product listing for ChooseTounsi:\n\n{$context}\n\nRequirements:\n- Highlight BENEFITS, not just features\n- Use specific product details (variants, attributes, price positioning)\n- Include Tunisian market context (local relevance, delivery trust, Tunisian buyer habits)\n- Strictly follow the tone and language specified\n- Description must feel written for THIS specific product, not a generic template\n\nRespond with ONLY this JSON (no extra text):\n{\n  \"title\": \"<optimized product title, max 80 chars>\",\n  \"short_description\": \"<compelling hook sentence, 1-2 sentences, max 160 chars>\",\n  \"description\": \"<full structured description, 180-280 words, paragraphs not bullets>\",\n  \"keywords\": [\"<kw1>\",\"<kw2>\",\"<kw3>\",\"<kw4>\",\"<kw5>\",\"<kw6>\"],\n  \"meta_title\": \"<SEO meta title, max 60 chars>\",\n  \"meta_description\": \"<SEO meta description, max 160 chars>\",\n  \"call_to_action\": \"<one powerful CTA tailored to Tunisian buyers>\"\n}";

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 800);
        $aiResult = null;

        if ($aiRaw) {
            try {
                $clean = preg_replace('/```json|```/i', '', $aiRaw);
                $start = strpos($clean, '{');
                $end   = strrpos($clean, '}');
                if ($start !== false && $end !== false) {
                    $aiResult = json_decode(substr($clean, $start, $end - $start + 1), true);
                }
            } catch (\Throwable $e) {
                Log::warning('[SellerAI::quickDescription] Parse failed: ' . $e->getMessage());
            }
        }

        if (!$aiResult) {
            $variantNote = $variantStr ? " Disponible en: {$variantStr}." : '';
            $attrNote    = $attrStr    ? " Caractéristiques: {$attrStr}." : '';
            $aiResult    = [
                'title'             => "{$name} — {$category} sur ChooseTounsi",
                'short_description' => $shortDesc ?: "Découvrez {$name}, disponible sur ChooseTounsi. Livraison rapide partout en Tunisie.",
                'description'       => "Faites confiance à {$name} pour répondre à vos besoins quotidiens. Ce produit de qualité dans la catégorie {$category} est conçu pour les consommateurs tunisiens exigeants.{$variantNote}{$attrNote} Disponible avec livraison express. Commandez maintenant sur ChooseTounsi.",
                'keywords'          => ['tunisien',strtolower($category),'choosetounsi','livraison','qualité','authentique'],
                'meta_title'        => "{$name} | {$category} — ChooseTounsi",
                'meta_description'  => "Achetez {$name} sur ChooseTounsi. {$priceLabel}. Livraison rapide en Tunisie.",
                'call_to_action'    => "Commandez maintenant et recevez votre colis sous 24-48h partout en Tunisie!",
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => compact('name', 'category', 'tone', 'language', 'priceLabel'),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5. RECOMMENDER — UNCHANGED
    // ═══════════════════════════════════════════════════════════════════════
    public function recommender(Request $request)
    {
        $request->validate([
            'product_id'   => 'required|integer',
            'mode'         => 'nullable|in:bundle,related',
            'discount_pct' => 'nullable|integer|min:1|max:50',
        ]);

        $sellerId    = auth()->id();
        $sellerCol   = $this->sellerCol();
        $totalExpr   = $this->totalExpr();
        $mode        = $request->input('mode', 'bundle');
        $discountPct = $request->input('discount_pct', 10);

        $mainProduct = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $request->product_id)
            ->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.name, p.price, p.category_id, c.name as category_name")
            ->first();

        if (!$mainProduct) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $ordersWithMain = DB::table('order_items')->where('product_id', $mainProduct->id)->pluck('order_id');

        $coPurchased = collect();
        if ($ordersWithMain->isNotEmpty()) {
            $coPurchased = DB::table('order_items as oi')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereIn('oi.order_id', $ordersWithMain)
                ->where('oi.product_id', '!=', $mainProduct->id)
                ->where("p.{$sellerCol}", $sellerId)
                ->whereNull('p.deleted_at')
                ->where('p.is_active', true)
                ->selectRaw("p.id, p.name, p.price, c.name as category_name, COUNT(*) as co_count")
                ->groupBy('p.id','p.name','p.price','c.name')
                ->orderByDesc('co_count')
                ->limit(10)
                ->get();
        }

        $sameCategoryProducts = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.category_id', $mainProduct->category_id)
            ->where('p.id', '!=', $mainProduct->id)
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->selectRaw("p.id, p.name, p.price, c.name as category_name")
            ->limit(8)
            ->get();

        $allProductIds = collect([$mainProduct->id])
            ->merge($coPurchased->pluck('id'))
            ->merge($sameCategoryProducts->pluck('id'))
            ->unique()->values()->toArray();

        $rawImages = DB::table('product_images')
            ->whereIn('product_id', $allProductIds)
            ->select('product_id','variant_id','image_path','is_primary','order','id')
            ->orderByRaw('product_id ASC, is_primary DESC, `order` ASC, id ASC')
            ->get()
            ->groupBy('product_id');

        $imageUrlById = [];
        foreach ($allProductIds as $pid) {
            $rows = $rawImages->get($pid, collect());
            if ($rows->isEmpty()) { $imageUrlById[$pid] = null; continue; }
            $variantImages = $rows->filter(fn($r) => !is_null($r->variant_id));
            $productImages = $rows->filter(fn($r) =>  is_null($r->variant_id));
            $best = $variantImages->isNotEmpty()
                ? ($variantImages->firstWhere('is_primary', true) ?? $variantImages->first())
                : ($productImages->firstWhere('is_primary', true)  ?? $productImages->first());
            $imageUrlById[$pid] = $best ? Storage::url($best->image_path) : null;
        }

        $productImagesByName = [];
        $productImagesByName[$mainProduct->name] = $imageUrlById[$mainProduct->id] ?? null;
        foreach ($coPurchased as $p) { $productImagesByName[$p->name] = $imageUrlById[$p->id] ?? null; }
        foreach ($sameCategoryProducts as $p) { $productImagesByName[$p->name] = $imageUrlById[$p->id] ?? null; }

        $coPurchasedStr   = $coPurchased->map(fn($p) => "{$p->name} ({$p->co_count}x co-purchased)")->implode(', ') ?: 'No co-purchase data yet';
        $categoryStr      = $sameCategoryProducts->pluck('name')->implode(', ') ?: 'No other products in category';
        $otherProductsArr = $coPurchased->isNotEmpty() ? $coPurchased->pluck('name') : $sameCategoryProducts->pluck('name');
        $otherProductsList= $otherProductsArr->implode('", "');

        $systemPrompt = "You are a Tunisian e-commerce bundle strategy expert for ChooseTounsi marketplace.\nSuggest high-converting product bundles and related product recommendations.\nBase suggestions on real purchase affinity data and Tunisian shopping behavior.\nALWAYS respond with ONLY valid JSON. No markdown. No text outside JSON.";

        if ($mode === 'bundle') {
            $userPrompt = "Create bundle recommendations for this ChooseTounsi product:\n\nMAIN PRODUCT: {$mainProduct->name} ({$mainProduct->category_name}) — {$mainProduct->price} TND\n\nCO-PURCHASED (real data): {$coPurchasedStr}\nSAME CATEGORY PRODUCTS: {$categoryStr}\nPROPOSED DISCOUNT: {$discountPct}%\n\nRespond with ONLY this JSON:\n{\n  \"bundles\": [\n    {\n      \"name\": \"<bundle name>\",\n      \"products\": [\"{$mainProduct->name}\", \"{$otherProductsList}\"],\n      \"reason\": \"<why these work together>\",\n      \"est_uplift\": \"<estimated % revenue increase>\",\n      \"discount\": {$discountPct},\n      \"suggested_price_reduction\": \"<discount explanation>\",\n      \"display_label\": \"<short UI badge text e.g. 'Popular Combo'>\"\n    }\n  ]\n}\nInclude 2-3 bundles. Tailor for Tunisian buyers.";
        } else {
            $userPrompt = "Suggest related products and cross-sell opportunities for:\n\nMAIN PRODUCT: {$mainProduct->name} ({$mainProduct->category_name}) — {$mainProduct->price} TND\n\nCO-PURCHASED PRODUCTS: {$coPurchasedStr}\nSAME CATEGORY: {$categoryStr}\n\nRespond with ONLY this JSON:\n{\n  \"recommendations\": [\n    {\n      \"product_name\": \"<name>\",\n      \"reason\": \"<why it's relevant>\",\n      \"placement\": \"also_bought\"|\"similar\"|\"upgrade\"|\"accessory\",\n      \"est_click_rate\": \"<estimated engagement>\"\n    }\n  ],\n  \"placement_strategy\": \"<where to show these recommendations>\",\n  \"best_time_to_show\": \"<when to show them in buyer journey>\"\n}\nInclude 4-6 recommendations.";
        }

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 650);
        $aiResult = null;

        if ($aiRaw) {
            try {
                $clean = preg_replace('/```json|```/i', '', $aiRaw);
                $start = strpos($clean, '{');
                $end   = strrpos($clean, '}');
                if ($start !== false && $end !== false) {
                    $aiResult = json_decode(substr($clean, $start, $end - $start + 1), true);
                }
            } catch (\Throwable $e) {}
        }

        if (!$aiResult) {
            $companions = $coPurchased->isNotEmpty() ? $coPurchased->pluck('name')->toArray() : $sameCategoryProducts->pluck('name')->toArray();
            if ($mode === 'bundle') {
                $aiResult = ['bundles' => [
                    ['name'=>'Starter Pack','products'=>array_slice(array_merge([$mainProduct->name],$companions),0,2),'reason'=>"Customers who bought {$mainProduct->name} frequently also purchase ".($companions[0]??'a complementary item')." within 7 days.",'est_uplift'=>'+'.(15+$discountPct).'%','discount'=>$discountPct,'suggested_price_reduction'=>"{$discountPct}% off when bought together",'display_label'=>'Popular Combo'],
                    ['name'=>'Value Bundle','products'=>array_slice(array_merge([$mainProduct->name],$companions),0,3),'reason'=>"Complete the set — this bundle covers all common use cases for {$mainProduct->category_name} buyers.",'est_uplift'=>'+'.(25+$discountPct).'%','discount'=>$discountPct,'suggested_price_reduction'=>"Save {$discountPct}% on the complete bundle",'display_label'=>'Best Value'],
                ]];
            } else {
                $aiResult = [
                    'recommendations'   => array_map(fn($name)=>['product_name'=>$name,'reason'=>"Co-purchased with {$mainProduct->name} based on real buyer behavior.",'placement'=>'also_bought','est_click_rate'=>'12-18%'],array_slice($companions,0,5)),
                    'placement_strategy'=> 'Show on product detail page under "Customers also bought" section.',
                    'best_time_to_show' => 'After adding to cart and on checkout page.',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    'product_name'   => $mainProduct->name,
                    'co_purchased'   => $coPurchased->take(5)->values(),
                    'same_category'  => $sameCategoryProducts->take(5)->values(),
                    'mode'           => $mode,
                    'product_images' => $productImagesByName,
                ],
            ],
        ]);
    }
}