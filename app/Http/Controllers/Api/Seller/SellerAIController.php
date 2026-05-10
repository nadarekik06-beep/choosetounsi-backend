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
            ->selectRaw("p.id, p.name, p.price, p.stock, p.views, p.category_id, c.name as category_name, c.slug as category_slug")
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
                AVG(oi.unit_price)          as avg_sold_price,
                MIN(o.created_at)           as first_sale,
                MAX(o.created_at)           as last_sale
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

        $catAvgRaw  = (float)($priceStdDevRow->avg_price ?? 0);
        $catStd     = (float)($priceStdDevRow->std_price ?? 0);
        $lowerBound = $catStd > 0 ? max($priceLow, $catAvgRaw - 2.0 * $catStd) : $priceLow;
        $upperBound = $catStd > 0 ? min($priceHigh, $catAvgRaw + 2.0 * $catStd) : $priceHigh;

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
            ->where('o.created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(oi.quantity) as units")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $conversionRate = 0;
        if (($product->views ?? 0) > 0 && ($salesHistory->total_orders ?? 0) > 0) {
            $conversionRate = round(($salesHistory->total_orders / $product->views) * 100, 2);
        }

        $totalUnits      = (int)($salesHistory->total_units ?? 0);
        $totalRevenue    = round((float)($salesHistory->total_revenue ?? 0), 3);
        $avgSoldPrice    = round((float)($salesHistory->avg_sold_price ?? $product->price), 3);
        $competitorCount = (int)($similarProducts->count ?? 0);
        $trendStr        = $monthlySales->map(fn($r) => "{$r->month}: {$r->units} units")->implode(', ') ?: 'No sales history';
        $catAvgPrice     = round((float)($similarProducts->avg_price ?? 0), 3);
        $catMinPrice     = round((float)($similarProducts->min_price ?? 0), 3);
        $catMaxPrice     = round((float)($similarProducts->max_price ?? 0), 3);

        $marketReport = ['has_data' => false];
        try {
            $marketSvc    = new MarketIntelligenceService(new PriceNormalizationService());
            $marketReport = $marketSvc->analyze($product->name, $product->category_name ?? 'General', (float)$product->price);
        } catch (\Throwable $e) {
            Log::warning("[SellerAI::priceOptimizer] Market intelligence failed: " . $e->getMessage());
        }

        $hasMarketData = (bool)($marketReport['has_data'] ?? false);
        $safeMarketAvg = $hasMarketData ? (float)$marketReport['market_avg'] : 0.0;
        $safeCatAvg    = ($catAvgPrice > 0) ? $catAvgPrice : 0.0;
        $bestRef       = $safeMarketAvg > 0 ? $safeMarketAvg : ($safeCatAvg > 0 ? $safeCatAvg : $productPrice);

        $psycho = static function (float $n): float {
            if ($n <= 1) return $n;
            return floor($n) - 0.100;
        };

        if ($hasMarketData) {
            $marketSection = "\nREAL TUNISIAN MARKET DATA (collected from {$marketReport['sources_count']} sources, {$marketReport['data_points']} data points):\n"
                . "- Market average price: {$marketReport['market_avg']} TND\n"
                . "- Market median price:  {$marketReport['market_median']} TND\n"
                . "- Market price range:   {$marketReport['market_min']} – {$marketReport['market_max']} TND\n"
                . "- Market confidence:    {$marketReport['confidence']} ({$marketReport['confidence_score']}/100)\n"
                . "- Seller positioning:   {$marketReport['positioning']} ({$marketReport['positioning_pct']}% vs market avg)\n"
                . "- Psychological price:  {$marketReport['psycho_price']} TND (charm pricing suggestion)";
            if (!empty($marketReport['by_source'])) {
                foreach ($marketReport['by_source'] as $src) {
                    $marketSection .= "\n  • {$src['source']}: {$src['count']} products, avg {$src['avg']} TND (range {$src['min']}–{$src['max']} TND)";
                }
            }
        } else {
            $catContext    = $safeCatAvg > 0
                ? "Platform competitors (outlier-filtered): avg {$safeCatAvg} TND, range {$catMinPrice}–{$catMaxPrice} TND ({$competitorCount} products)."
                : "No platform competitor data for this category.";
            $marketSection = "\nTUNISIAN MARKET DATA: External scraping returned no results.\n"
                . "{$catContext}\n"
                . "COLD-START: Use your knowledge of Tunisian e-commerce to fill all price fields.\n"
                . "- Category: {$product->category_name} | Current price: {$productPrice} TND\n"
                . "- Tunisian avg salary 900-1200 TND/month — price sensitivity HIGH above 200 TND.\n"
                . "- Typical Tunisian e-commerce margins: 15-35% above cost.\n"
                . "- All 4 price fields MUST be specific numbers near {$productPrice} TND (within ±40%). NO zeros.";
        }

        $systemPrompt = "You are a senior Tunisian e-commerce pricing strategist for ChooseTounsi.\n"
            . "You know Tunisian market prices, purchasing power (avg salary 900-1200 TND/month), and e-commerce margins.\n"
            . "RULES: NEVER return 0 or null for any price field. All prices in TND. "
            . "suggested_price must be within ±40% of the seller current price unless there is a very strong reason. "
            . "Always respond with ONLY valid JSON. No markdown. No text outside the JSON object.";

        $userPrompt = "Generate a complete pricing recommendation for this ChooseTounsi product.\n\n"
            . "PRODUCT:\n"
            . "- Name: {$product->name}\n"
            . "- Category: {$product->category_name}\n"
            . "- Current price: {$productPrice} TND\n"
            . "- Stock: {$product->stock} | Views: {$product->views} | Conversion: {$conversionRate}%\n\n"
            . "SALES: {$totalUnits} units sold, {$totalRevenue} TND revenue, avg sold price {$avgSoldPrice} TND\n"
            . "Trend (6 months): {$trendStr}\n"
            . $marketSection . "\n\n"
            . "Return ONLY this JSON — no zeros, no nulls:\n"
            . "{\n"
            . "  \"suggested_price\": <number close to {$productPrice}>,\n"
            . "  \"competitive_price\": <number — matches market/platform avg>,\n"
            . "  \"premium_price\": <number — justified premium ceiling>,\n"
            . "  \"min_profitable_price\": <number — floor, ~85% of current price>,\n"
            . "  \"market_avg_price\": <number — best estimate of Tunisian market avg>,\n"
            . "  \"confidence\": \"high\"|\"medium\"|\"low\",\n"
            . "  \"risk\": \"low\"|\"medium\"|\"high\",\n"
            . "  \"strategy\": \"<name>\",\n"
            . "  \"reasoning\": \"<2-3 sentences specific to this product and its price>\",\n"
            . "  \"expected_impact\": \"<one sentence>\",\n"
            . "  \"market_positioning\": \"underpriced\"|\"competitive\"|\"overpriced\",\n"
            . "  \"competitor_summary\": \"<one sentence naming the actual Tunisian platforms compared e.g. Mytek, Tunisianet, Tayara.tn and the price ranges found>\",\n"
            . "  \"overpriced_warning\": <string or null>,\n"
            . "  \"opportunity_note\": <string or null>,\n"
            . "  \"psychological_tip\": \"<specific e.g. use 128.900 TND instead of 129 TND>\",\n"
            . "  \"platforms_compared\": [\"<platform1>\", \"<platform2>\"],\n"
            . "  \"min_price\": <number>,\n"
            . "  \"max_price\": <number>\n"
            . "}";

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 750);
        $aiResult = null;

        if ($aiRaw) {
            try {
                $clean  = preg_replace('/```json|```/i', '', $aiRaw);
                $start  = strpos($clean, '{');
                $end    = strrpos($clean, '}');
                if ($start !== false && $end !== false) {
                    $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
                    if ($parsed) {
                        $priceFields = ['suggested_price','competitive_price','premium_price','min_profitable_price','market_avg_price','min_price','max_price'];
                        $valid = true;
                        foreach ($priceFields as $f) {
                            if (empty($parsed[$f]) || (float)$parsed[$f] <= 0) { $valid = false; break; }
                        }
                        if ($valid) $aiResult = $parsed;
                        else Log::warning('[SellerAI] Groq returned zero price fields — math fallback.');
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[SellerAI::priceOptimizer] JSON parse failed: ' . $e->getMessage());
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

            if ($hasMarketData) {
                $reasonBase = "Based on {$marketReport['data_points']} real Tunisian market data points (avg: {$marketReport['market_avg']} TND)";
            } elseif ($safeCatAvg > 0) {
                $reasonBase = "Based on {$competitorCount} platform competitors in this category (avg: {$safeCatAvg} TND)";
            } else {
                $reasonBase = "Based on general Tunisian market knowledge for the {$product->category_name} category";
            }

            $salesNote = $totalUnits === 0
                ? "This product has no sales yet — a competitive entry price will help attract first buyers."
                : "With {$totalUnits} units sold, current pricing shows " . ($totalUnits > 20 ? 'solid' : 'early') . " market validation.";

            $aiResult = [
                'suggested_price'      => $suggested,
                'competitive_price'    => $competitive,
                'premium_price'        => $premium,
                'min_profitable_price' => $minProfit,
                'market_avg_price'     => round($bestRef, 3),
                'confidence'           => $hasMarketData ? ($marketReport['confidence'] ?? 'medium') : 'low',
                'risk'                 => 'low',
                'strategy'             => $totalUnits === 0 ? 'Competitive entry pricing' : 'Market-aligned pricing',
                'reasoning'            => "{$reasonBase}, your current price of {$productPrice} TND appears {$positioning} for the Tunisian market. {$salesNote}",
                'expected_impact'      => $totalUnits === 0
                    ? "A competitive entry price should generate your first sales and reviews on ChooseTounsi."
                    : "Aligning with market pricing maintains conversion while optimizing revenue per unit.",
                'market_positioning'   => $positioning,
                'competitor_summary'   => $hasMarketData
                    ? "Tunisian market shows {$marketReport['data_points']} products ranging {$marketReport['market_min']}–{$marketReport['market_max']} TND."
                    : ($safeCatAvg > 0
                        ? "Platform shows {$competitorCount} competitors (avg {$safeCatAvg} TND, range {$catMinPrice}–{$catMaxPrice} TND)."
                        : "No competitor data found. Your price of {$productPrice} TND is your current market anchor."),
                'overpriced_warning'   => $positioningPct > 15
                    ? "Your price is {$positioningPct}% above market average — consider reducing to improve conversion."
                    : null,
                'opportunity_note'     => ($positioningPct < -10)
                    ? "Your price is " . abs($positioningPct) . "% below market average — you may have room to increase without losing buyers."
                    : ($totalUnits === 0
                        ? "No sales yet — ensure your listing has complete images and description to maximize conversion."
                        : null),
                'psychological_tip'    => "Use {$psychoTip} TND instead of {$suggested} TND — charm pricing ending in .900 consistently converts better with Tunisian buyers.",
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
                        'by_source'        => $marketReport['by_source']        ?? [],
                        'scrapers_meta'    => $marketReport['scrapers_meta']    ?? [],
                        'data_source'      => $marketReport['data_source']      ?? 'unknown',
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
    public function salesPredictor(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'season'     => 'required|string|max:50',
        ]);

        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        // ── 1. Core product row (now includes subcategory) ────────────────
        $product = DB::table('products as p')
            ->leftJoin('categories as c',    'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $request->product_id)
            ->whereNull('p.deleted_at')
            ->selectRaw("
                p.id, p.name, p.price, p.stock, p.views,
                p.subcategory_id,
                c.name  as category_name,
                s.name  as subcategory_name,
                s.name_ar as subcategory_name_ar
            ")
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $season = $request->season;

        // ── 2. Historical monthly sales (12 months) ───────────────────────
        $monthlySales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(12))
            ->selectRaw("
                DATE_FORMAT(o.created_at, '%Y-%m') as month,
                SUM(oi.quantity) as units,
                COUNT(DISTINCT oi.order_id) as orders,
                SUM({$totalExpr}) as revenue
            ")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // ── 3. Lifetime stats ─────────────────────────────────────────────
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

        // ── 4. Variant intelligence ───────────────────────────────────────
        // 4a. Summary: count, active count, price range across all variants
        $variantSummary = DB::table('product_variants as pv')
            ->where('pv.product_id', $request->product_id)
            ->selectRaw("
                COUNT(*)                                     as total_variants,
                SUM(CASE WHEN pv.is_active = 1 THEN 1 ELSE 0 END) as active_variants,
                SUM(pv.stock)                                as total_variant_stock,
                MIN(COALESCE(pv.price_override, {$product->price})) as price_min,
                MAX(COALESCE(pv.price_override, {$product->price})) as price_max,
                AVG(COALESCE(pv.price_override, {$product->price})) as price_avg
            ")
            ->first();

        $hasVariants   = (int)($variantSummary->total_variants ?? 0) > 0;
        $activeVarCnt  = (int)($variantSummary->active_variants ?? 0);
        $totalVarCnt   = (int)($variantSummary->total_variants ?? 0);
        $varStockTotal = (int)($variantSummary->total_variant_stock ?? 0);
        $varPriceMin   = round((float)($variantSummary->price_min ?? $product->price), 3);
        $varPriceMax   = round((float)($variantSummary->price_max ?? $product->price), 3);

        // 4b. Top-selling variant combos (color+size that move the most)
        //     Joins: order_items → variant_attribute_values → attribute_options → attributes
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
                    COALESCE(pv.price_override, {$product->price}) as effective_price
                ")
                ->groupBy('oi.variant_id', 'pv.price_override')
                ->orderByDesc('units_sold')
                ->limit(5)
                ->get();
        }

        // 4c. Current stock distribution across variant axes
        //     e.g. which colors/sizes still have stock vs are sold out
        $variantStockByAxis = collect();
        if ($hasVariants) {
            $variantStockByAxis = DB::table('product_variants as pv')
                ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
                ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
                ->join('attributes as a', 'a.id', '=', 'ao.attribute_id')
                ->where('pv.product_id', $request->product_id)
                ->where('pv.is_active', true)
                ->selectRaw("
                    a.slug  as attr_slug,
                    a.name  as attr_name,
                    ao.value as option_value,
                    SUM(pv.stock) as stock_for_option
                ")
                ->groupBy('a.slug', 'a.name', 'ao.value')
                ->orderBy('a.order')
                ->orderByDesc('stock_for_option')
                ->get();
        }

        // 4d. Variant attribute axes (what dimensions exist: color, size, storage…)
        $variantAxes = collect();
        if ($hasVariants) {
            $variantAxes = DB::table('subcategory_attributes as sa')
                ->join('attributes as a', 'a.id', '=', 'sa.attribute_id')
                ->where('sa.subcategory_id', $product->subcategory_id)
                ->where('sa.is_variant', true)
                ->selectRaw("a.slug, a.name")
                ->orderBy('sa.order')
                ->get();
        }

        // ── 5. Info-level product attributes (brand, material, gender…) ───
        //      These live in product_attribute_values (not in variants)
        $infoAttributes = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->join('subcategory_attributes as sa', function ($join) use ($product) {
                $join->on('sa.attribute_id', '=', 'pav.attribute_id')
                     ->where('sa.subcategory_id', '=', $product->subcategory_id)
                     ->where('sa.is_variant', '=', false); // info-only attributes
            })
            ->where('pav.product_id', $request->product_id)
            ->selectRaw("a.slug, a.name, a.type, pav.value")
            ->orderBy('sa.order')
            ->get();

        // Resolve select/multiselect option IDs → human-readable labels
        // Collect all option IDs first for a single batch query
        $allOptionIds = [];
        foreach ($infoAttributes as $attr) {
            if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
                $decoded = json_decode($attr->value, true);
                if (is_array($decoded)) {
                    $allOptionIds = array_merge($allOptionIds, $decoded);
                } elseif (is_numeric($attr->value)) {
                    $allOptionIds[] = (int)$attr->value;
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

        // Build human-readable info attribute map: slug => "Label: Value"
        $infoAttrLines = [];
        foreach ($infoAttributes as $attr) {
            if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
                $decoded = json_decode($attr->value, true);
                $ids     = is_array($decoded) ? $decoded : (is_numeric($attr->value) ? [(int)$attr->value] : []);
                $labels  = array_filter(array_map(fn($id) => $optionLabels[$id] ?? null, $ids));
                if (!empty($labels)) {
                    $infoAttrLines[$attr->slug] = $attr->name . ': ' . implode(', ', $labels);
                }
            } elseif ($attr->type === 'boolean') {
                $infoAttrLines[$attr->slug] = $attr->name . ': ' . ($attr->value ? 'Yes' : 'No');
            } elseif (!empty($attr->value)) {
                $infoAttrLines[$attr->slug] = $attr->name . ': ' . $attr->value;
            }
        }

        // ── 6. Compute derived metrics ────────────────────────────────────
        $bestMonth  = $monthlySales->sortByDesc('units')->first();
        $recentMonths  = $monthlySales->take(-3);
        $recentUnits   = (int)$recentMonths->sum('units');

        $avgMonthlySales  = $monthlySales->isNotEmpty() ? round($monthlySales->avg('units'), 1) : 0;
        $lastMonthSales   = (int)($monthlySales->last()?->units   ?? 0);
        $lastMonthRevenue = round((float)($monthlySales->last()?->revenue ?? 0), 3);
        $historyStr       = $monthlySales->map(fn($r) => "{$r->month}: {$r->units} units ({$r->orders} orders)")->implode(', ') ?: 'No sales yet';
        $totalUnits       = (int)($lifetimeStats->total_units   ?? 0);
        $totalRevenue     = round((float)($lifetimeStats->total_revenue ?? 0), 3);
        $convRate         = ($product->views > 0 && $totalUnits > 0)
            ? round(($lifetimeStats->total_orders / $product->views) * 100, 2)
            : 0;

        // Growth momentum (last 2 months)
        $last2    = $monthlySales->take(-2)->values();
        $momentum = 'stable';
        if ($last2->count() === 2) {
            $diff = (int)$last2[1]->units - (int)$last2[0]->units;
            if ($diff > 0) $momentum = 'growing';
            elseif ($diff < 0) $momentum = 'declining';
        }

        // ── 7. Build product DNA strings for prompt ───────────────────────
        $subcategoryStr = $product->subcategory_name
            ? "{$product->subcategory_name}" . ($product->subcategory_name_ar ? " ({$product->subcategory_name_ar})" : '')
            : 'Not specified';

        // Variant axes e.g. "Color × Size"
        $axesStr = $variantAxes->isNotEmpty()
            ? $variantAxes->map(fn($a) => $a->name)->implode(' × ')
            : 'Simple product (no variants)';

        // Price range string
        $priceRangeStr = ($hasVariants && $varPriceMin !== $varPriceMax)
            ? "{$varPriceMin} – {$varPriceMax} TND across variants"
            : "{$product->price} TND (fixed)";

        // Stock summary
        $stockStr = $hasVariants
            ? "{$varStockTotal} units total across {$activeVarCnt}/{$totalVarCnt} active variants"
            : "{$product->stock} units";

        // Top-selling combos e.g. "color:Red | size:M → 23 units"
        $topVariantStr = $topVariantSales->isNotEmpty()
            ? $topVariantSales->map(fn($v) => "  • {$v->combo_label} → {$v->units_sold} units sold")->implode("\n")
            : ($hasVariants ? '  • No variant sales data yet' : '  • N/A (simple product)');

        // Stock by axis e.g. "color: Red=12, Blue=3, Black=0 | size: M=8, L=7"
        $stockByAxisStr = '';
        if ($variantStockByAxis->isNotEmpty()) {
            $grouped = $variantStockByAxis->groupBy('attr_slug');
            $parts   = [];
            foreach ($grouped as $slug => $options) {
                $optParts = $options->map(fn($o) => "{$o->option_value}={$o->stock_for_option}")->implode(', ');
                $attrName = $options->first()->attr_name;
                $parts[]  = "{$attrName}: {$optParts}";
            }
            $stockByAxisStr = implode(' | ', $parts);
        }

        // Info attributes e.g. "Brand: Nike | Material: Cotton | Gender: Men"
        $infoAttrStr = !empty($infoAttrLines)
            ? implode(' | ', $infoAttrLines)
            : 'No additional attributes';

        // ── 8. Build enriched Groq prompt ─────────────────────────────────
        $systemPrompt = "You are an expert Tunisian e-commerce sales analyst for ChooseTounsi marketplace.\n"
            . "You provide highly accurate, product-specific sales predictions for Tunisian sellers.\n"
            . "You understand Tunisian seasons deeply:\n"
            . "  - Ramadan: fashion/food/gifts peak weeks 2-3, electronics slow\n"
            . "  - Eid al-Fitr: impulse gift buying spike, clothing & accessories up 30-50%\n"
            . "  - Eid al-Adha: home goods, food, family purchases surge\n"
            . "  - Back-to-school (Aug-Sep): stationery, kids clothing, electronics strong\n"
            . "  - Summer: beach/sports/kids toys up, formal clothing down\n"
            . "  - Winter: warm clothing, home living, electronics steady\n"
            . "CRITICAL RULES:\n"
            . "  1. Use the SUBCATEGORY (not just category) to calibrate seasonality — a Dress behaves differently from a Jeans or a Sneaker even inside Fashion.\n"
            . "  2. Use VARIANT data: if top-selling combos show 'color:Red | size:M', predict that color/size awareness will affect demand.\n"
            . "  3. Use INFO ATTRIBUTES: Gender (Men/Women/Kids), Material (Leather/Cotton), Brand all affect seasonal demand curves.\n"
            . "  4. If stockouts are visible in variant stock data, flag that as a risk and factor it into predicted_units.\n"
            . "  5. All numeric fields required. ALWAYS respond with ONLY valid JSON. No markdown. No text outside JSON.";

        $userPrompt = "Predict next-month sales for this ChooseTounsi product. Use ALL product details below.\n\n"

            // ── Product identity
            . "═══ PRODUCT IDENTITY ═══\n"
            . "Name:        {$product->name}\n"
            . "Category:    {$product->category_name}\n"
            . "Subcategory: {$subcategoryStr}\n"
            . "Base price:  {$product->price} TND\n"
            . "Price range: {$priceRangeStr}\n"
            . "Stock:       {$stockStr}\n"
            . "Views:       {$product->views} | Conversion: {$convRate}%\n\n"

            // ── Product attributes (brand, material, gender…)
            . "═══ PRODUCT ATTRIBUTES ═══\n"
            . "{$infoAttrStr}\n\n"

            // ── Variant structure
            . ($hasVariants
                ? "═══ VARIANT STRUCTURE ═══\n"
                . "Axes:        {$axesStr}\n"
                . "Variants:    {$activeVarCnt} active / {$totalVarCnt} total\n"
                . ($stockByAxisStr ? "Stock split: {$stockByAxisStr}\n" : '')
                . "Top sellers:\n{$topVariantStr}\n\n"
                : "═══ PRODUCT TYPE: SIMPLE (no variants) ═══\n\n"
            )

            // ── Sales history
            . "═══ SALES HISTORY (12 months) ═══\n"
            . "{$historyStr}\n"
            . "Avg monthly: {$avgMonthlySales} units | Last month: {$lastMonthSales} units ({$lastMonthRevenue} TND)\n"
            . "Lifetime:    {$totalUnits} units sold | {$totalRevenue} TND revenue\n"
            . "Momentum:    {$momentum}\n\n"

            // ── Season
            . "═══ SEASON TO PREDICT ═══\n"
            . "Season: {$season}\n\n"

            // ── JSON schema
            . "Return ONLY this JSON (all fields required, no nulls):\n"
            . "{\n"
            . "  \"predicted_units\": <integer>,\n"
            . "  \"growth_pct\": <number vs avg monthly>,\n"
            . "  \"trend\": \"up\"|\"down\"|\"stable\",\n"
            . "  \"confidence\": \"high\"|\"medium\"|\"low\",\n"
            . "  \"key_factor\": \"<SPECIFIC reason referencing subcategory+attributes+season, 1-2 sentences>\",\n"
            . "  \"advice\": \"<concrete action — exact qty, variant, timing e.g. 'Restock Red/M and Red/L by 20 units before Eid week 2'>\",\n"
            . "  \"stock_recommendation\": \"<exact total units to stock>\",\n"
            . "  \"promotion_ideas\": [\n"
            . "    \"<idea1 referencing specific variant/attribute e.g. 'Flash sale on Black color — highest stock but low sales'>\",\n"
            . "    \"<idea2>\",\n"
            . "    \"<idea3>\"\n"
            . "  ],\n"
            . "  \"best_selling_week\": \"Week 1\"|\"Week 2\"|\"Week 3\"|\"Week 4\",\n"
            . "  \"weekly_breakdown\": [\n"
            . "    {\"week\": \"Week 1\", \"predicted\": <int>, \"baseline\": <int>},\n"
            . "    {\"week\": \"Week 2\", \"predicted\": <int>, \"baseline\": <int>},\n"
            . "    {\"week\": \"Week 3\", \"predicted\": <int>, \"baseline\": <int>},\n"
            . "    {\"week\": \"Week 4\", \"predicted\": <int>, \"baseline\": <int>}\n"
            . "  ],\n"
            . "  \"risk_factors\": [\n"
            . "    \"<risk1 e.g. 'Red/M stock at 2 units — likely stockout by week 2'>\",\n"
            . "    \"<risk2>\"\n"
            . "  ],\n"
            . "  \"opportunity\": \"<specific untapped opportunity referencing variant/attribute/season e.g. 'Women M/L sizes have high stock but no promotion — bundle with accessories for Eid'>\"\n"
            . "}";

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 900);
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
                Log::warning('[SellerAI::salesPredictor] JSON parse failed: ' . $e->getMessage());
            }
        }

        // ── Math fallback ─────────────────────────────────────────────────
        if (!$aiResult) {
            $seasonMultipliers = [
                'Ramadan'      => 1.35, 'Eid al-Fitr'  => 1.30, 'Eid al-Adha'    => 1.25,
                'Summer'       => 0.92, 'Back to school'=> 1.18, 'Winter'         => 1.10,
                'Spring'       => 1.05, 'Normal'        => 1.0,
            ];
            $mult     = $seasonMultipliers[$season] ?? 1.05;
            $base     = max(1, $avgMonthlySales > 0 ? $avgMonthlySales : 1);
            $pred     = max(1, (int)round($base * $mult));
            $pct      = round(($mult - 1) * 100, 1);
            $weekly   = max(1, (int)round($pred / 4));
            $stockRec = $hasVariants
                ? max($varStockTotal, (int)round($pred * 1.3))
                : max((int)$product->stock, (int)round($pred * 1.3));

            // Build subcategory-aware key_factor
            $subLabel  = $product->subcategory_name ?? $product->category_name;
            $genderStr = $infoAttrLines['gender'] ?? '';
            $brandStr  = $infoAttrLines['brand']  ?? '';
            $matStr    = $infoAttrLines['material'] ?? '';
            $extraCtx  = array_filter([$genderStr, $brandStr, $matStr]);
            $ctxStr    = !empty($extraCtx) ? ' (' . implode(', ', $extraCtx) . ')' : '';

            $aiResult = [
                'predicted_units'     => $pred,
                'growth_pct'          => $pct,
                'trend'               => $mult > 1.02 ? 'up' : ($mult < 0.98 ? 'down' : 'stable'),
                'confidence'          => $avgMonthlySales > 0 ? 'medium' : 'low',
                'key_factor'          => "{$season} typically creates a " . abs($pct) . "% " . ($pct >= 0 ? 'boost' : 'dip') . " for {$subLabel}{$ctxStr} in Tunisia.",
                'advice'              => $pct > 0
                    ? "Increase stock to at least {$stockRec} units before {$season} starts. Consider a 5-10% promotional discount in week 2."
                    : "Offer bundle deals and free shipping to offset the expected " . abs($pct) . "% slowdown. Focus on loyalty buyers.",
                'stock_recommendation'=> (string)$stockRec,
                'promotion_ideas'     => [
                    $pct > 0
                        ? "Launch a {$season} flash sale in week 2 with 10% off" . ($topVariantSales->isNotEmpty() ? " — prioritise your best-selling variant combo" : '')
                        : "Bundle with complementary products for added value",
                    "Boost your ChooseTounsi sponsored placement during peak week",
                    "Prepare stock 2 weeks before {$season} to avoid stockouts",
                ],
                'best_selling_week'   => 'Week 2',
                'weekly_breakdown'    => [
                    ['week' => 'Week 1', 'predicted' => (int)round($weekly * 0.90), 'baseline' => (int)round($base * 0.24)],
                    ['week' => 'Week 2', 'predicted' => (int)round($weekly * 1.10), 'baseline' => (int)round($base * 0.25)],
                    ['week' => 'Week 3', 'predicted' => (int)round($weekly * 1.05), 'baseline' => (int)round($base * 0.26)],
                    ['week' => 'Week 4', 'predicted' => (int)round($weekly * 0.95), 'baseline' => (int)round($base * 0.25)],
                ],
                'risk_factors'  => [
                    $hasVariants && $varStockTotal < $pred
                        ? "Total variant stock ({$varStockTotal} units) may be insufficient for predicted demand ({$pred} units) — restock urgently"
                        : 'Low stock may cause missed sales — restock early',
                    'Competitor promotions during peak season may reduce conversion',
                ],
                'opportunity' => $avgMonthlySales === 0
                    ? "This {$subLabel} has no sales yet — {$season} is a great time to launch with a competitive introductory price."
                    : ($topVariantSales->isNotEmpty()
                        ? "Your top variant ({$topVariantSales->first()->combo_label}) drives most sales — ensure it stays in stock throughout {$season}."
                        : "Cross-sell with complementary products in your store to increase basket size during {$season}."),
            ];
        }

        // ── Build enriched data_context for frontend ──────────────────────
        // Group stock-by-axis for the frontend DNA strip
        $stockByAxisForFrontend = [];
        if ($variantStockByAxis->isNotEmpty()) {
            foreach ($variantStockByAxis->groupBy('attr_slug') as $slug => $options) {
                $stockByAxisForFrontend[$options->first()->attr_name] = $options
                    ->map(fn($o) => ['value' => $o->option_value, 'stock' => (int)$o->stock_for_option])
                    ->values()
                    ->toArray();
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    // ── existing fields (unchanged) ──
                    'product_name'       => $product->name,
                    'avg_monthly_sales'  => $avgMonthlySales,
                    'last_month_sales'   => $lastMonthSales,
                    'last_month_revenue' => $lastMonthRevenue,
                    'monthly_history'    => $monthlySales,
                    'current_stock'      => $hasVariants ? $varStockTotal : (int)$product->stock,
                    'total_units'        => $totalUnits,
                    'total_revenue'      => $totalRevenue,
                    'momentum'           => $momentum,
                    'best_month'         => $bestMonth,
                    'views'              => (int)$product->views,
                    'conversion_rate'    => $convRate,

                    // ── NEW: product DNA fields ──
                    'subcategory'        => $product->subcategory_name,
                    'has_variants'       => $hasVariants,
                    'variant_axes'       => $variantAxes->map(fn($a) => $a->name)->values()->toArray(),
                    'active_variants'    => $activeVarCnt,
                    'total_variants'     => $totalVarCnt,
                    'variant_price_min'  => $hasVariants ? $varPriceMin : null,
                    'variant_price_max'  => $hasVariants ? $varPriceMax : null,
                    'top_variant_sales'  => $topVariantSales->map(fn($v) => [
                        'combo'       => $v->combo_label,
                        'units_sold'  => (int)$v->units_sold,
                        'price'       => round((float)$v->effective_price, 3),
                    ])->values()->toArray(),
                    'stock_by_axis'      => $stockByAxisForFrontend,
                    'info_attributes'    => $infoAttrLines,
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