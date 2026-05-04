<?php
// app/Http/Controllers/Api/Seller/SellerAIController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * SellerAIController
 *
 * Provides 4 AI-powered endpoints for Red/Black Pepper sellers.
 * Each endpoint:
 *   1. Fetches REAL data from the database for this seller
 *   2. Builds a rich context-aware prompt
 *   3. Calls Groq (free LLM) via the existing AIController pattern
 *   4. Returns structured JSON + a data_context payload so the
 *      frontend can show "based on your real data" confirmations.
 *
 * Routes (add to api.php inside the seller prefix group):
 *   POST /api/seller/ai/price-optimizer
 *   POST /api/seller/ai/sales-predictor
 *   POST /api/seller/ai/description-generator
 *   POST /api/seller/ai/recommender
 *
 * All routes are already gated by auth:sanctum.
 * Add the SellerPlanMiddleware to further gate by plan.
 */
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

    /**
     * Central Groq caller — shared across all 4 tools.
     */
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
    // 1. PRICE OPTIMIZER
    //    POST /api/seller/ai/price-optimizer
    //    Body: { product_id: int }
    // ═══════════════════════════════════════════════════════════════════════
    public function priceOptimizer(Request $request)
    {
        $request->validate(['product_id' => 'required|integer']);
        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();
        $totalExpr = $this->totalExpr();

        // ── Fetch the target product ───────────────────────────────────────
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

        // ── Sales history for this product ─────────────────────────────────
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

        // ── Similar products in same category ─────────────────────────────
        $similarProducts = DB::table('products as p')
            ->where('p.category_id', DB::table('products')->where('id', $request->product_id)->value('category_id'))
            ->where('p.id', '!=', $request->product_id)
            ->where('p.is_approved', true)
            ->whereNull('p.deleted_at')
            ->selectRaw("AVG(p.price) as avg_price, MIN(p.price) as min_price, MAX(p.price) as max_price, COUNT(*) as count")
            ->first();

        // ── Monthly sales trend (last 6 months) ───────────────────────────
        $monthlySales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(oi.quantity) as units")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // ── Build prompt ───────────────────────────────────────────────────
        $totalUnits   = (int)($salesHistory->total_units ?? 0);
        $totalRevenue = round((float)($salesHistory->total_revenue ?? 0), 3);
        $avgSoldPrice = round((float)($salesHistory->avg_sold_price ?? $product->price), 3);
        $catAvgPrice  = round((float)($similarProducts->avg_price ?? $product->price), 3);
        $catMinPrice  = round((float)($similarProducts->min_price ?? $product->price * 0.7), 3);
        $catMaxPrice  = round((float)($similarProducts->max_price ?? $product->price * 1.5), 3);

        $trendStr = $monthlySales->map(fn($r) => "{$r->month}: {$r->units} units")->implode(', ');

        $systemPrompt = "You are a Tunisian e-commerce pricing expert for ChooseTounsi marketplace.
Analyze product pricing and suggest the optimal price for the Tunisian market.
Consider: market competition, demand signals, Tunisian buying behavior, and local purchasing power.
ALWAYS respond with ONLY valid JSON. No markdown. No text outside the JSON object.";

        $userPrompt = <<<PROMPT
Analyze and suggest optimal pricing for this ChooseTounsi product:

PRODUCT:
- Name: {$product->name}
- Category: {$product->category_name}
- Current price: {$product->price} TND
- Stock: {$product->stock} units
- Total views: {$product->views}

SALES PERFORMANCE:
- Total units sold: {$totalUnits}
- Total revenue: {$totalRevenue} TND
- Average sold price: {$avgSoldPrice} TND
- Monthly trend: {$trendStr}

CATEGORY MARKET DATA:
- Avg competitor price: {$catAvgPrice} TND
- Price range: {$catMinPrice} – {$catMaxPrice} TND
- Competitors in category: {$similarProducts->count}

Respond with ONLY this JSON structure:
{
  "suggested_price": <number>,
  "confidence": "high"|"medium"|"low",
  "reasoning": "<2-3 sentences explaining the recommendation>",
  "strategy": "<pricing strategy name>",
  "risk": "low"|"medium"|"high",
  "min_price": <number>,
  "max_price": <number>,
  "expected_impact": "<one sentence about expected sales/revenue impact>"
}
PROMPT;

        $aiRaw = $this->callGroq($systemPrompt, $userPrompt, 500);

        // ── Parse or fallback ──────────────────────────────────────────────
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

        // Math fallback when AI is unavailable
        if (!$aiResult) {
            $catMultiplier = $catAvgPrice > 0 ? min(1.20, max(0.90, $catAvgPrice / $product->price)) : 1.05;
            $demandBoost   = $totalUnits > 50 ? 1.08 : ($totalUnits > 10 ? 1.04 : 1.0);
            $suggested     = round($product->price * $catMultiplier * $demandBoost, 3);
            $aiResult = [
                'suggested_price'  => $suggested,
                'confidence'       => 'medium',
                'reasoning'        => "Based on category market data (avg: {$catAvgPrice} TND) and your sales velocity of {$totalUnits} units, a price adjustment is recommended. Your current price is " . ($suggested > $product->price ? 'below' : 'above') . " the market average.",
                'strategy'         => 'Market-competitive pricing',
                'risk'             => 'low',
                'min_price'        => round($product->price * 0.92, 3),
                'max_price'        => round($product->price * 1.25, 3),
                'expected_impact'  => 'Moderate improvement in revenue with minimal impact on conversion rate.',
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    'product_name'   => $product->name,
                    'current_price'  => (float)$product->price,
                    'total_units'    => $totalUnits,
                    'total_revenue'  => $totalRevenue,
                    'category_avg'   => $catAvgPrice,
                    'monthly_trend'  => $monthlySales,
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2. SALES PREDICTOR
    //    POST /api/seller/ai/sales-predictor
    //    Body: { product_id: int, season: string }
    // ═══════════════════════════════════════════════════════════════════════
    public function salesPredictor(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'season'     => 'required|string|max:50',
        ]);

        $sellerId  = auth()->id();
        $sellerCol = $this->sellerCol();

        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->where('p.id', $request->product_id)
            ->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.name, p.price, p.stock, c.name as category_name")
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        // ── Monthly sales last 12 months ───────────────────────────────────
        $monthlySales = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.product_id', $request->product_id)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(12))
            ->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m') as month, SUM(oi.quantity) as units, COUNT(DISTINCT oi.order_id) as orders")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // ── This seller's overall monthly trend ───────────────────────────
        $totalMonthlySales = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->whereIn('o.status', ['completed', 'delivered'])
            ->where('o.created_at', '>=', Carbon::now()->subMonths(3))
            ->selectRaw("SUM(oi.quantity) as total_units")
            ->value('total_units') ?? 0;

        $avgMonthlySales = $monthlySales->isNotEmpty()
            ? round($monthlySales->avg('units'), 1)
            : 0;

        $lastMonthSales = $monthlySales->last()?->units ?? 0;

        $historyStr = $monthlySales->map(fn($r) => "{$r->month}: {$r->units} units")->implode(', ');
        $season     = $request->season;

        $systemPrompt = "You are a Tunisian e-commerce sales forecasting expert for ChooseTounsi marketplace.
You predict sales based on historical data and Tunisian seasonal patterns.
Key Tunisian seasons: Ramadan (large boost), Eid al-Fitr, Eid al-Adha, Summer, Back-to-school, Winter holidays.
ALWAYS respond with ONLY valid JSON. No markdown. No text outside the JSON.";

        $userPrompt = <<<PROMPT
Predict sales for the next month for this ChooseTounsi product during {$season}:

PRODUCT:
- Name: {$product->name}
- Category: {$product->category_name}
- Price: {$product->price} TND
- Current Stock: {$product->stock}

HISTORICAL SALES (last 12 months):
{$historyStr}
- Average monthly units: {$avgMonthlySales}
- Last month units: {$lastMonthSales}

SEASON: {$season}

Respond with ONLY this JSON:
{
  "predicted_units": <integer>,
  "growth_pct": <number>,
  "trend": "up"|"down"|"stable",
  "confidence": "high"|"medium"|"low",
  "key_factor": "<main reason for prediction>",
  "advice": "<actionable advice for stock/marketing>",
  "weekly_breakdown": [
    {"week": "Week 1", "predicted": <int>, "baseline": <int>},
    {"week": "Week 2", "predicted": <int>, "baseline": <int>},
    {"week": "Week 3", "predicted": <int>, "baseline": <int>},
    {"week": "Week 4", "predicted": <int>, "baseline": <int>}
  ],
  "risk_factors": ["<risk1>", "<risk2>"]
}
PROMPT;

        $aiRaw = $this->callGroq($systemPrompt, $userPrompt, 600);

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
            $seasonMultipliers = [
                'Ramadan' => 1.35, 'Eid al-Fitr' => 1.30, 'Eid al-Adha' => 1.25,
                'Summer'  => 0.92, 'Back to school' => 1.18, 'Winter' => 1.10,
                'Spring'  => 1.05, 'Normal'  => 1.0,
            ];
            $mult  = $seasonMultipliers[$season] ?? 1.05;
            $pred  = max(1, (int)round($avgMonthlySales * $mult));
            $pct   = round(($mult - 1) * 100, 1);
            $weekly = round($pred / 4);

            $aiResult = [
                'predicted_units' => $pred,
                'growth_pct'      => $pct,
                'trend'           => $mult > 1.02 ? 'up' : ($mult < 0.98 ? 'down' : 'stable'),
                'confidence'      => 'medium',
                'key_factor'      => "{$season} typically creates a " . abs($pct) . "% " . ($pct >= 0 ? 'boost' : 'dip') . " for {$product->category_name} products in Tunisia.",
                'advice'          => $pct > 0
                    ? "Increase stock by at least " . (int)round($pct * 0.8) . "% before {$season} starts. Consider promotions in the week before."
                    : "Run bundle offers and discounts to offset the expected " . abs($pct) . "% slowdown.",
                'weekly_breakdown' => [
                    ['week' => 'Week 1', 'predicted' => (int)round($weekly * 0.90), 'baseline' => (int)round($avgMonthlySales * 0.24)],
                    ['week' => 'Week 2', 'predicted' => (int)round($weekly * 1.05), 'baseline' => (int)round($avgMonthlySales * 0.25)],
                    ['week' => 'Week 3', 'predicted' => (int)round($weekly * 1.10), 'baseline' => (int)round($avgMonthlySales * 0.26)],
                    ['week' => 'Week 4', 'predicted' => (int)round($weekly * 0.95), 'baseline' => (int)round($avgMonthlySales * 0.25)],
                ],
                'risk_factors' => ['Supply chain delays', 'Competitor price cuts'],
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'ai_result'    => $aiResult,
                'data_context' => [
                    'product_name'        => $product->name,
                    'avg_monthly_sales'   => $avgMonthlySales,
                    'last_month_sales'    => (int)$lastMonthSales,
                    'monthly_history'     => $monthlySales,
                    'current_stock'       => (int)$product->stock,
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. DESCRIPTION GENERATOR
    //    POST /api/seller/ai/description-generator
    //    Body: { product_id: int, tone?: string, language?: string }
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

        // ── Fetch existing attribute values for richer context ─────────────
        $attributes = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('pav.product_id', $request->product_id)
            ->selectRaw("a.name as attr_name, pav.value")
            ->get()
            ->map(fn($r) => "{$r->attr_name}: {$r->value}")
            ->implode(', ');

        // ── What customers searched for to find this product ───────────────
        $salesCount = DB::table('order_items')
            ->where('product_id', $request->product_id)
            ->sum('quantity');

        $tone     = $request->input('tone', 'professional');
        $language = $request->input('language', 'fr');

        $langInstructions = match($language) {
            'ar'    => 'Write the title, description, and meta in Arabic (Modern Standard Arabic). Keep keywords in English.',
            'fr'    => 'Write in French. Title, description, and meta in French. Keep keywords in both French and Tunisian transliterations.',
            default => 'Write in English. Optimize for international and Tunisian diaspora buyers.',
        };

        $systemPrompt = "You are an expert SEO copywriter for ChooseTounsi Tunisian e-commerce marketplace.
{$langInstructions}
Tone: {$tone}. Write compelling product content that converts Tunisian buyers.
ALWAYS respond with ONLY valid JSON. No markdown. No text outside JSON.";

        $userPrompt = <<<PROMPT
Generate SEO-optimized product content for this ChooseTounsi listing:

PRODUCT:
- Name: {$product->name}
- Category: {$product->category_name}
- Subcategory: {$product->subcategory_name}
- Price: {$product->price} TND
- SKU: {$product->sku}
- Current description: {$product->description}
- Attributes: {$attributes}
- Total units sold: {$salesCount}

Respond with ONLY this JSON:
{
  "title": "<optimized product title, max 80 chars>",
  "short_description": "<compelling hook, 1-2 sentences, max 160 chars>",
  "description": "<full SEO description, 150-250 words, include benefits and Tunisian context>",
  "keywords": ["<kw1>", "<kw2>", "<kw3>", "<kw4>", "<kw5>", "<kw6>"],
  "meta_title": "<meta title max 60 chars>",
  "meta_description": "<meta description max 160 chars>",
  "call_to_action": "<one compelling CTA sentence>"
}
PROMPT;

        $aiRaw = $this->callGroq($systemPrompt, $userPrompt, 700);

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
                'description'       => "Faites confiance à {$product->name} pour répondre à vos besoins quotidiens. Ce produit de qualité dans la catégorie {$cat} est conçu pour les consommateurs tunisiens exigeants. " . ($attributes ? "Caractéristiques: {$attributes}. " : '') . "Disponible en stock avec livraison express. Commandez maintenant sur ChooseTounsi et recevez votre commande rapidement. Qualité garantie ou remboursé.",
                'keywords'          => ['tunisien', 'artisanal', $cat, 'choosetounsi', 'livraison', 'qualité', 'authentique', 'meilleur prix'],
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
// 4. QUICK DESCRIPTION (inline, no product_id required)
//    POST /api/seller/ai/quick-description
//    Body: { name, category?, short_description?, tone?, language? }
//    Used by the Add Product modal before the product is saved.
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

    // ── Price positioning ─────────────────────────────────────────────────
    $priceLabel = match (true) {
        $price >= 500 => 'Luxury / Ultra-premium',
        $price >= 200 => 'Premium / High-end',
        $price >= 80  => 'Mid-range / Quality',
        $price >= 30  => 'Value / Accessible',
        default       => 'Budget-friendly',
    };

    // ── Attribute string ──────────────────────────────────────────────────
    $attrParts = [];
    foreach ($attributes as $slug => $val) {
        if ($val !== null && $val !== '') {
            $attrParts[] = ucfirst(str_replace('_', ' ', (string) $slug)) . ': ' . $val;
        }
    }
    $attrStr = implode(' | ', $attrParts);

    // ── Variant string ────────────────────────────────────────────────────
    $variantStr = !empty($variants) ? implode(', ', $variants) : '';

    // ── Language instruction ───────────────────────────────────────────────
    $langInstruction = match ($language) {
        'ar'    => 'Write title, short_description, description, meta_title, meta_description in Arabic (Modern Standard Arabic). Keep keywords in English.',
        'fr'    => 'Write title, short_description, description, meta_title, meta_description in French.',
        default => 'Write everything in English. Optimize for Tunisian diaspora and international buyers.',
    };

    // ── Tone instruction ───────────────────────────────────────────────────
    $toneInstruction = match ($tone) {
        'casual'        => 'Friendly, conversational, relatable. Simple sentences, everyday language.',
        'exciting'      => 'High energy, bold, enthusiastic. Create urgency and strong desire.',
        'trust-focused' => 'Emphasize reliability, quality guarantees, social proof, and after-sales support.',
        default         => 'Clear, authoritative, benefits-first. Professional and credible.',
    };

    // ── Build context ──────────────────────────────────────────────────────
    $contextLines = array_filter([
        "PRODUCT NAME: {$name}",
        "CATEGORY: {$category}",
        $price > 0    ? "PRICE: {$price} TND  →  Positioning: {$priceLabel}" : null,
        $imageCount   ? "PRODUCT PHOTOS: {$imageCount} image(s) provided"      : null,
        $variantStr   ? "AVAILABLE VARIANTS: {$variantStr}"                     : null,
        $attrStr      ? "PRODUCT ATTRIBUTES: {$attrStr}"                        : null,
        $shortDesc    ? "SELLER DRAFT (improve this): {$shortDesc}"             : null,
    ]);
    $context = implode("\n", $contextLines);

    $systemPrompt = <<<SYSTEM
You are an expert SEO copywriter for ChooseTounsi, Tunisia's leading multi-vendor e-commerce marketplace.
{$langInstruction}
Tone: {$toneInstruction}
ALWAYS structure the description as: Hook → Value Proposition → Key Features/Benefits → Trust Elements → Call to Action.
Use the product data provided to write something SPECIFIC, not generic.
ALWAYS respond with ONLY valid JSON. No markdown fences. No text outside the JSON object.
SYSTEM;

    $userPrompt = <<<PROMPT
Generate a high-conversion, SEO-optimized product listing for ChooseTounsi:

{$context}

Requirements:
- Highlight BENEFITS, not just features
- Use specific product details (variants, attributes, price positioning)
- Include Tunisian market context (local relevance, delivery trust, Tunisian buyer habits)
- Strictly follow the tone and language specified
- Description must feel written for THIS specific product, not a generic template

Respond with ONLY this JSON (no extra text):
{
  "title": "<optimized product title, max 80 chars>",
  "short_description": "<compelling hook sentence, 1-2 sentences, max 160 chars>",
  "description": "<full structured description, 180-280 words, paragraphs not bullets>",
  "keywords": ["<kw1>", "<kw2>", "<kw3>", "<kw4>", "<kw5>", "<kw6>"],
  "meta_title": "<SEO meta title, max 60 chars>",
  "meta_description": "<SEO meta description, max 160 chars>",
  "call_to_action": "<one powerful CTA tailored to Tunisian buyers>"
}
PROMPT;

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

    // ── Deterministic fallback ─────────────────────────────────────────────
    if (!$aiResult) {
        $variantNote = $variantStr ? " Disponible en: {$variantStr}." : '';
        $attrNote    = $attrStr    ? " Caractéristiques: {$attrStr}." : '';
        $aiResult    = [
            'title'             => "{$name} — {$category} sur ChooseTounsi",
            'short_description' => $shortDesc
                ?: "Découvrez {$name}, disponible sur ChooseTounsi. Livraison rapide partout en Tunisie.",
            'description'       => "Faites confiance à {$name} pour répondre à vos besoins quotidiens. "
                . "Ce produit de qualité dans la catégorie {$category} est conçu pour les consommateurs tunisiens exigeants."
                . $variantNote . $attrNote
                . " Disponible avec livraison express. Commandez maintenant sur ChooseTounsi.",
            'keywords'          => ['tunisien', strtolower($category), 'choosetounsi', 'livraison', 'qualité', 'authentique'],
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
    // 5. RECOMMENDER (Bundle & Related Products)
    //    POST /api/seller/ai/recommender
    //    Body: { product_id: int, mode: 'bundle'|'related' }
    // ═══════════════════════════════════════════════════════════════════════
    public function recommender(Request $request)
    {
        $request->validate([
            'product_id'  => 'required|integer',
            'mode'        => 'nullable|in:bundle,related',
            'discount_pct'=> 'nullable|integer|min:1|max:50',
        ]);

        $sellerId   = auth()->id();
        $sellerCol  = $this->sellerCol();
        $totalExpr  = $this->totalExpr();
        $mode       = $request->input('mode', 'bundle');
        $discountPct= $request->input('discount_pct', 10);

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

        // ── Co-purchase data: which products are bought together? ──────────
        // Orders that contain the main product
        $ordersWithMain = DB::table('order_items')
            ->where('product_id', $mainProduct->id)
            ->pluck('order_id');

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

        // ── Other seller products in same category ─────────────────────────
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

        // ── NEW: Resolve product images in a single batched query ──────────
        // Collect all product IDs we need images for:
        // main product + co-purchased products + same-category products.
        $allProductIds = collect([$mainProduct->id])
            ->merge($coPurchased->pluck('id'))
            ->merge($sameCategoryProducts->pluck('id'))
            ->unique()
            ->values()
            ->toArray();

        // Fetch all relevant image rows in ONE query, ordered so the best
        // image per product comes first when we group by product_id:
        //   1. is_primary DESC  → primary image wins
        //   2. order ASC        → lowest display-order next
        //   3. id ASC           → tie-break by earliest row
        $rawImages = DB::table('product_images')
            ->whereIn('product_id', $allProductIds)
            ->select('product_id', 'variant_id', 'image_path', 'is_primary', 'order', 'id')
            ->orderByRaw('product_id ASC, is_primary DESC, `order` ASC, id ASC')
            ->get()
            ->groupBy('product_id');

        // Build a map of product_id → resolved Storage URL (null if no image).
        // Image priority per product:
        //   • Variant product  → primary variant image first, else first variant image
        //   • Simple product   → primary product-level image first, else first product image
        //   • No image at all  → null  (frontend renders a placeholder icon)
        $imageUrlById = [];
        foreach ($allProductIds as $pid) {
            $rows = $rawImages->get($pid, collect());

            if ($rows->isEmpty()) {
                $imageUrlById[$pid] = null;
                continue;
            }

            $variantImages = $rows->filter(fn($r) => !is_null($r->variant_id));
            $productImages = $rows->filter(fn($r) =>  is_null($r->variant_id));

            if ($variantImages->isNotEmpty()) {
                // Variant product: prefer primary variant image, else lowest-order variant image
                $best = $variantImages->firstWhere('is_primary', true)
                     ?? $variantImages->first(); // already sorted by order ASC
            } else {
                // Simple product: prefer primary product image, else lowest-order product image
                $best = $productImages->firstWhere('is_primary', true)
                     ?? $productImages->first(); // already sorted by order ASC
            }

            $imageUrlById[$pid] = $best ? Storage::url($best->image_path) : null;
        }

        // Build the final map keyed by product NAME (not ID).
        // The AI returns product names as strings in bundle.products[],
        // so the frontend looks up images by name.
        $productImagesByName = [];

        // Main product
        $productImagesByName[$mainProduct->name] = $imageUrlById[$mainProduct->id] ?? null;

        // Co-purchased products
        foreach ($coPurchased as $p) {
            $productImagesByName[$p->name] = $imageUrlById[$p->id] ?? null;
        }

        // Same-category products
        foreach ($sameCategoryProducts as $p) {
            $productImagesByName[$p->name] = $imageUrlById[$p->id] ?? null;
        }
        // ── END image resolution ───────────────────────────────────────────

        $coPurchasedStr = $coPurchased->map(fn($p) => "{$p->name} ({$p->co_count}x co-purchased)")->implode(', ') ?: 'No co-purchase data yet';
        $categoryStr    = $sameCategoryProducts->pluck('name')->implode(', ') ?: 'No other products in category';
        $otherProductsArr = $coPurchased->isNotEmpty() ? $coPurchased->pluck('name') : $sameCategoryProducts->pluck('name');
        $otherProductsList = $otherProductsArr->implode('", "');

        $systemPrompt = "You are a Tunisian e-commerce bundle strategy expert for ChooseTounsi marketplace.
Suggest high-converting product bundles and related product recommendations.
Base suggestions on real purchase affinity data and Tunisian shopping behavior.
ALWAYS respond with ONLY valid JSON. No markdown. No text outside JSON.";

        if ($mode === 'bundle') {
            $userPrompt = <<<PROMPT
Create bundle recommendations for this ChooseTounsi product:

MAIN PRODUCT: {$mainProduct->name} ({$mainProduct->category_name}) — {$mainProduct->price} TND

CO-PURCHASED (real data): {$coPurchasedStr}
SAME CATEGORY PRODUCTS: {$categoryStr}
PROPOSED DISCOUNT: {$discountPct}%

Respond with ONLY this JSON:
{
  "bundles": [
    {
      "name": "<bundle name>",
      "products": ["{$mainProduct->name}", "{$otherProductsList}"],
      "reason": "<why these work together>",
      "est_uplift": "<estimated % revenue increase>",
      "discount": {$discountPct},
      "suggested_price_reduction": "<discount explanation>",
      "display_label": "<short UI badge text e.g. 'Popular Combo'>"
    }
  ]
}
Include 2-3 bundles. Tailor for Tunisian buyers.
PROMPT;
        } else {
            $userPrompt = <<<PROMPT
Suggest related products and cross-sell opportunities for:

MAIN PRODUCT: {$mainProduct->name} ({$mainProduct->category_name}) — {$mainProduct->price} TND

CO-PURCHASED PRODUCTS: {$coPurchasedStr}
SAME CATEGORY: {$categoryStr}

Respond with ONLY this JSON:
{
  "recommendations": [
    {
      "product_name": "<name>",
      "reason": "<why it's relevant>",
      "placement": "also_bought"|"similar"|"upgrade"|"accessory",
      "est_click_rate": "<estimated engagement>"
    }
  ],
  "placement_strategy": "<where to show these recommendations>",
  "best_time_to_show": "<when to show them in buyer journey>"
}
Include 4-6 recommendations.
PROMPT;
        }

        $aiRaw = $this->callGroq($systemPrompt, $userPrompt, 650);

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
            $companions = $coPurchased->isNotEmpty()
                ? $coPurchased->pluck('name')->toArray()
                : $sameCategoryProducts->pluck('name')->toArray();

            if ($mode === 'bundle') {
                $aiResult = [
                    'bundles' => [
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
                    ],
                ];
            } else {
                $aiResult = [
                    'recommendations'     => array_map(fn($name) => [
                        'product_name'    => $name,
                        'reason'          => "Co-purchased with {$mainProduct->name} based on real buyer behavior.",
                        'placement'       => 'also_bought',
                        'est_click_rate'  => '12-18%',
                    ], array_slice($companions, 0, 5)),
                    'placement_strategy'  => 'Show on product detail page under "Customers also bought" section.',
                    'best_time_to_show'   => 'After adding to cart and on checkout page.',
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
                    // NEW: image map keyed by product name → Storage URL | null
                    // Used by the frontend to render thumbnails in bundle chips.
                    // null means no image exists → frontend shows a placeholder icon.
                    'product_images' => $productImagesByName,
                ],
            ],
        ]);
    }
}