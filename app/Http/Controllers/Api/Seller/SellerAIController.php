<?php
// app/Http/Controllers/Api/Seller/SellerAIController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\Chat\GroqClient;
use App\Services\MarketIntelligenceService;
use App\Services\PriceNormalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class SellerAIController extends Controller
{
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
        if (in_array('net_total', $cols))  $parts[] = 'oi.net_total'; // after seller coupon
        if (in_array('total',      $cols)) $parts[] = 'oi.total';
        if (in_array('subtotal',   $cols)) $parts[] = 'oi.subtotal';
        if (in_array('unit_price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.unit_price * oi.quantity';
        elseif (in_array('price', $cols) && in_array('quantity', $cols))
            $parts[] = 'oi.price * oi.quantity';
        $parts[] = '0';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    /**
     * Through the shared GroqClient: configured model (services.groq.model), free-tier
     * budget and 429 handling. Null on any failure — every tool has a math fallback.
     */
    private function callGroq(string $system, string $user, int $maxTokens = 700): ?string
    {
        // Reasoning models spend part of the completion budget thinking first
        return app(GroqClient::class)->chat(
            [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'seller_ai_tools',
            false,
            $maxTokens + 400,
            ['temperature' => 0.7, 'timeout' => 30]
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1. PRICE OPTIMIZER — UNCHANGED
    // ═══════════════════════════════════════════════════════════════════════
    public function priceOptimizer(Request $request)
    {
        $request->validate(['product_id' => 'required|integer', 'language' => 'nullable|in:fr,en,ar']);
        // Texts come back in the seller's dashboard language (AI and fallback alike)
        $lang = $request->input('language') ?: (in_array(app()->getLocale(), ['fr', 'en', 'ar'], true) ? app()->getLocale() : 'fr');
        $tr   = fn(string $key, array $r = []) => __("ai_price.$key", $r, $lang);
        // 1234.5 → "1 234,5" (fr/ar) or "1,234.5" (en), up to 3 decimals, no trailing zeros
        $num  = function ($v) use ($lang): string {
            $v   = round((float) $v, 3);
            $dec = strlen(rtrim(substr(strrchr(number_format($v, 3, '.', ''), '.'), 1), '0'));
            return $lang === 'en' ? number_format($v, $dec, '.', ',') : number_format($v, $dec, ',', ' ');
        };

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
            return response()->json(['success' => false, 'message' => __('seller.common.product_not_found')], 404);
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

        $langName = ['fr' => 'French', 'en' => 'English', 'ar' => 'Modern Standard Arabic'][$lang];
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
8. Write every text value (strategy, reasoning, expected_impact, competitor_summary, overpriced_warning,
   opportunity_note, psychological_tip) in {$langName}. Keep the enum values (confidence, risk,
   market_positioning) and platform names exactly as specified, in English.
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
                $reasonBase = $tr('reason_market', ['count' => $marketReport['data_points'], 'platforms' => implode(', ', $platformsUsed), 'avg' => $num($marketReport['market_avg'])]);
            } elseif ($safeCatAvg > 0) {
                $reasonBase = $tr('reason_platform', ['count' => $competitorCount, 'avg' => $num($safeCatAvg)]);
            } else {
                $reasonBase = $tr('reason_none', ['category' => $product->category_name]);
            }
            $positioningLabel = $tr("positioning.$positioning");
            if (str_starts_with($positioningLabel, 'ai_price.')) $positioningLabel = $tr('positioning.unknown');

            $aiResult = [
                'suggested_price'      => $suggested,
                'competitive_price'    => $competitive,
                'premium_price'        => $premium,
                'min_profitable_price' => $minProfit,
                'market_avg_price'     => round($bestRef, 3),
                'confidence'           => $hasMarketData ? ($marketReport['confidence'] ?? 'medium') : ($safeCatAvg > 0 ? 'medium' : 'low'),
                'risk'                 => 'low',
                'strategy'             => $tr($totalUnits === 0 ? 'strategy_entry' : 'strategy_market'),
                'reasoning'            => $reasonBase . '. ' . $tr('reason_current', ['price' => $num($productPrice), 'positioning' => $positioningLabel]),
                'expected_impact'      => $tr($totalUnits === 0 ? 'impact_entry' : 'impact_market'),
                'market_positioning'   => $positioning,
                'competitor_summary'   => $hasMarketData
                    ? $tr('competitors_market', ['platforms' => implode(', ', $platformsUsed), 'count' => $marketReport['data_points'],
                                                  'min' => $num($marketReport['market_min']), 'max' => $num($marketReport['market_max'])])
                    : ($safeCatAvg > 0
                        ? $tr('competitors_platform', ['count' => $competitorCount, 'avg' => $num($safeCatAvg)])
                        : $tr('competitors_none')),
                'overpriced_warning'   => $positioningPct > 15 ? $tr('overpriced', ['pct' => $num($positioningPct)]) : null,
                'opportunity_note'     => $positioningPct < -10
                    ? $tr('room_to_increase', ['pct' => $num(abs($positioningPct))])
                    : ($totalUnits === 0 ? $tr('no_sales') : null),
                'psychological_tip'    => $tr('psycho', ['psycho' => $num($psychoTip), 'price' => $num($suggested)]),
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
    // 2. RECOMMENDER — UNCHANGED
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
            return response()->json(['success' => false, 'message' => __('seller.common.product_not_found')], 404);
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