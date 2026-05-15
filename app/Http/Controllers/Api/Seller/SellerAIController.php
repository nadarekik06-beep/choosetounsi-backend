<?php
// app/Http/Controllers/Api/Seller/SellerAIController.php

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
    private string $groqModel  = 'llama-3.1-8b-instant';


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
                'temperature' => 0.7,
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

        $conversionRate = 0;
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

        $marketReport = ['has_data' => false];
        try {
            $marketSvc    = new \App\Services\MarketIntelligenceService(new \App\Services\PriceNormalizationService());
            $marketReport = $marketSvc->analyze($product->name, $product->category_name ?? 'General', $productPrice);
        } catch (\Throwable $e) {
            Log::warning("[SellerAI::priceOptimizer] Market intelligence failed: " . $e->getMessage());
        }

        $hasMarketData = (bool)($marketReport['has_data'] ?? false);
        $safeMarketAvg = $hasMarketData ? (float)$marketReport['market_avg'] : 0.0;
        $safeCatAvg    = $catAvgPrice > 0 ? $catAvgPrice : 0.0;

        $bestRef = $safeMarketAvg > 0 ? $safeMarketAvg
                 : ($safeCatAvg > 0   ? $safeCatAvg
                 : $productPrice);

        $psycho = static function (float $n): float {
            if ($n <= 1) return $n;
            return floor($n) - 0.100;
        };

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
  "suggested_price": <number>,
  "competitive_price": <number>,
  "premium_price": <number>,
  "min_profitable_price": <number>,
  "market_avg_price": <number>,
  "confidence": "high"|"medium"|"low",
  "risk": "low"|"medium"|"high",
  "strategy": "<strategy name>",
  "reasoning": "<2-3 sentences>",
  "expected_impact": "<one sentence>",
  "market_positioning": "underpriced"|"competitive"|"overpriced",
  "competitor_summary": "<one sentence>",
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
                    ? 'A competitive entry price should attract first buyers on ChooseTounsi.'
                    : 'Aligning with market pricing maintains conversion while optimizing revenue.',
                'market_positioning'   => $positioning,
                'competitor_summary'   => $hasMarketData
                    ? implode(', ', $platformsUsed) . " show {$marketReport['data_points']} listings ranging {$marketReport['market_min']}–{$marketReport['market_max']} TND."
                    : ($safeCatAvg > 0
                        ? "ChooseTounsi shows {$competitorCount} competitors (avg {$safeCatAvg} TND)."
                        : 'No competitor data found.'),
                'overpriced_warning'   => $positioningPct > 15
                    ? "Your price is {$positioningPct}% above market average — consider reducing to improve conversion."
                    : null,
                'opportunity_note'     => $positioningPct < -10
                    ? "Your price is " . abs($positioningPct) . "% below market — you may have room to increase."
                    : ($totalUnits === 0 ? 'No sales yet — ensure listing has complete images.' : null),
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
    // 2. SALES PREDICTOR — UNCHANGED
    // ═══════════════════════════════════════════════════════════════════════
    public function salesPredictor(Request $request)
    {
        $request->validate([
            'product_id'       => 'required|integer',
            'target_seasons'   => 'nullable|array',
            'target_seasons.*' => 'nullable|string',
        ]);

        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        $seasonMonthMap = [
            'summer'         => [6, 7, 8],
            'winter'         => [12, 1, 2],
            'spring'         => [3, 4, 5],
            'autumn'         => [9, 10, 11],
            'ramadan'        => [],
            'eid_al_fitr'    => [],
            'eid_al_adha'    => [],
            'back_to_school' => [8, 9],
            'new_year'       => [12, 1],
            'all_seasons'    => [],
        ];

        $seasonMultiplierTable = [
            'ramadan'        => 1.40,
            'eid_al_fitr'    => 1.35,
            'eid_al_adha'    => 1.28,
            'back_to_school' => 1.22,
            'new_year'       => 1.18,
            'winter'         => 1.10,
            'summer'         => 1.08,
            'spring'         => 1.05,
            'autumn'         => 1.03,
            'all_seasons'    => 1.00,
        ];

        $weeklyWeightMap = [
            'ramadan'        => [0.18, 0.30, 0.32, 0.20],
            'eid_al_fitr'    => [0.15, 0.35, 0.35, 0.15],
            'eid_al_adha'    => [0.20, 0.30, 0.30, 0.20],
            'back_to_school' => [0.25, 0.30, 0.28, 0.17],
            'default'        => [0.23, 0.27, 0.27, 0.23],
        ];

        $product = DB::table('products as p')
            ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $request->product_id)
            ->whereNull('p.deleted_at')
            ->selectRaw("
                p.id, p.name, p.price, p.stock, p.views,
                p.subcategory_id, p.category_id,
                p.season,
                c.name  as category_name,
                s.name  as subcategory_name
            ")
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $rawSeason = $product->season;
        if (is_string($rawSeason)) {
            $decoded = json_decode($rawSeason, true);
            $productSeasons = is_array($decoded) && !empty($decoded)
                ? $decoded
                : ($rawSeason !== '' ? [$rawSeason] : ['all_seasons']);
        } elseif (is_array($rawSeason)) {
            $productSeasons = !empty($rawSeason) ? $rawSeason : ['all_seasons'];
        } else {
            $productSeasons = ['all_seasons'];
        }

        $knownSlugs     = array_keys($seasonMonthMap);
        $productSeasons = array_values(array_filter(
            $productSeasons,
            fn($s) => in_array($s, $knownSlugs, true)
        ));
        if (empty($productSeasons)) {
            $productSeasons = ['all_seasons'];
        }

        $requestedTargets = $request->input('target_seasons', []);
        if (!empty($requestedTargets) && is_array($requestedTargets)) {
            $targetSeasons = array_values(array_filter(
                $requestedTargets,
                fn($s) => in_array($s, $productSeasons, true)
            ));
        }
        if (empty($targetSeasons ?? [])) {
            $targetSeasons = $productSeasons;
        }

        $allSeasonLabels    = \App\Models\Product::SEASONS;
        $targetSeasonLabels = array_map(
            fn($s) => $allSeasonLabels[$s] ?? ucfirst(str_replace('_', ' ', $s)),
            $targetSeasons
        );
        $seasonLabel = implode(' + ', $targetSeasonLabels);

        $primarySeason = collect($targetSeasons)
            ->sortByDesc(fn($s) => $seasonMultiplierTable[$s] ?? 1.0)
            ->first() ?? 'all_seasons';

        $categoryId = $product->category_id;

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

        $lifetimeStats = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->selectRaw("
                SUM(oi.quantity)            as total_units,
                SUM({$totalExpr})           as total_revenue,
                COUNT(DISTINCT oi.order_id) as total_orders
            ")
            ->first();

        $perSeasonData = [];

        foreach ($targetSeasons as $slug) {
            $monthNums      = $seasonMonthMap[$slug] ?? [];
            $realMultiplier = null;
            $realDataPoints = 0;

            if (!empty($monthNums) && $categoryId) {
                $seasonAvg = DB::table('order_items as oi')
                    ->join('orders as o',   'o.id',  '=', 'oi.order_id')
                    ->join('products as p', 'p.id',  '=', 'oi.product_id')
                    ->where('p.category_id', $categoryId)
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereIn(DB::raw('MONTH(o.created_at)'), $monthNums)
                    ->where('o.created_at', '>=', Carbon::now()->subMonths(24))
                    ->selectRaw("AVG(oi.quantity) as avg_qty, COUNT(*) as cnt")
                    ->first();

                $offAvg = DB::table('order_items as oi')
                    ->join('orders as o',   'o.id',  '=', 'oi.order_id')
                    ->join('products as p', 'p.id',  '=', 'oi.product_id')
                    ->where('p.category_id', $categoryId)
                    ->whereIn('o.status', ['completed', 'delivered'])
                    ->whereNotIn(DB::raw('MONTH(o.created_at)'), $monthNums)
                    ->where('o.created_at', '>=', Carbon::now()->subMonths(24))
                    ->selectRaw("AVG(oi.quantity) as avg_qty, COUNT(*) as cnt")
                    ->first();

                $sQty = (float)($seasonAvg->avg_qty ?? 0);
                $oQty = (float)($offAvg->avg_qty    ?? 0);
                $realDataPoints = (int)($seasonAvg->cnt ?? 0);

                if ($sQty > 0 && $oQty > 0) {
                    $realMultiplier = round($sQty / $oQty, 3);
                }
            }

            $sameSeasonStats = DB::table('order_items as oi')
                ->join('orders as o',   'o.id',  '=', 'oi.order_id')
                ->join('products as p', 'p.id',  '=', 'oi.product_id')
                ->where('p.category_id', $categoryId)
                ->where('p.id', '!=', $request->product_id)
                ->whereJsonContains('p.season', $slug)
                ->whereIn('o.status', ['completed', 'delivered'])
                ->where('o.created_at', '>=', Carbon::now()->subMonths(12))
                ->selectRaw("
                    COUNT(DISTINCT p.id)     as product_count,
                    SUM(oi.quantity)         as total_qty,
                    COUNT(DISTINCT DATE_FORMAT(o.created_at, '%Y-%m')) as active_months
                ")
                ->first();

            $sameSeasonMonthlyAvg = 0.0;
            if ($sameSeasonStats && (int)$sameSeasonStats->active_months > 0) {
                $sameSeasonMonthlyAvg = round(
                    (float)$sameSeasonStats->total_qty / (int)$sameSeasonStats->active_months,
                    1
                );
            }

            $perSeasonData[$slug] = [
                'slug'                    => $slug,
                'label'                   => $allSeasonLabels[$slug] ?? $slug,
                'real_multiplier'         => $realMultiplier,
                'real_data_points'        => $realDataPoints,
                'baseline_multiplier'     => $seasonMultiplierTable[$slug] ?? 1.0,
                'effective_multiplier'    => $realDataPoints >= 10 && $realMultiplier !== null
                                              ? $realMultiplier
                                              : ($seasonMultiplierTable[$slug] ?? 1.0),
                'multiplier_source'       => $realDataPoints >= 10 ? 'real_data' : 'baseline_table',
                'same_season_products'    => (int)($sameSeasonStats->product_count ?? 0),
                'same_season_monthly_avg' => $sameSeasonMonthlyAvg,
            ];
        }

        $totalWeight           = 0.0;
        $weightedSum           = 0.0;
        $totalRealDataPoints   = 0;
        $sameSeasonProductsMax = 0;

        foreach ($perSeasonData as $data) {
            $w             = $data['baseline_multiplier'];
            $weightedSum  += $data['effective_multiplier'] * $w;
            $totalWeight  += $w;
            $totalRealDataPoints   += $data['real_data_points'];
            $sameSeasonProductsMax  = max($sameSeasonProductsMax, $data['same_season_products']);
        }

        $finalMultiplier = $totalWeight > 0
            ? round($weightedSum / $totalWeight, 3)
            : 1.0;

        $resilienceBonus = count($productSeasons) >= 3 ? 1.05 : 1.0;
        $finalMultiplier = round($finalMultiplier * $resilienceBonus, 3);

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

        $avgMonthlySales  = $monthlySales->isNotEmpty()
            ? round($monthlySales->avg('units'), 1) : 0.0;
        $lastMonthSales   = (int)($monthlySales->last()?->units   ?? 0);
        $lastMonthRevenue = round((float)($monthlySales->last()?->revenue ?? 0), 3);
        $totalUnits       = (int)($lifetimeStats->total_units   ?? 0);
        $totalRevenue     = round((float)($lifetimeStats->total_revenue ?? 0), 3);
        $totalOrders      = (int)($lifetimeStats->total_orders  ?? 0);
        $convRate         = ($product->views > 0 && $totalOrders > 0)
            ? round(($totalOrders / $product->views) * 100, 2) : 0.0;

        $recentMonths = $monthlySales->take(-3)->values();
        $momentum = 'stable';
        if ($recentMonths->count() >= 2) {
            $first = (int)$recentMonths->first()->units;
            $last  = (int)$recentMonths->last()->units;
            $delta = $last - $first;
            if ($delta > max(1, $first * 0.10)) $momentum = 'growing';
            elseif ($delta < -max(1, $first * 0.10)) $momentum = 'declining';
        }

        $baseForPrediction = match(true) {
            $avgMonthlySales > 0    => $avgMonthlySales,
            $sameSeasonProductsMax > 0 => (float)collect($perSeasonData)->max('same_season_monthly_avg'),
            default                 => 1.0,
        };

        $momentumFactor = match($momentum) {
            'growing'   => 1.10,
            'declining' => 0.90,
            default     => 1.0,
        };

        $currentStock = $hasVariants ? $varStockTotal : (int)$product->stock;

        $rawPrediction  = $baseForPrediction * $finalMultiplier * $momentumFactor;
        $predictedUnits = (int)round(max(1, $rawPrediction));
        $predictedUnits = min(
            $predictedUnits,
            $currentStock > 0 ? (int)round($currentStock * 1.5) : PHP_INT_MAX
        );

        $growthPct = $avgMonthlySales > 0
            ? round((($predictedUnits - $avgMonthlySales) / $avgMonthlySales) * 100, 1)
            : round(($finalMultiplier - 1) * 100, 1);

        $trend = match(true) {
            $growthPct > 5  => 'up',
            $growthPct < -5 => 'down',
            default         => 'stable',
        };

        $confidence = match(true) {
            $totalOrders >= 20 && $totalRealDataPoints >= 10 => 'high',
            $totalOrders >= 5  || $totalRealDataPoints >= 5  => 'medium',
            default                                           => 'low',
        };

        $weeklyWeights  = $weeklyWeightMap[$primarySeason] ?? $weeklyWeightMap['default'];
        $baselineWeekly = max(1, (int)round($avgMonthlySales / 4));
        $weeklyBreakdown = [];
        foreach ($weeklyWeights as $i => $w) {
            $weeklyBreakdown[] = [
                'week'      => 'Week ' . ($i + 1),
                'predicted' => max(1, (int)round($predictedUnits * $w)),
                'baseline'  => $baselineWeekly,
            ];
        }
        $bestSellingWeek = $weeklyBreakdown[
            array_search(
                max(array_column($weeklyBreakdown, 'predicted')),
                array_column($weeklyBreakdown, 'predicted')
            )
        ]['week'];

        $safetyBuffer   = 1.30;
        $stockRec       = (int)round($predictedUnits * $safetyBuffer);
        $stockShortfall = max(0, $stockRec - $currentStock);

        $riskFactors = [];
        if ($stockShortfall > 0) {
            $riskFactors[] = "Stock shortfall: you need {$stockRec} units but have {$currentStock} — restock {$stockShortfall} units before {$seasonLabel} starts.";
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
                : 'Monitor stock weekly — demand may spike unexpectedly during peak.';
        }
        if (count($productSeasons) >= 3) {
            $riskFactors[] = 'Cross-season resilience: this product covers ' . count($productSeasons) . ' seasons — revenue is spread across the year, reducing single-event risk.';
        }

        $promotionIdeas = [];
        if ($topVariantSales->isNotEmpty()) {
            $bottom = $topVariantSales->last();
            $promotionIdeas[] = "Run a {$seasonLabel} flash sale on '{$bottom->combo_label}' — it has low sales but likely adequate stock.";
        } else {
            $promotionIdeas[] = "Launch a {$seasonLabel} promo with 8-12% discount in week 2 when demand peaks.";
        }
        $promotionIdeas[] = "Boost your ChooseTounsi sponsorship placement 5 days before {$seasonLabel} starts.";
        $promotionIdeas[] = count($targetSeasons) > 1
            ? 'Market this product for both ' . implode(' and ', $targetSeasonLabels) . ' — cross-season listings attract 22% more clicks on average.'
            : ($sameSeasonProductsMax > 0
                ? "Bundle with other {$seasonLabel}-season products — {$sameSeasonProductsMax} similar products exist in this category."
                : 'Cross-sell with complementary products to increase basket size.');

        $subLabel      = $product->subcategory_name ?? $product->category_name;
        $genderLabel   = $infoAttrLines['gender']   ?? null;
        $brandLabel    = $infoAttrLines['brand']     ?? null;
        $materialLabel = $infoAttrLines['material']  ?? null;
        $attrContext   = implode(', ', array_filter([$genderLabel, $brandLabel, $materialLabel]));

        $topVariantStr = $topVariantSales->isNotEmpty()
            ? $topVariantSales->map(fn($v) => "'{$v->combo_label}' ({$v->units_sold} sold, {$v->current_stock} in stock)")->implode('; ')
            : 'no variant sales data';

        $multiplierSourceLabel = $totalRealDataPoints >= 10
            ? "real category data ({$totalRealDataPoints} order samples)"
            : 'Tunisia market baseline';

        $multiSeasonNote = count($targetSeasons) > 1
            ? " This is a multi-season forecast ({$seasonLabel}) — the combined multiplier is {$finalMultiplier}×."
            : '';

        $systemPrompt =
            "You are a Tunisian e-commerce analyst writing brief, precise insights for sellers.\n"
            . "ALL numeric predictions are already computed. Your ONLY job is:\n"
            . "  1. key_factor: 1-2 sentences explaining WHY this product/season combination predicts {$predictedUnits} units.\n"
            . "  2. advice: 1 concrete sentence with the single most important action.\n"
            . "  3. opportunity: 1 sentence about the best untapped upside.\n"
            . "RULES:\n"
            . "  - Do NOT invent or change any numbers. Use only what's given.\n"
            . "  - Reference subcategory '{$subLabel}' and season(s) '{$seasonLabel}' explicitly.\n"
            . "  - If attrContext is set, weave it in naturally.\n"
            . "  - For multi-season products, mention the cross-season benefit if count > 1.\n"
            . "  - Respond ONLY with valid JSON. No markdown. No extra fields.";

        $historyStr = $monthlySales->isNotEmpty()
            ? $monthlySales->take(-6)->map(fn($r) => "{$r->month}: {$r->units} units")->implode(', ')
            : 'no sales history';

        $userPrompt = "Product: {$product->name}\n"
            . "Subcategory: {$subLabel}" . ($attrContext ? " ({$attrContext})" : '') . "\n"
            . 'All product seasons: ' . implode(', ', $productSeasons) . "\n"
            . "Forecasting for: {$seasonLabel}\n"
            . "Combined multiplier: ×{$finalMultiplier} (source: {$multiplierSourceLabel}){$multiSeasonNote}\n"
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

        if (empty($aiNarration['key_factor'])) {
            $aiNarration['key_factor'] = "{$seasonLabel} drives a {$finalMultiplier}× multiplier for {$subLabel}"
                . ($attrContext ? " ({$attrContext})" : '')
                . " based on {$multiplierSourceLabel}. Momentum is {$momentum}.";
        }
        if (empty($aiNarration['advice'])) {
            $aiNarration['advice'] = $stockShortfall > 0
                ? "Restock {$stockShortfall} units before {$seasonLabel} begins — current stock cannot meet predicted demand."
                : "Maintain current stock and activate your ChooseTounsi sponsored listing 5 days before {$seasonLabel} starts.";
        }
        if (empty($aiNarration['opportunity'])) {
            $aiNarration['opportunity'] = count($targetSeasons) > 1
                ? 'Marketing this product across ' . implode(' and ', $targetSeasonLabels) . ' gives it year-round exposure — consider separate promotions per season peak.'
                : ($topVariantSales->isNotEmpty()
                    ? "Your top variant drives most sales — ensure it has priority restock before {$seasonLabel}."
                    : "First {$seasonLabel} with this product — a competitive introductory price will build sales history fast.");
        }

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
                'ai_result' => [
                    'predicted_units'      => $predictedUnits,
                    'growth_pct'           => $growthPct,
                    'trend'                => $trend,
                    'confidence'           => $confidence,
                    'stock_recommendation' => (string)$stockRec,
                    'best_selling_week'    => $bestSellingWeek,
                    'weekly_breakdown'     => $weeklyBreakdown,
                    'risk_factors'         => $riskFactors,
                    'promotion_ideas'      => $promotionIdeas,
                    'key_factor'           => $aiNarration['key_factor'],
                    'advice'               => $aiNarration['advice'],
                    'opportunity'          => $aiNarration['opportunity'],
                ],
                'data_context' => [
                    'product_seasons'        => $productSeasons,
                    'target_seasons'         => $targetSeasons,
                    'season'                 => $primarySeason,
                    'season_label'           => $seasonLabel,
                    'is_multi_season'        => count($targetSeasons) > 1,
                    'per_season_data'        => array_values($perSeasonData),
                    'product_name'           => $product->name,
                    'avg_monthly_sales'      => $avgMonthlySales,
                    'last_month_sales'       => $lastMonthSales,
                    'last_month_revenue'     => $lastMonthRevenue,
                    'monthly_history'        => $monthlySales,
                    'current_stock'          => $currentStock,
                    'stock_shortfall'        => $stockShortfall,
                    'total_units'            => $totalUnits,
                    'total_revenue'          => $totalRevenue,
                    'momentum'               => $momentum,
                    'views'                  => (int)$product->views,
                    'conversion_rate'        => $convRate,
                    'subcategory'            => $product->subcategory_name,
                    'has_variants'           => $hasVariants,
                    'active_variants'        => $activeVarCnt,
                    'total_variants'         => $totalVarCnt,
                    'variant_price_min'      => $hasVariants ? $varPriceMin : null,
                    'variant_price_max'      => $hasVariants ? $varPriceMax : null,
                    'top_variant_sales'      => $topVariantSales->map(fn($v) => [
                        'combo'         => $v->combo_label,
                        'units_sold'    => (int)$v->units_sold,
                        'current_stock' => (int)$v->current_stock,
                    ])->values()->toArray(),
                    'stock_by_axis'          => $stockByAxisForFrontend,
                    'info_attributes'        => $infoAttrLines,
                    'algorithm' => [
                        'base_monthly'           => $baseForPrediction,
                        'season_multiplier'      => $finalMultiplier,
                        'resilience_bonus'       => $resilienceBonus,
                        'momentum_factor'        => $momentumFactor,
                        'total_real_data_points' => $totalRealDataPoints,
                        'formula'                => "{$baseForPrediction} × {$finalMultiplier} × {$momentumFactor} = {$predictedUnits}",
                        'per_season_breakdown'   => array_map(fn($d) => [
                            'season'     => $d['slug'],
                            'multiplier' => $d['effective_multiplier'],
                            'source'     => $d['multiplier_source'],
                        ], array_values($perSeasonData)),
                    ],
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. DESCRIPTION GENERATOR — IMPROVED (SEO removed, category-aware tone)
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
            ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $request->product_id)
            ->whereNull('p.deleted_at')
            ->selectRaw("
                p.id, p.name, p.price, p.stock, p.description,
                p.short_description, p.sku, p.season, p.views,
                c.name as category_name,
                s.name as subcategory_name
            ")
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $attributeRows = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('pav.product_id', $request->product_id)
            ->selectRaw("a.name as attr_name, a.type, pav.value")
            ->get();

        $allOptionIds = [];
        foreach ($attributeRows as $row) {
            if (in_array($row->type, ['select', 'multiselect', 'color'])) {
                $decoded = json_decode($row->value, true);
                if (is_array($decoded)) {
                    $allOptionIds = array_merge($allOptionIds, $decoded);
                } elseif (is_numeric($row->value)) {
                    $allOptionIds[] = (int) $row->value;
                }
            }
        }
        $optionLabels = [];
        if (!empty($allOptionIds)) {
            $optionLabels = DB::table('attribute_options')
                ->whereIn('id', array_unique($allOptionIds))
                ->pluck('value', 'id')
                ->toArray();
        }

        $attrLines = [];
        foreach ($attributeRows as $row) {
            if (in_array($row->type, ['select', 'multiselect', 'color'])) {
                $decoded = json_decode($row->value, true);
                $ids     = is_array($decoded) ? $decoded : (is_numeric($row->value) ? [(int) $row->value] : []);
                $labels  = array_filter(array_map(fn($id) => $optionLabels[$id] ?? null, $ids));
                if (!empty($labels)) {
                    $attrLines[] = $row->attr_name . ': ' . implode(', ', $labels);
                }
            } elseif (!empty($row->value)) {
                $attrLines[] = $row->attr_name . ': ' . $row->value;
            }
        }
        $attributeStr = implode(' | ', $attrLines);

        $salesCount = (int) DB::table('order_items')
            ->where('product_id', $request->product_id)
            ->sum('quantity');

        $variants = DB::table('product_variants as pv')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->join('attributes as a', 'a.id', '=', 'ao.attribute_id')
            ->where('pv.product_id', $request->product_id)
            ->where('pv.is_active', true)
            ->selectRaw("GROUP_CONCAT(DISTINCT CONCAT(a.slug,':', ao.value) ORDER BY a.order SEPARATOR ' / ') as combo")
            ->groupBy('pv.id')
            ->limit(8)
            ->pluck('combo')
            ->toArray();

        $variantStr = !empty($variants) ? implode(', ', $variants) : '';

        $rawSeason = $product->season;
        if (is_string($rawSeason)) {
            $decoded = json_decode($rawSeason, true);
            $seasons = is_array($decoded) ? $decoded : [$rawSeason];
        } elseif (is_array($rawSeason)) {
            $seasons = $rawSeason;
        } else {
            $seasons = ['all_seasons'];
        }
        $seasonLabels = \App\Models\Product::SEASONS;
        $seasonStr    = implode(', ', array_map(
            fn($s) => $seasonLabels[$s] ?? ucfirst(str_replace('_', ' ', $s)),
            $seasons
        ));

        $tone     = $request->input('tone',     'professional');
        $language = $request->input('language', 'fr');

        $catLower    = mb_strtolower(($product->category_name ?? '') . ' ' . ($product->subcategory_name ?? ''));
        $productName = $product->name;
        $catName     = $product->category_name ?? 'General';
        $subName     = $product->subcategory_name ?? '';
        $priceVal    = (float) $product->price;

        $priceLabel = match (true) {
            $priceVal >= 500 => 'Luxury / Ultra-premium',
            $priceVal >= 200 => 'Premium / High-end',
            $priceVal >= 80  => 'Mid-range / Quality',
            $priceVal >= 30  => 'Value / Accessible',
            $priceVal > 0    => 'Budget-friendly',
            default          => 'Price not set',
        };

        $toneProfiles = [
            'fashion|mode|vetement|habit|robe|chemise|pantalon|jupe|pull|manteau|accessoir|sac|chaussure|bijou|lingerie|sportswear' => [
                'persona'  => 'Style copywriter — trend-forward, aspirational, sensory language. Reference fabrics, silhouettes, occasions.',
                'cta_pool' => [
                    "Ajoutez au panier et faites tourner les têtes dès demain.",
                    "Votre prochain look signature vous attend.",
                    "Commandez maintenant — les stocks s'épuisent vite.",
                    "Offrez-vous un style qui vous ressemble vraiment.",
                    "Disponible maintenant — livraison express partout en Tunisie.",
                ],
            ],
            'artisan|handmade|broderie|poterie|ceramique|maroquinerie|tapis|artisanat|decor|decoration' => [
                'persona'  => 'Artisan storyteller — authentic, warm, craft-proud. Emphasise the human hands, local materials, tradition.',
                'cta_pool' => [
                    "Faites entrer l'artisanat tunisien dans votre quotidien.",
                    "Chaque piece est unique — commandez la votre avant qu'elle parte.",
                    "Soutenez l'artisanat local en passant votre commande aujourd'hui.",
                    "Un savoir-faire transmis de generation en generation, livre chez vous.",
                    "Offrez l'authentique — commandez maintenant.",
                ],
            ],
            'food|alimentaire|alimentation|epicerie|cuisine|gateau|patisserie|miel|huile|olive|harissa|biscuit|confiture|dattes|cafe|the|poisson' => [
                'persona'  => 'Food copywriter — appetising, sensory, evocative. Use taste, smell, texture. Reference Tunisian flavours.',
                'cta_pool' => [
                    "Commandez maintenant et regalez votre table ce soir.",
                    "Livraison fraiche — commandez avant midi.",
                    "Goutez la difference — ajoutez au panier maintenant.",
                    "Un gout authentique qui vous ramene a la maison.",
                    "Pour vos repas en famille — commandez avant la rupture de stock.",
                ],
            ],
            'beaute|beauty|cosmetique|soin|skincare|parfum|creme|maquillage|serum|lotion|hygiene|cheveux|hair|shampoo|masque|visage' => [
                'persona'  => 'Beauty editor — elegant, self-care focused, sensory. Emphasise transformation, ritual, and confidence.',
                'cta_pool' => [
                    "Prenez soin de vous — ajoutez au panier maintenant.",
                    "Votre rituel beaute commence ici.",
                    "Commandez et ressentez la difference des la premiere utilisation.",
                    "Livraison rapide — commencez votre routine des demain.",
                    "Offrez-vous ce soin des aujourd'hui.",
                ],
            ],
            'tech|electronique|informatique|telephone|smartphone|ordinateur|laptop|tablette|gadget|audio|casque|enceinte|batterie|chargeur' => [
                'persona'  => 'Tech reviewer — modern, practical, spec-confident. Lead with the key spec advantage, then practical use case.',
                'cta_pool' => [
                    "Commandez maintenant et recevez votre appareil sous 24-48h.",
                    "Stock limite — securisez le votre aujourd'hui.",
                    "Compatible, fiable, disponible — ajoutez au panier.",
                    "Performance garantie — commandez des maintenant.",
                    "Livraison rapide partout en Tunisie.",
                ],
            ],
            'maison|mobilier|meuble|electromenager|four|refrigerateur|aspirateur|canape|matelas|luminaire|lampe|rideau' => [
                'persona'  => 'Home lifestyle writer — warm, practical, aspirational. Paint a picture of the home environment this product improves.',
                'cta_pool' => [
                    "Transformez votre interieur — commandez maintenant.",
                    "Livraison rapide — votre maison vous remerciera.",
                    "Stock limite — ajoutez au panier avant qu'il ne parte.",
                    "Qualite et confort reunis — a votre porte en 48h.",
                    "Commandez aujourd'hui et profitez des cette semaine.",
                ],
            ],
            'sport|fitness|musculation|velo|football|basket|tennis|yoga|randonnee|maillot|equipement sportif' => [
                'persona'  => 'Sports coach copywriter — energetic, motivating, performance-focused. Use active verbs and challenge language.',
                'cta_pool' => [
                    "Entrainezous mieux — commandez maintenant.",
                    "Votre prochain record vous attend — ajoutez au panier.",
                    "Performance garantie — livre en 48h.",
                    "Ne laissez pas vos objectifs attendre.",
                    "Commandez et passez au niveau superieur des demain.",
                ],
            ],
            'bebe|enfant|jouet|puericulture|biberon' => [
                'persona'  => 'Parenting copywriter — reassuring, warm, safety-first. Speak directly to the loving parent.',
                'cta_pool' => [
                    "Offrez le meilleur a votre enfant — commandez maintenant.",
                    "Securise, teste, et livre rapidement — ajoutez au panier.",
                    "Votre bebe merite le meilleur — commandez aujourd'hui.",
                    "Stock limite — ne tardez pas.",
                    "Livraison rapide partout en Tunisie.",
                ],
            ],
        ];

        $matchedPersona = null;
        $ctaPool        = [];

        foreach ($toneProfiles as $keywords => $profile) {
            $kwArray = explode('|', $keywords);
            foreach ($kwArray as $kw) {
                if (mb_strpos($catLower, mb_strtolower(trim($kw))) !== false) {
                    $matchedPersona = $profile['persona'];
                    $ctaPool        = $profile['cta_pool'];
                    break 2;
                }
            }
        }

        if (!$matchedPersona) {
            $matchedPersona = 'Conversion copywriter — clear, benefits-first, trustworthy. Lead with the key value, support with proof, close with action.';
            $ctaPool = [
                "Commandez maintenant — livraison rapide partout en Tunisie.",
                "Ajoutez au panier et recevez sous 24-48h.",
                "Stock disponible — commandez avant rupture.",
                "Qualite garantie — commandez des aujourd'hui.",
            ];
        }

        $cta = $ctaPool[array_rand($ctaPool)];

        $toneInstruction = match ($tone) {
            'casual'        => 'Register: friendly, conversational. Simple sentences. Contractions allowed.',
            'exciting'      => 'Register: high energy, bold, create urgency. Short punchy sentences.',
            'trust-focused' => 'Register: reassuring, cite quality signals. Emphasise reliability and guarantees.',
            default         => 'Register: clear, authoritative, benefits-first. Professional and credible.',
        };

        $langInstruction = match ($language) {
            'ar'    => 'Write EVERYTHING in Modern Standard Arabic.',
            'en'    => 'Write EVERYTHING in English. Optimise for Tunisian diaspora and international buyers.',
            default => 'Write EVERYTHING in French.',
        };

        $introOpenersJson = json_encode([
            "Il y a des produits que l'on garde pour toujours.",
            "Certaines choses meritent d'etre vecues, pas seulement achetees.",
            "Tout commence par le bon choix.",
            "Imaginez.",
            "Vous le cherchiez — le voila.",
            "La difference, elle se ressent des le premier instant.",
            "Derriere chaque bonne decision, il y a une bonne raison.",
            "Pense pour vous. Fait pour durer.",
            "Ce n'est pas un achat. C'est un investissement dans votre quotidien.",
            "Parce que vous meritez mieux que l'ordinaire.",
            "Le detail qui change tout.",
            "Simple. Efficace. Tunisien.",
            "Quand qualite et accessibilite se rencontrent.",
            "Voici ce que vous attendiez.",
            "Moins de compromis. Plus de satisfaction.",
            "Une seule regle : ne jamais sacrifier la qualite.",
            "Le produit dont on parle — maintenant disponible chez vous.",
            "Chaque jour merite le meilleur.",
            "Concu pour ceux qui exigent l'excellence.",
            "Quand on y goute, on ne revient plus en arriere.",
        ], JSON_UNESCAPED_UNICODE);

        $contextLines = array_filter([
            "Product name: {$productName}",
            "Category: {$catName}" . ($subName ? " > {$subName}" : ''),
            $priceVal > 0   ? "Price: {$priceVal} TND ({$priceLabel} positioning)" : null,
            $seasonStr      ? "Season(s): {$seasonStr}" : null,
            $variantStr     ? "Available variants: {$variantStr}" : null,
            $attributeStr   ? "Product attributes: {$attributeStr}" : null,
            $salesCount > 0 ? "Units sold: {$salesCount} (social proof)" : null,
            $product->short_description ? "Existing short description: \"{$product->short_description}\"" : null,
            $product->description       ? "Existing description (improve upon): \"{$product->description}\"" : null,
        ]);
        $context = implode("\n", $contextLines);

        $systemPrompt = <<<EOT
You are a senior product copywriter for ChooseTounsi, Tunisia's leading multi-vendor e-commerce marketplace.

WRITING IDENTITY: {$matchedPersona}

LANGUAGE RULE: {$langInstruction}

TONE RULE: {$toneInstruction}

STRUCTURE RULE — always follow this arc:
  1. Hook / Opening line — unique, emotionally resonant, never generic.
  2. Value proposition — what is this product and why does it matter?
  3. Key features / Benefits — specific to THIS product.
  4. Trust element — quality, origin, social proof hint, or seasonal relevance.
  5. Call to action — use EXACTLY the CTA provided, word for word.

INTRO VARIETY RULE — choose the opener that best fits the product's emotion:
{$introOpenersJson}

Do NOT use "Decouvrez notre", "Introducing", or any generic discovery phrase.

OUTPUT RULES:
- short_description: 1-2 sentences, maximum 160 characters.
- description: 180-300 words, flowing prose paragraphs. No bullet points. No headers. No dashes.
  Must end with EXACTLY this call to action, verbatim: "{$cta}"
- Respond with ONLY valid JSON. No markdown. No text outside the JSON object.
EOT;

        $userPrompt = <<<EOT
Generate a human-quality, emotionally resonant product description for ChooseTounsi.

PRODUCT DATA:
{$context}

REQUIRED JSON (exactly these two fields, no others):
{
  "short_description": "<hook sentence, max 160 chars>",
  "description": "<full flowing prose description, 180-300 words, ends with the exact CTA>"
}
EOT;

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 900);
        $aiResult = null;

        if ($aiRaw) {
            try {
                $clean = preg_replace('/```json|```/i', '', $aiRaw);
                $start = strpos($clean, '{');
                $end   = strrpos($clean, '}');
                if ($start !== false && $end !== false) {
                    $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
                    if (!empty($parsed['short_description']) && !empty($parsed['description'])) {
                        $aiResult = [
                            'short_description' => (string) $parsed['short_description'],
                            'description'       => (string) $parsed['description'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[SellerAI::descriptionGenerator] Parse failed: ' . $e->getMessage());
            }
        }

        if (!$aiResult) {
    $variantNote = $variantStr ? " Available in: {$variantStr}." : '';
    $attrNote    = $attrStr    ? " Attributes: {$attrStr}." : '';

    if ($language === 'en') {
        $aiResult = [
            'short_description' => $shortDesc
                ?: "{$name} — quality and authenticity, delivered fast across Tunisia.",
            'description'       =>
                "Looking for a reliable product in the {$category} category? "
                . "{$name} delivers exactly what you need.{$attrNote}{$variantNote} "
                . "Built for customers who refuse to compromise, this product stands out "
                . "for its quality finish and proven durability. "
                . $cta,
        ];
    } else {
        $variantNote = $variantStr ? " Disponible en : {$variantStr}." : '';
        $attrNote    = $attrStr    ? " Caracteristiques : {$attrStr}." : '';
        $aiResult    = [
            'short_description' => $shortDesc
                ?: "{$name} — qualite et authenticite, livre rapidement partout en Tunisie.",
            'description'       =>
                "Vous cherchez un produit qui allie qualite et fiabilite dans la categorie {$category} ? "
                . "{$name} repond exactement a vos attentes.{$attrNote}{$variantNote} "
                . "Concu pour les consommateurs tunisiens qui refusent de faire des compromis, "
                . "ce produit se distingue par ses finitions soignees et sa durabilite eprouvee. "
                . "Que vous l'offriez ou vous le reserviez, vous ne serez pas decu. "
                . $cta,
        ];
    }
}

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    'product_name'        => $productName,
                    'category'            => $catName,
                    'current_description' => $product->description,
                    'attributes'          => $attributeStr,
                    'units_sold'          => $salesCount,
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4. QUICK DESCRIPTION — IMPROVED (category-aware tone, no SEO)
    // ═══════════════════════════════════════════════════════════════════════
    public function quickDescription(Request $request)
    {
        $request->validate([
            'name'              => 'required|string|max:255',
            'category'          => 'nullable|string|max:100',
            'subcategory'       => 'nullable|string|max:100',
            'price'             => 'nullable|numeric|min:0',
            'short_description' => 'nullable|string|max:500',
            'attributes'        => 'nullable|array',
            'variants'          => 'nullable|array',
            'image_count'       => 'nullable|integer|min:0',
            'tone'              => 'nullable|in:professional,casual,exciting,trust-focused',
            'language'          => 'nullable|in:en,fr,ar',
        ]);

        $name        = trim($request->name);
        $category    = trim($request->input('category',    'General')) ?: 'General';
        $subcategory = trim($request->input('subcategory', '')) ?: '';
        $price       = (float) $request->input('price', 0);
        $shortDesc   = trim($request->input('short_description', ''));
        $attributes  = (array)  $request->input('attributes', []);
        $variants    = array_slice((array) $request->input('variants', []), 0, 12);
        $imageCount  = (int)    $request->input('image_count', 0);
        $sellerTone  = $request->input('tone', 'professional');
        $language    = $request->input('language', 'fr');

        $priceLabel = match (true) {
            $price >= 500 => 'Luxury / Ultra-premium',
            $price >= 200 => 'Premium / High-end',
            $price >= 80  => 'Mid-range / Quality',
            $price >= 30  => 'Value / Accessible',
            $price > 0    => 'Budget-friendly',
            default       => 'Price not set',
        };

        $attrParts = [];
        foreach ($attributes as $slug => $val) {
            if ($val !== null && $val !== '') {
                $attrParts[] = ucfirst(str_replace('_', ' ', (string) $slug)) . ': ' . $val;
            }
        }
        $attrStr    = implode(' | ', $attrParts);
        $variantStr = !empty($variants) ? implode(', ', $variants) : '';

        $catLower = mb_strtolower($category . ' ' . $subcategory);

        $toneProfiles = [
            'fashion|mode|vetement|habit|robe|chemise|pantalon|jupe|pull|manteau|accessoir|sac|chaussure|bijou|lingerie|sportswear' => [
                'persona'  => 'Style copywriter — trend-forward, aspirational, sensory language. Reference fabrics, silhouettes, occasions.',
                'cta_pool' => [
                    "Ajoutez au panier et faites tourner les têtes dès demain.",
                    "Votre prochain look signature vous attend.",
                    "Commandez maintenant — les stocks s'épuisent vite.",
                    "Offrez-vous un style qui vous ressemble vraiment.",
                    "Disponible maintenant — livraison express partout en Tunisie.",
                ],
            ],
            'artisan|handmade|broderie|poterie|ceramique|maroquinerie|tapis|artisanat|decor|decoration' => [
                'persona'  => 'Artisan storyteller — authentic, warm, craft-proud. Emphasise the human hands, local materials, tradition.',
                'cta_pool' => [
                    "Faites entrer l'artisanat tunisien dans votre quotidien.",
                    "Chaque piece est unique — commandez la votre avant qu'elle parte.",
                    "Soutenez l'artisanat local en passant votre commande aujourd'hui.",
                    "Un savoir-faire transmis de generation en generation, livre chez vous.",
                    "Offrez l'authentique — commandez maintenant.",
                ],
            ],
            'food|alimentaire|alimentation|epicerie|cuisine|gateau|patisserie|miel|huile|olive|harissa|biscuit|confiture|dattes|cafe|the|poisson' => [
                'persona'  => 'Food copywriter — appetising, sensory, evocative. Use taste, smell, texture. Reference Tunisian flavours.',
                'cta_pool' => [
                    "Commandez maintenant et regalez votre table ce soir.",
                    "Livraison fraiche — commandez avant midi.",
                    "Goutez la difference — ajoutez au panier maintenant.",
                    "Un gout authentique qui vous ramene a la maison.",
                    "Pour vos repas en famille — commandez avant la rupture de stock.",
                ],
            ],
            'beaute|beauty|cosmetique|soin|skincare|parfum|creme|maquillage|serum|lotion|hygiene|cheveux|hair|shampoo|masque|visage' => [
                'persona'  => 'Beauty editor — elegant, self-care focused, sensory. Emphasise transformation, ritual, and confidence.',
                'cta_pool' => [
                    "Prenez soin de vous — ajoutez au panier maintenant.",
                    "Votre rituel beaute commence ici.",
                    "Commandez et ressentez la difference des la premiere utilisation.",
                    "Livraison rapide — commencez votre routine des demain.",
                    "Offrez-vous ce soin des aujourd'hui.",
                ],
            ],
            'tech|electronique|informatique|telephone|smartphone|ordinateur|laptop|tablette|gadget|audio|casque|enceinte|batterie|chargeur' => [
                'persona'  => 'Tech reviewer — modern, practical, spec-confident. Lead with the key spec advantage, then practical use case.',
                'cta_pool' => [
                    "Commandez maintenant et recevez votre appareil sous 24-48h.",
                    "Stock limite — securisez le votre aujourd'hui.",
                    "Compatible, fiable, disponible — ajoutez au panier.",
                    "Performance garantie — commandez des maintenant.",
                    "Livraison rapide partout en Tunisie.",
                ],
            ],
            'maison|mobilier|meuble|electromenager|four|refrigerateur|aspirateur|canape|matelas|luminaire|lampe|rideau' => [
                'persona'  => 'Home lifestyle writer — warm, practical, aspirational. Paint a picture of the home environment this product improves.',
                'cta_pool' => [
                    "Transformez votre interieur — commandez maintenant.",
                    "Livraison rapide — votre maison vous remerciera.",
                    "Stock limite — ajoutez au panier avant qu'il ne parte.",
                    "Qualite et confort reunis — a votre porte en 48h.",
                    "Commandez aujourd'hui et profitez des cette semaine.",
                ],
            ],
            'sport|fitness|musculation|velo|football|basket|tennis|yoga|randonnee|maillot|equipement sportif' => [
                'persona'  => 'Sports coach copywriter — energetic, motivating, performance-focused. Use active verbs and challenge language.',
                'cta_pool' => [
                    "Entrainez-vous mieux — commandez maintenant.",
                    "Votre prochain record vous attend — ajoutez au panier.",
                    "Performance garantie — livre en 48h.",
                    "Ne laissez pas vos objectifs attendre.",
                    "Commandez et passez au niveau superieur des demain.",
                ],
            ],
            'bebe|enfant|jouet|puericulture|biberon' => [
                'persona'  => 'Parenting copywriter — reassuring, warm, safety-first. Speak directly to the loving parent.',
                'cta_pool' => [
                    "Offrez le meilleur a votre enfant — commandez maintenant.",
                    "Securise, teste, et livre rapidement — ajoutez au panier.",
                    "Votre bebe merite le meilleur — commandez aujourd'hui.",
                    "Stock limite — ne tardez pas.",
                    "Livraison rapide partout en Tunisie.",
                ],
            ],
        ];

        $matchedPersona = null;
        $ctaPool        = [];

        foreach ($toneProfiles as $keywords => $profile) {
            $kwArray = explode('|', $keywords);
            foreach ($kwArray as $kw) {
                if (mb_strpos($catLower, mb_strtolower(trim($kw))) !== false) {
                    $matchedPersona = $profile['persona'];
                    $ctaPool        = $profile['cta_pool'];
                    break 2;
                }
            }
        }

        if (!$matchedPersona) {
            $matchedPersona = 'Conversion copywriter — clear, benefits-first, trustworthy. Lead with the key value, support with proof, close with action.';
            $ctaPool = [
                "Commandez maintenant — livraison rapide partout en Tunisie.",
                "Ajoutez au panier et recevez sous 24-48h.",
                "Stock disponible — commandez avant rupture.",
                "Qualite garantie — commandez des aujourd'hui.",
                "Offrez-vous ce produit maintenant.",
            ];
        }

        $cta = $ctaPool[array_rand($ctaPool)];

        $introOpenersJson = json_encode([
            "Il y a des produits que l'on garde pour toujours.",
            "Certaines choses meritent d'etre vecues, pas seulement achetees.",
            "Tout commence par le bon choix.",
            "Imaginez.",
            "Vous le cherchiez — le voila.",
            "La difference, elle se ressent des le premier instant.",
            "Derriere chaque bonne decision, il y a une bonne raison.",
            "Pense pour vous. Fait pour durer.",
            "Ce n'est pas un achat. C'est un investissement dans votre quotidien.",
            "Parce que vous meritez mieux que l'ordinaire.",
            "Le detail qui change tout.",
            "Simple. Efficace. Tunisien.",
            "Quand qualite et accessibilite se rencontrent.",
            "Voici ce que vous attendiez.",
            "Moins de compromis. Plus de satisfaction.",
            "Une seule regle : ne jamais sacrifier la qualite.",
            "Le produit dont on parle — maintenant disponible chez vous.",
            "Chaque jour merite le meilleur.",
            "Concu pour ceux qui exigent l'excellence.",
            "Quand on y goute, on ne revient plus en arriere.",
        ], JSON_UNESCAPED_UNICODE);

        $toneInstruction = match ($sellerTone) {
            'casual'        => 'Register: friendly, conversational, like a trusted friend recommending. Simple sentences.',
            'exciting'      => 'Register: high energy, bold, create desire and urgency. Strong action verbs. Short punchy sentences.',
            'trust-focused' => 'Register: reassuring, credible, cite quality signals. Emphasise reliability and guarantees.',
            default         => 'Register: clear, authoritative, benefits-first. Professional and credible without being cold.',
        };

        $langInstruction = match ($language) {
            'ar'    => 'Write EVERYTHING in Modern Standard Arabic. All text in Arabic script.',
            'en'    => 'Write EVERYTHING in English. Optimise for Tunisian diaspora and international buyers.',
            default => 'Write EVERYTHING in French. Both fields must be in French.',
        };

        $contextLines = array_filter([
            "Product name: {$name}",
            "Category: {$category}" . ($subcategory ? " > {$subcategory}" : ''),
            $price > 0   ? "Price: {$price} TND ({$priceLabel} positioning)" : null,
            $imageCount  ? "Photos available: {$imageCount}" : 'Photos: none yet',
            $variantStr  ? "Available options/variants: {$variantStr}" : null,
            $attrStr     ? "Product attributes: {$attrStr}" : null,
            $shortDesc   ? "Seller draft (improve and expand): \"{$shortDesc}\"" : null,
        ]);
        $context = implode("\n", $contextLines);

        $systemPrompt = <<<EOT
You are a senior product copywriter for ChooseTounsi, Tunisia's leading multi-vendor e-commerce marketplace.

WRITING IDENTITY: {$matchedPersona}

LANGUAGE RULE: {$langInstruction}

TONE RULE: {$toneInstruction}

STRUCTURE RULE — always follow this arc:
  1. Hook / Opening line — unique, emotionally resonant, never generic.
  2. Value proposition — what is this product and why does it matter to the buyer?
  3. Key features / Benefits — specific to THIS product, not a generic list.
  4. Trust element — quality signal, origin story, or social proof hint.
  5. Call to action — use EXACTLY the CTA provided, word for word.

INTRO VARIETY RULE — choose one opener from this list that best fits the product.
Do NOT use "Decouvrez notre", "Introducing", or any generic discovery phrase.
Intro pool (pick the best fit):
{$introOpenersJson}

TUNISIAN CONTEXT — weave in naturally when relevant:
- Local delivery confidence
- Cultural moments (Ramadan, Eid, summer, back-to-school) if the product fits
- Local materials, origin, or craftsmanship when authentic

OUTPUT RULES:
- short_description: 1-2 sentences, maximum 160 characters.
- description: 160-280 words, flowing paragraphs. No bullet points. No dashes. No headers.
  Must end with EXACTLY this call to action, verbatim: "{$cta}"
- Respond with ONLY valid JSON. No markdown fences. No text outside the JSON object.
EOT;

        $userPrompt = <<<EOT
Generate a high-conversion product listing for ChooseTounsi.

PRODUCT DATA:
{$context}

REQUIRED JSON (no other fields, no extra text):
{
  "short_description": "<hook sentence, max 160 chars>",
  "description": "<full flowing description, 160-280 words, ends with the exact CTA>"
}
EOT;

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 900);
        $aiResult = null;

        if ($aiRaw) {
            try {
                $clean = preg_replace('/```json|```/i', '', $aiRaw);
                $start = strpos($clean, '{');
                $end   = strrpos($clean, '}');
                if ($start !== false && $end !== false) {
                    $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
                    if (!empty($parsed['short_description']) && !empty($parsed['description'])) {
                        $aiResult = [
                            'short_description' => (string) $parsed['short_description'],
                            'description'       => (string) $parsed['description'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[SellerAI::quickDescription] Parse failed: ' . $e->getMessage());
            }
        }

        if (!$aiResult) {
    $variantNote = $variantStr ? " Available in: {$variantStr}." : '';
    $attrNote    = $attrStr    ? " Attributes: {$attrStr}." : '';

    if ($language === 'en') {
        $aiResult = [
            'short_description' => $shortDesc
                ?: "{$name} — quality and authenticity, delivered fast across Tunisia.",
            'description'       =>
                "Looking for a reliable product in the {$category} category? "
                . "{$name} delivers exactly what you need.{$attrNote}{$variantNote} "
                . "Built for customers who refuse to compromise, this product stands out "
                . "for its quality finish and proven durability. "
                . $cta,
        ];
    } else {
        $variantNote = $variantStr ? " Disponible en : {$variantStr}." : '';
        $attrNote    = $attrStr    ? " Caracteristiques : {$attrStr}." : '';
        $aiResult    = [
            'short_description' => $shortDesc
                ?: "{$name} — qualite et authenticite, livre rapidement partout en Tunisie.",
            'description'       =>
                "Vous cherchez un produit qui allie qualite et fiabilite dans la categorie {$category} ? "
                . "{$name} repond exactement a vos attentes.{$attrNote}{$variantNote} "
                . "Concu pour les consommateurs tunisiens qui refusent de faire des compromis, "
                . "ce produit se distingue par ses finitions soignees et sa durabilite eprouvee. "
                . "Que vous l'offriez ou vous le reserviez, vous ne serez pas decu. "
                . $cta,
        ];
    }
}

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => compact('name', 'category', 'sellerTone', 'language', 'priceLabel'),
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
                ->groupBy('p.id', 'p.name', 'p.price', 'c.name')
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
            ->select('product_id', 'variant_id', 'image_path', 'is_primary', 'order', 'id')
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

        $coPurchasedStr    = $coPurchased->map(fn($p) => "{$p->name} ({$p->co_count}x co-purchased)")->implode(', ') ?: 'No co-purchase data yet';
        $categoryStr       = $sameCategoryProducts->pluck('name')->implode(', ') ?: 'No other products in category';
        $otherProductsArr  = $coPurchased->isNotEmpty() ? $coPurchased->pluck('name') : $sameCategoryProducts->pluck('name');
        $otherProductsList = $otherProductsArr->implode('", "');

        $systemPrompt = "You are a Tunisian e-commerce bundle strategy expert for ChooseTounsi marketplace.\nSuggest high-converting product bundles and related product recommendations.\nBase suggestions on real purchase affinity data and Tunisian shopping behavior.\nALWAYS respond with ONLY valid JSON. No markdown. No text outside JSON.";

        if ($mode === 'bundle') {
            $userPrompt = "Create bundle recommendations for this ChooseTounsi product:\n\nMAIN PRODUCT: {$mainProduct->name} ({$mainProduct->category_name}) — {$mainProduct->price} TND\n\nCO-PURCHASED (real data): {$coPurchasedStr}\nSAME CATEGORY PRODUCTS: {$categoryStr}\nPROPOSED DISCOUNT: {$discountPct}%\n\nRespond with ONLY this JSON:\n{\n  \"bundles\": [\n    {\n      \"name\": \"<bundle name>\",\n      \"products\": [\"{$mainProduct->name}\", \"{$otherProductsList}\"],\n      \"reason\": \"<why these work together>\",\n      \"est_uplift\": \"<estimated % revenue increase>\",\n      \"discount\": {$discountPct},\n      \"suggested_price_reduction\": \"<discount explanation>\",\n      \"display_label\": \"<short UI badge text>\"\n    }\n  ]\n}\nInclude 2-3 bundles. Tailor for Tunisian buyers.";
        } else {
            $userPrompt = "Suggest related products and cross-sell opportunities for:\n\nMAIN PRODUCT: {$mainProduct->name} ({$mainProduct->category_name}) — {$mainProduct->price} TND\n\nCO-PURCHASED PRODUCTS: {$coPurchasedStr}\nSAME CATEGORY: {$categoryStr}\n\nRespond with ONLY this JSON:\n{\n  \"recommendations\": [\n    {\n      \"product_name\": \"<name>\",\n      \"reason\": \"<why relevant>\",\n      \"placement\": \"also_bought\"|\"similar\"|\"upgrade\"|\"accessory\",\n      \"est_click_rate\": \"<estimated engagement>\"\n    }\n  ],\n  \"placement_strategy\": \"<where to show>\",\n  \"best_time_to_show\": \"<when in buyer journey>\"\n}\nInclude 4-6 recommendations.";
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
                    [
                        'name'                    => 'Starter Pack',
                        'products'                => array_slice(array_merge([$mainProduct->name], $companions), 0, 2),
                        'reason'                  => "Customers who bought {$mainProduct->name} frequently also purchase " . ($companions[0] ?? 'a complementary item') . " within 7 days.",
                        'est_uplift'              => '+' . (15 + $discountPct) . '%',
                        'discount'                => $discountPct,
                        'suggested_price_reduction'=> "{$discountPct}% off when bought together",
                        'display_label'           => 'Popular Combo',
                    ],
                    [
                        'name'                    => 'Value Bundle',
                        'products'                => array_slice(array_merge([$mainProduct->name], $companions), 0, 3),
                        'reason'                  => "Complete the set — this bundle covers all common use cases for {$mainProduct->category_name} buyers.",
                        'est_uplift'              => '+' . (25 + $discountPct) . '%',
                        'discount'                => $discountPct,
                        'suggested_price_reduction'=> "Save {$discountPct}% on the complete bundle",
                        'display_label'           => 'Best Value',
                    ],
                ]];
            } else {
                $aiResult = [
                    'recommendations'    => array_map(fn($name) => [
                        'product_name'   => $name,
                        'reason'         => "Co-purchased with {$mainProduct->name} based on real buyer behavior.",
                        'placement'      => 'also_bought',
                        'est_click_rate' => '12-18%',
                    ], array_slice($companions, 0, 5)),
                    'placement_strategy' => 'Show on product detail page under "Customers also bought" section.',
                    'best_time_to_show'  => 'After adding to cart and on checkout page.',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    'product_name'  => $mainProduct->name,
                    'co_purchased'  => $coPurchased->take(5)->values(),
                    'same_category' => $sameCategoryProducts->take(5)->values(),
                    'mode'          => $mode,
                    'product_images'=> $productImagesByName,
                ],
            ],
        ]);
    }
}