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
        $language    = $request->input('language') ?: app()->getLocale();

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