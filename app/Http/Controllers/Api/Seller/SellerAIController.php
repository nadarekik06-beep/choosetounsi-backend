<?php
// app/Http/Controllers/Api/Seller/SellerAIController.php
//
// ONLY priceOptimizer() is modified in this file.
// All other methods (salesPredictor, descriptionGenerator,
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
    // 1. PRICE OPTIMIZER  ← FULLY REWRITTEN WITH 3-LAYER ARCHITECTURE
    //    POST /api/seller/ai/price-optimizer
    //    Body: { product_id: int }
    // ═══════════════════════════════════════════════════════════════════════
    public function priceOptimizer(Request $request)
    {
        $request->validate(['product_id' => 'required|integer']);

        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        // ── LAYER 1A: Fetch target product ────────────────────────────────
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

        // ── LAYER 1B: Internal platform analytics ─────────────────────────

        // Sales history for this product
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

        // ── SMART COMPETITOR QUERY ────────────────────────────────────────
        // Strategy: price-band filtering FIRST, then STDDEV.
        // Only include products within 0.25×–4× of the product price.
        // This prevents a category mixing MacBooks + USB cables from
        // producing a meaningless average of 400 TND.
        $productPrice = (float)$product->price;
        $priceLow     = $productPrice * 0.25;
        $priceHigh    = $productPrice * 4.0;

        // Step 1: STDDEV on price-band-filtered competitors
        $priceStdDevRow = DB::table('products as p')
            ->where('p.category_id', $product->category_id)
            ->where('p.id', '!=', $request->product_id)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->where('p.price', '>=', $priceLow)
            ->where('p.price', '<=', $priceHigh)
            ->selectRaw("
                AVG(p.price)    as avg_price,
                STDDEV(p.price) as std_price,
                COUNT(*)        as count
            ")
            ->first();

        // Step 2: tighten with STDDEV (avg ± 2×stddev inside the band)
        $catAvgRaw  = (float)($priceStdDevRow->avg_price ?? 0);
        $catStd     = (float)($priceStdDevRow->std_price ?? 0);
        $lowerBound = $catStd > 0
            ? max($priceLow,  $catAvgRaw - 2.0 * $catStd)
            : $priceLow;
        $upperBound = $catStd > 0
            ? min($priceHigh, $catAvgRaw + 2.0 * $catStd)
            : $priceHigh;

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

        // Monthly sales trend (last 6 months)
        $monthlySales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(oi.quantity) as units")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Conversion rate (views → orders)
        $conversionRate = 0;
        if (($product->views ?? 0) > 0 && ($salesHistory->total_orders ?? 0) > 0) {
            $conversionRate = round(($salesHistory->total_orders / $product->views) * 100, 2);
        }

        // Prepare Layer 1 metrics
        $totalUnits    = (int)($salesHistory->total_units ?? 0);
        $totalRevenue  = round((float)($salesHistory->total_revenue ?? 0), 3);
        $avgSoldPrice  = round((float)($salesHistory->avg_sold_price ?? $product->price), 3);
        $competitorCount = (int)($similarProducts->count ?? 0);
        $trendStr      = $monthlySales->map(fn($r) => "{$r->month}: {$r->units} units")->implode(', ') ?: 'No sales history';

        // catAvgPrice is now price-band filtered: only products at 0.25×–4× current price
        $catAvgPrice  = round((float)($similarProducts->avg_price ?? 0), 3);
        $catMinPrice  = round((float)($similarProducts->min_price ?? 0), 3);
        $catMaxPrice  = round((float)($similarProducts->max_price ?? 0), 3);


        // ── LAYER 2: Tunisian market intelligence ─────────────────────────
        $marketReport = ['has_data' => false];

        try {
            $marketSvc    = new MarketIntelligenceService(new PriceNormalizationService());
            $marketReport = $marketSvc->analyze(
                $product->name,
                $product->category_name ?? 'General',
                (float)$product->price
            );
        } catch (\Throwable $e) {
            Log::warning("[SellerAI::priceOptimizer] Market intelligence failed: " . $e->getMessage());
        }

        // ── Compute a SAFE price reference for math fallback ─────────────
        // Priority: 1) real market avg  2) valid platform cat avg  3) product price itself
        $hasMarketData = (bool)($marketReport['has_data'] ?? false);
        $safeMarketAvg = $hasMarketData ? (float)$marketReport['market_avg'] : 0.0;
        $safeCatAvg    = ($catAvgPrice > 0) ? $catAvgPrice : 0.0;
        $bestRef       = $safeMarketAvg > 0
            ? $safeMarketAvg
            : ($safeCatAvg > 0 ? $safeCatAvg : $productPrice);

        // Psycho-price helper: nearest X.900 below a number
        $psycho = static function (float $n): float {
            if ($n <= 1) return $n;
            return floor($n) - 0.100;
        };

        // ── LAYER 3: Groq reasoning engine ────────────────────────────────
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
            $catContext = $safeCatAvg > 0
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
            . "  \"platforms_compared\": [\"<platform1>\", \"<platform2>\"],\n"            . "  \"min_price\": <number>,\n"
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
                        // Validate: no price field may be zero or null
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

        // ── Math fallback — always produces plausible prices ──────────────
        if (!$aiResult) {
            // Clamp suggested within ±20% of current price — never explode
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
                'suggested_price'     => $suggested,
                'competitive_price'   => $competitive,
                'premium_price'       => $premium,
                'min_profitable_price'=> $minProfit,
                'market_avg_price'    => round($bestRef, 3),
                'confidence'          => $hasMarketData ? ($marketReport['confidence'] ?? 'medium') : 'low',
                'risk'                => 'low',
                'strategy'            => $totalUnits === 0 ? 'Competitive entry pricing' : 'Market-aligned pricing',
                'reasoning'           => "{$reasonBase}, your current price of {$productPrice} TND appears {$positioning} for the Tunisian market. {$salesNote}",
                'expected_impact'     => $totalUnits === 0
                    ? "A competitive entry price should generate your first sales and reviews on ChooseTounsi."
                    : "Aligning with market pricing maintains conversion while optimizing revenue per unit.",
                'market_positioning'  => $positioning,
                'competitor_summary'  => $hasMarketData
                    ? "Tunisian market shows {$marketReport['data_points']} products ranging {$marketReport['market_min']}–{$marketReport['market_max']} TND."
                    : ($safeCatAvg > 0
                        ? "Platform shows {$competitorCount} competitors (avg {$safeCatAvg} TND, range {$catMinPrice}–{$catMaxPrice} TND)."
                        : "No competitor data found. Your price of {$productPrice} TND is your current market anchor."),
                'overpriced_warning'  => $positioningPct > 15
                    ? "Your price is {$positioningPct}% above market average — consider reducing to improve conversion."
                    : null,
                'opportunity_note'    => ($positioningPct < -10)
                    ? "Your price is " . abs($positioningPct) . "% below market average — you may have room to increase without losing buyers."
                    : ($totalUnits === 0
                        ? "No sales yet — ensure your listing has complete images and description to maximize conversion."
                        : null),
                'psychological_tip'   => "Use {$psychoTip} TND instead of {$suggested} TND — charm pricing ending in .900 consistently converts better with Tunisian buyers.",
                'min_price'           => $minPrice,
                'max_price'           => $maxPrice,
            ];
        }

        // ── Build response ────────────────────────────────────────────────
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
    // 2. SALES PREDICTOR — UNCHANGED
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

        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $request->product_id)
            ->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.name, p.price, p.stock, p.views, c.name as category_name")
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $season = $request->season;

        // ── Historical monthly sales (12 months) ──────────────────────────
        $monthlySales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(12))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(oi.quantity) as units, COUNT(DISTINCT oi.order_id) as orders, SUM({$totalExpr}) as revenue")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // ── Lifetime stats ────────────────────────────────────────────────
        $lifetimeStats = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->selectRaw("SUM(oi.quantity) as total_units, SUM({$totalExpr}) as total_revenue, COUNT(DISTINCT oi.order_id) as total_orders")
            ->first();

        // ── Best & worst month ────────────────────────────────────────────
        $bestMonth  = $monthlySales->sortByDesc('units')->first();
        $worstMonth = $monthlySales->filter(fn($m) => $m->units > 0)->sortBy('units')->first();

        // ── Revenue trend (last 3 months) ─────────────────────────────────
        $recentMonths   = $monthlySales->take(-3);
        $recentRevenue  = round((float)$recentMonths->sum('revenue'), 3);
        $recentUnits    = (int)$recentMonths->sum('units');

        $avgMonthlySales  = $monthlySales->isNotEmpty() ? round($monthlySales->avg('units'), 1) : 0;
        $lastMonthSales   = (int)($monthlySales->last()?->units ?? 0);
        $lastMonthRevenue = round((float)($monthlySales->last()?->revenue ?? 0), 3);
        $historyStr       = $monthlySales->map(fn($r) => "{$r->month}: {$r->units} units ({$r->orders} orders)")->implode(', ') ?: 'No sales yet';
        $totalUnits       = (int)($lifetimeStats->total_units ?? 0);
        $totalRevenue     = round((float)($lifetimeStats->total_revenue ?? 0), 3);
        $convRate         = ($product->views > 0 && $totalUnits > 0)
            ? round(($lifetimeStats->total_orders / $product->views) * 100, 2)
            : 0;

        // Detect growth momentum
        $last2 = $monthlySales->take(-2)->values();
        $momentum = 'stable';
        if ($last2->count() === 2) {
            $diff = (int)$last2[1]->units - (int)$last2[0]->units;
            if ($diff > 0) $momentum = 'growing';
            elseif ($diff < 0) $momentum = 'declining';
        }

        // ── Enriched Groq prompt ──────────────────────────────────────────
        $systemPrompt = "You are an expert Tunisian e-commerce sales analyst for ChooseTounsi marketplace.
"
            . "You provide highly actionable, data-driven sales predictions for Tunisian sellers.
"
            . "You know Tunisian seasons deeply: Ramadan (Sfax/Tunis shopping peaks in weeks 2-3), "
            . "Eid al-Fitr (impulse buying spike), Back-to-school (August-September surge), "
            . "Summer (beach/tourism products up, clothing sometimes down).
"
            . "RULES: All fields required. Give SPECIFIC, CONCRETE seller actions (not generic advice). "
            . "ALWAYS respond with ONLY valid JSON. No markdown. No text outside JSON.";

        $userPrompt = "Predict next-month sales and guide this ChooseTounsi seller:\n\n"
            . "PRODUCT: {$product->name} | Category: {$product->category_name} | Price: {$product->price} TND\n"
            . "Stock: {$product->stock} units | Views: {$product->views} | Conversion: {$convRate}%\n\n"
            . "SALES HISTORY (12 months): {$historyStr}\n"
            . "Avg monthly: {$avgMonthlySales} units | Last month: {$lastMonthSales} units ({$lastMonthRevenue} TND)\n"
            . "Lifetime: {$totalUnits} units sold | {$totalRevenue} TND revenue\n"
            . "Momentum: {$momentum}\n"
            . "SEASON REQUESTED: {$season}\n\n"
            . "Return ONLY this JSON (all fields required, no nulls):\n"
            . "{\n"
            . "  \"predicted_units\": <integer>,\n"
            . "  \"growth_pct\": <number>,\n"
            . "  \"trend\": \"up\"|\"down\"|\"stable\",\n"
            . "  \"confidence\": \"high\"|\"medium\"|\"low\",\n"
            . "  \"key_factor\": \"<specific seasonal reason, 1 sentence>\",\n"
            . "  \"advice\": \"<concrete action with exact qty and timing>\",\n"
            . "  \"stock_recommendation\": \"<exact units to stock>\",\n"
            . "  \"promotion_ideas\": [\"<idea1>\", \"<idea2>\", \"<idea3>\"],\n"
            . "  \"best_selling_week\": \"Week 1\"|\"Week 2\"|\"Week 3\"|\"Week 4\",\n"
            . "  \"weekly_breakdown\": [\n"
            . "    {\"week\": \"Week 1\", \"predicted\": <int>, \"baseline\": <int>},\n"
            . "    {\"week\": \"Week 2\", \"predicted\": <int>, \"baseline\": <int>},\n"
            . "    {\"week\": \"Week 3\", \"predicted\": <int>, \"baseline\": <int>},\n"
            . "    {\"week\": \"Week 4\", \"predicted\": <int>, \"baseline\": <int>}\n"
            . "  ],\n"
            . "  \"risk_factors\": [\"<risk1>\", \"<risk2>\"],\n"
            . "  \"opportunity\": \"<one key opportunity for this product this season>\"\n"
            . "}";

        $aiRaw    = $this->callGroq($systemPrompt, $userPrompt, 750);
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

        // ── Math fallback ─────────────────────────────────────────────────
        if (!$aiResult) {
            $seasonMultipliers = [
                'Ramadan'=>1.35,'Eid al-Fitr'=>1.30,'Eid al-Adha'=>1.25,
                'Summer'=>0.92,'Back to school'=>1.18,'Winter'=>1.10,
                'Spring'=>1.05,'Normal'=>1.0,
            ];
            $mult    = $seasonMultipliers[$season] ?? 1.05;
            $base    = max(1, $avgMonthlySales > 0 ? $avgMonthlySales : 1);
            $pred    = max(1, (int)round($base * $mult));
            $pct     = round(($mult - 1) * 100, 1);
            $weekly  = max(1, (int)round($pred / 4));
            $stockRec = max($product->stock, (int)round($pred * 1.3));

            $aiResult = [
                'predicted_units'    => $pred,
                'growth_pct'         => $pct,
                'trend'              => $mult > 1.02 ? 'up' : ($mult < 0.98 ? 'down' : 'stable'),
                'confidence'         => $avgMonthlySales > 0 ? 'medium' : 'low',
                'key_factor'         => "{$season} typically creates a " . abs($pct) . "% " . ($pct >= 0 ? 'boost' : 'dip') . " for {$product->category_name} products in Tunisia.",
                'advice'             => $pct > 0
                    ? "Increase stock to at least {$stockRec} units before {$season} starts. Consider a 5-10% promotional discount in week 2."
                    : "Offer bundle deals and free shipping to offset the expected " . abs($pct) . "% slowdown. Focus on loyalty buyers.",
                'stock_recommendation'=> (string)$stockRec,
                'promotion_ideas'    => [
                    $pct > 0 ? "Launch a {$season} flash sale in week 2 with 10% off" : "Bundle with complementary products for added value",
                    "Boost your ChooseTounsi sponsored placement during peak week",
                    "Prepare stock 2 weeks before {$season} to avoid stockouts",
                ],
                'best_selling_week'  => 'Week 2',
                'weekly_breakdown'   => [
                    ['week'=>'Week 1','predicted'=>(int)round($weekly*0.90),'baseline'=>(int)round($base*0.24)],
                    ['week'=>'Week 2','predicted'=>(int)round($weekly*1.10),'baseline'=>(int)round($base*0.25)],
                    ['week'=>'Week 3','predicted'=>(int)round($weekly*1.05),'baseline'=>(int)round($base*0.26)],
                    ['week'=>'Week 4','predicted'=>(int)round($weekly*0.95),'baseline'=>(int)round($base*0.25)],
                ],
                'risk_factors'  => ['Low stock may cause missed sales — restock early', 'Competitor promotions during peak season'],
                'opportunity'   => $avgMonthlySales === 0
                    ? "This product has no sales yet — {$season} is a great time to launch with a competitive introductory price."
                    : "Cross-sell with top products in your store to increase basket size during {$season}.",
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    'product_name'      => $product->name,
                    'avg_monthly_sales' => $avgMonthlySales,
                    'last_month_sales'  => $lastMonthSales,
                    'last_month_revenue'=> $lastMonthRevenue,
                    'monthly_history'   => $monthlySales,
                    'current_stock'     => (int)$product->stock,
                    'total_units'       => $totalUnits,
                    'total_revenue'     => $totalRevenue,
                    'momentum'          => $momentum,
                    'best_month'        => $bestMonth,
                    'views'             => (int)$product->views,
                    'conversion_rate'   => $convRate,
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

        $coPurchasedStr  = $coPurchased->map(fn($p) => "{$p->name} ({$p->co_count}x co-purchased)")->implode(', ') ?: 'No co-purchase data yet';
        $categoryStr     = $sameCategoryProducts->pluck('name')->implode(', ') ?: 'No other products in category';
        $otherProductsArr = $coPurchased->isNotEmpty() ? $coPurchased->pluck('name') : $sameCategoryProducts->pluck('name');
        $otherProductsList = $otherProductsArr->implode('", "');

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
                    'recommendations'  => array_map(fn($name)=>['product_name'=>$name,'reason'=>"Co-purchased with {$mainProduct->name} based on real buyer behavior.",'placement'=>'also_bought','est_click_rate'=>'12-18%'],array_slice($companions,0,5)),
                    'placement_strategy'=>'Show on product detail page under "Customers also bought" section.',
                    'best_time_to_show' =>'After adding to cart and on checkout page.',
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