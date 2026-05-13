<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Services\AiRouter;
use App\Services\ChatMemory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

/**
 * AiChatController — POST /api/ai/chat
 *
 * Hybrid AI architecture:
 *   • Gemini Flash   → greetings, product search, multilingual, fast replies
 *   • DeepSeek R1    → compare, recommend, rank, semantic reasoning
 *
 * Session isolation:
 *   Every request carries session_id from the frontend.
 *   Rate limits, memory, and response cache are ALL keyed per session_id.
 *   One user's spam never affects any other chatbot instance.
 *
 * Fallback chain (handled by AiRouter — this controller never calls AI directly):
 *   preferred provider → cross-fallback → session memory → static degrade
 *
 * NEVER auto-confirms orders. NEVER modifies cart. Read-only guidance only.
 */
class AiChatController extends Controller
{
    private const MAX_PRODUCTS  = 8;
    private const MAX_CARDS     = 6;

    // Intents that benefit from DeepSeek's reasoning over Gemini's speed
    private const DEEPSEEK_INTENTS = ['compare_products'];

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function handle(Request $request)
    {
        $request->validate([
            'message'    => 'required|string|max:1000',
            'session_id' => 'required|string|max:120',
            'history'    => 'sometimes|array|max:12',
            'history.*.role'    => 'required|in:user,assistant',
            'history.*.content' => 'required|string|max:2000',
        ]);

        $userMessage = trim($request->input('message'));
        $sessionId   = $request->input('session_id');
        $history     = $request->input('history', []);
        $user        = $request->user(); // nullable — endpoint is public

        if (empty(config('services.gemini.api_key'))) {
            return response()->json(['success' => false, 'message' => 'AI service not configured.'], 503);
        }

        try {
            // ── Classify intent using full conversation context ────────────
            $intent = $this->classifyIntent($userMessage, $history);
            Log::info('[AiChat] Intent: ' . $intent['type'], [
                'session' => $sessionId,
                'intent'  => $intent,
            ]);

            // ── Route to data tool ────────────────────────────────────────
            $toolResult = $this->dispatchTool($intent, $userMessage, $history, $user);

            // ── Build prompt + message arrays for both AI formats ─────────
            $systemPrompt   = $this->buildSystemPrompt($intent, $toolResult, $user);
            $geminiMessages = $this->buildGeminiMessages($history, $userMessage);
            $openaiMessages = $this->buildOpenAiMessages($history, $userMessage);

            // ── Determine preferred provider from intent ───────────────────
            $preferredProvider = in_array($intent['type'], self::DEEPSEEK_INTENTS, true)
                ? 'deepseek'
                : 'gemini';

            // ── Route through hybrid AI with automatic fallback ───────────
            /** @var AiRouter $router */
            $router = app(AiRouter::class);
            $result = $router->respond(
                sessionId:         $sessionId,
                preferredProvider: $preferredProvider,
                systemPrompt:      $systemPrompt,
                geminiMessages:    $geminiMessages,
                openaiMessages:    $openaiMessages,
            );

            return response()->json([
                'success'  => true,
                'message'  => $result['text'],
                'products' => $toolResult['products'] ?? [],
                'intent'   => $intent['type'],
                'provider' => $result['provider'], // for debugging; can be removed in prod
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChat] Unhandled error: ' . $e->getMessage(), [
                'session' => $sessionId,
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            // Even on unexpected exceptions, return a friendly message — never a raw error
            return response()->json([
                'success'  => true, // keep frontend happy — this isn't a client error
                'message'  => "Je rencontre une difficulté technique. Veuillez réessayer dans un moment. 🙏",
                'products' => [],
            ]);
        }
    }

    // =========================================================================
    // STEP 1 — INTENT CLASSIFICATION (conversation-aware)
    // =========================================================================

    /**
     * Classify the user's intent using the full conversation context.
     * Resolves references like "show me cheaper ones" by looking at history.
     */
    private function classifyIntent(string $message, array $history): array
    {
        // ── 0. Context carry-forward — if last bot turn was about seller onboarding,
        //       short affirmative replies continue that intent ─────────────────────
        $affirmatives = ['oui', 'yes', 'yeah', 'yep', 'ok', 'okay', 'bahi', 'ey', 'ayeh', 'na3m', 'نعم', 'أيوه', 'باهي'];
        $isAffirmative = in_array(trim($lower), $affirmatives, true);

        if ($isAffirmative && !empty($history)) {
            // Look at last assistant message for seller context
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if ($history[$i]['role'] === 'assistant') {
                    $lastBot = mb_strtolower($history[$i]['content']);
                    if (preg_match('/vendeur|seller|vendor|بائع|تاجر|become-a-vendor|sell/u', $lastBot)) {
                        return [
                            'type'        => 'seller_onboarding',
                            'query'       => $message,
                            'raw_message' => $message,
                        ];
                    }
                    break;
                }
            }
        }
        $lower        = mb_strtolower($message);
        $resolvedQuery = $this->resolveContextualReference($message, $history);

        // ── 1. Comparison — routes to DeepSeek ───────────────────────────
        if (preg_match('/compar|vs\.?|versus|difference|quel est le meilleur|which is better|قارن|مقارنة/u', $lower)) {
            return [
                'type'          => 'compare_products',
                'query'         => $resolvedQuery,
                'raw_message'   => $message,
                'price_min'     => null,
                'price_max'     => $this->extractPriceMax($lower),
                'sort'          => $this->extractSort($lower),
                'is_contextual' => $resolvedQuery !== $message,
            ];
        }

        // ── 2. Flash sales / promotions ───────────────────────────────────
        if (preg_match('/flash.?sale|promotion|promo|solde|discount|r[ée]duction|offre|en promotion|بالتخفيض|تخفيضات|عروض|mrigla|barcha tkhfidh/u', $lower)) {
            return [
                'type'        => 'flash_sales',
                'query'       => null,
                'raw_message' => $message,
                'price_min'   => null,
                'price_max'   => $this->extractPriceMax($lower),
                'sort'        => 'discount',
            ];
        }

        // ── 3. Packs / bundles ────────────────────────────────────────────
        if (preg_match('/\bpacks?\b|bundle|lot\b|ensemble|coffret|مجموعة|باقة/u', $lower)) {
            return [
                'type'        => 'packs',
                'query'       => null,
                'raw_message' => $message,
                'price_min'   => null,
                'price_max'   => $this->extractPriceMax($lower),
                'sort'        => 'created_at',
            ];
        }
        

        // ── 4. Seller onboarding ──────────────────────────────────────────
        if (preg_match('/\bsell\b|vendor|vendeur|devenir.*vendeur|become.*seller|how.*sell|بائع|كيف.*أبيع|كيف.*أبيع|كيف أصبح|أصبح تاجر|تاجر|nheb nbii|besh nbii|nwali|nweli|nbii|nbi3|كيف نبيع|كيف نولي|comment vendre|comment devenir|devenir vendeur/u', $lower)) {
                return ['type' => 'seller_onboarding', 'query' => $message, 'raw_message' => $message];
        }

        // ── 5. Checkout / order guidance ──────────────────────────────────
        if (preg_match('/checkout|comment.*payer|how.*pay|how.*do.*order|how.*to.*order|how.*place|how.*order|comment.*commander|order.*place|كيف.*أدفع|كيف.*أطلب|comment passer commande/u', $lower)) {
            return ['type' => 'checkout_guidance', 'query' => $message, 'raw_message' => $message];
        }

        // ── 6. Cart status (read-only) ────────────────────────────────────
        if (preg_match('/my cart|mon panier|panier actuel|what.*in.*cart|سلتي|قائمة التسوق/u', $lower)) {
            return ['type' => 'cart_status', 'query' => $message, 'raw_message' => $message];
        }

        // ── 7. Trending / popular ─────────────────────────────────────────
        if (preg_match('/trending|popular|best.?sell|most.?view|populaire|الأكثر مبيعاً|mzyen barsha/u', $lower)) {
            return [
                'type'        => 'trending_products',
                'query'       => null,
                'raw_message' => $message,
                'price_min'   => null,
                'price_max'   => $this->extractPriceMax($lower),
                'sort'        => 'views',
            ];
        }

        // ── 8. Contextual reference ("show cheaper ones", "show more") ────
        $isContextual = $resolvedQuery !== $message;
        if ($isContextual && !empty($resolvedQuery)) {
            return [
                'type'          => 'product_search',
                'query'         => $resolvedQuery,
                'raw_message'   => $message,
                'price_min'     => $this->extractPriceMin($lower),
                'price_max'     => $this->extractPriceMax($lower),
                'sort'          => $this->extractSort($lower),
                'is_contextual' => true,
            ];
        }

        // ── 9. Explicit product search ────────────────────────────────────
        $searchSignals = [
            'need', 'want', 'looking for', 'find', 'show', 'search', 'buy', 'get me',
            'give me', 'recommend', 'suggest', 'list', 'price', 'cheap', 'affordable',
            'cherche', 'trouver', 'acheter', 'besoin', 'montrer', 'veux', 'affiche',
            'نحتاج', 'أريد', 'اريد', 'ابحث', 'أبحث', 'اشتري', 'عايز', 'هاتف', 'لابتوب',
            'warini', 'orini', 'arini', 'nheb', 'nchri', 'عندكم',
            'laptop', 'phone', 'portable', 'pc', 'shoes', 'chaussures', 'chemise',
        ];

        foreach ($searchSignals as $signal) {
            if (mb_strpos($lower, $signal) !== false) {
                return [
                    'type'          => 'product_search',
                    'query'         => $this->extractSearchQuery($message),
                    'raw_message'   => $message,
                    'price_min'     => $this->extractPriceMin($lower),
                    'price_max'     => $this->extractPriceMax($lower),
                    'sort'          => $this->extractSort($lower),
                    'is_contextual' => false,
                ];
            }
        }

        // ── 10. General / greeting / unknown ─────────────────────────────
        return ['type' => 'general', 'query' => $message, 'raw_message' => $message];
    }

    /**
     * Resolve contextual references to concrete search terms.
     */
    private function resolveContextualReference(string $message, array $history): string
    {
        $lower = mb_strtolower($message);

        $contextSignals = [
            'cheaper', 'more expensive', 'same', 'similar', 'show more', 'show me more',
            'moins cher', 'plus cher', 'autre', 'encore', 'more of',
            'ones', 'these', 'those', 'them', 'it',
            'أرخص', 'أغلى', 'مشابه', 'المزيد', 'تلقاها', 'نفس',
        ];

        $isContextual = false;
        foreach ($contextSignals as $signal) {
            if (mb_strpos($lower, $signal) !== false) {
                $isContextual = true;
                break;
            }
        }

        if (!$isContextual || empty($history)) {
            return $message;
        }

        $lastUserSearch = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'user') {
                $lastQuery = $this->extractSearchQuery($history[$i]['content']);
                if (!empty($lastQuery) && mb_strlen($lastQuery) > 2) {
                    $lastUserSearch = $lastQuery;
                    break;
                }
            }
        }

        if (!$lastUserSearch) return $message;

        $modifiers = [];
        if (preg_match('/cheaper|moins cher|rkhis|أرخص/u', $lower))  $modifiers[] = 'cheap';
        if (preg_match('/expensive|premium|غالي/u', $lower))          $modifiers[] = 'premium';
        if (preg_match('/red|rouge|أحمر/u', $lower))                  $modifiers[] = 'red';
        if (preg_match('/blue|bleu|أزرق/u', $lower))                  $modifiers[] = 'blue';

        $resolved = $lastUserSearch;
        if (!empty($modifiers)) {
            $resolved = implode(' ', $modifiers) . ' ' . $resolved;
        }

        return $resolved;
    }

    // =========================================================================
    // STEP 2 — TOOL DISPATCHER
    // =========================================================================

    private function dispatchTool(array $intent, string $message, array $history, $user): array
    {
        return match ($intent['type']) {
            'product_search'    => $this->toolProductSearch($intent),
            'trending_products' => $this->toolTrendingProducts($intent),
            'compare_products'  => $this->toolProductSearch($intent),
            'cart_status'       => $this->toolCartStatus($user),
            'flash_sales'       => $this->toolFlashSales($intent),
            'packs'             => $this->toolPacks($intent),
            'seller_onboarding' => ['products' => [], 'context' => 'seller_onboarding'],
            'checkout_guidance' => ['products' => [], 'context' => 'checkout_guidance'],
            default             => ['products' => [], 'context' => 'general'],
        };
    }

    // =========================================================================
    // TOOLS
    // =========================================================================

    private function toolFlashSales(array $intent): array
    {
        $now = now();

        try {
            $productIds = DB::table('promotion_products')
                ->join('promotions', 'promotions.id', '=', 'promotion_products.promotion_id')
                ->where('promotions.status', 'active')
                ->where('promotions.starts_at', '<=', $now)
                ->where('promotions.ends_at', '>', $now)
                ->pluck('promotion_products.product_id')
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('[AiChat] Flash sales query failed: ' . $e->getMessage());
            return ['products' => [], 'context' => 'no_flash_sales'];
        }

        $q = Product::available()
            ->with(['category:id,name,slug', 'primaryImage'])
            ->whereIn('id', $productIds);

        if (!empty($intent['price_max'])) {
            $q->where('price', '<=', $intent['price_max']);
        }

        $results = $q->take(self::MAX_PRODUCTS)->get();

        $promotionData = DB::table('promotion_products')
            ->join('promotions', 'promotions.id', '=', 'promotion_products.promotion_id')
            ->where('promotions.status', 'active')
            ->where('promotions.starts_at', '<=', $now)
            ->where('promotions.ends_at', '>', $now)
            ->whereIn('promotion_products.product_id', $productIds)
            ->select('promotion_products.product_id', 'promotions.discount_type', 'promotions.discount_value', 'promotions.ends_at')
            ->get()
            ->keyBy('product_id');

        $products = $this->formatProducts($results);

        foreach ($products as &$p) {
            $promo = $promotionData->get($p['id']);
            if ($promo) {
                $p['discount_type']  = $promo->discount_type;
                $p['discount_value'] = (float) $promo->discount_value;
                $p['promo_ends_at']  = $promo->ends_at;

                $p['effective_price'] = $promo->discount_type === 'percentage'
                    ? round($p['price'] * (1 - $promo->discount_value / 100), 3)
                    : max(0, round($p['price'] - $promo->discount_value, 3));
            }
        }
        unset($p);

        return ['products' => $products, 'context' => 'flash_sales', 'count' => count($products)];
    }

    private function toolPacks(array $intent): array
    {
        $packs = \App\Models\Pack::available()
            ->with(['items.product.primaryImage'])
            ->orderByDesc('created_at')
            ->take(6)
            ->get();

        if ($packs->isEmpty()) {
            return ['products' => [], 'context' => 'no_packs'];
        }

        $formatted = $packs->map(fn($pack) => [
            'id'                => $pack->id,
            'name'              => $pack->name,
            'slug'              => $pack->slug,
            'price'             => (float) $pack->pack_price,
            'stock'             => 999,
            'short_description' => $pack->short_description ?? '',
            'category'          => 'Bundle Pack',
            'category_slug'     => 'packs',
            'primary_image_url' => $pack->image_url,
            'is_pack'           => true,
            'original_price'    => (float) $pack->original_price,
            'savings'           => (float) $pack->savings,
        ])->toArray();

        return ['products' => $formatted, 'context' => 'packs'];
    }

    private function toolProductSearch(array $intent): array
    {
        $baseQuery = function () use ($intent) {
            $q = Product::available()->with(['category:id,name,slug', 'primaryImage']);

            if (!empty($intent['price_min'])) $q->where('price', '>=', $intent['price_min']);
            if (!empty($intent['price_max'])) $q->where('price', '<=', $intent['price_max']);

            match ($intent['sort'] ?? 'created_at') {
                'price_asc'  => $q->orderBy('price'),
                'price_desc' => $q->orderByDesc('price'),
                'views'      => $q->orderByDesc('views'),
                default      => $q->orderByDesc('created_at'),
            };

            return $q;
        };

        if (!empty($intent['query'])) {
            $search = $intent['query'];
            $terms  = array_filter(explode(' ', $search), fn($t) => mb_strlen($t) >= 2);

            $results = $baseQuery()->where(function ($q) use ($search, $terms) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%");
                foreach ($terms as $term) {
                    $q->orWhere('name', 'like', "%{$term}%");
                }
            })->take(self::MAX_PRODUCTS)->get();

            if ($results->isNotEmpty()) {
                return ['products' => $this->formatProducts($results), 'context' => 'product_results'];
            }

            $category = Category::where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->first();

            if ($category) {
                $results = $baseQuery()->where('category_id', $category->id)->take(self::MAX_PRODUCTS)->get();
                if ($results->isNotEmpty()) {
                    return ['products' => $this->formatProducts($results), 'context' => 'product_results', 'category' => $category->name];
                }
            }
        }

        $results = $baseQuery()->take(self::MAX_PRODUCTS)->get();

        return [
            'products' => $this->formatProducts($results),
            'context'  => $results->isEmpty() ? 'no_results' : 'product_fallback',
        ];
    }

    private function toolTrendingProducts(array $intent): array
    {
        $q = Product::available()->with(['category:id,name,slug', 'primaryImage'])->orderByDesc('views');
        if (!empty($intent['price_max'])) $q->where('price', '<=', $intent['price_max']);

        return ['products' => $this->formatProducts($q->take(self::MAX_PRODUCTS)->get()), 'context' => 'trending'];
    }

    private function toolCartStatus($user): array
    {
        if (!$user) {
            return ['products' => [], 'context' => 'cart_not_logged_in'];
        }

        $cartItems = \App\Models\Cart::where('user_id', $user->id)
            ->with('product:id,name,price')
            ->get();

        $cartSummary = $cartItems->map(fn($item) => [
            'name'     => $item->product->name ?? 'Unknown',
            'price'    => (float) ($item->product->price ?? 0),
            'quantity' => $item->quantity,
        ])->toArray();

        return [
            'products'     => [],
            'context'      => 'cart_status',
            'cart_summary' => $cartSummary,
            'cart_total'   => round($cartItems->sum(fn($i) => ($i->product->price ?? 0) * $i->quantity), 3),
        ];
    }

    // =========================================================================
    // STEP 3 — PROMPT BUILDER
    // =========================================================================

    private function buildSystemPrompt(array $intent, array $toolResult, $user): string
    {
        $platform = "ChooseTounsi — Tunisia's #1 multi-vendor e-commerce marketplace.";
        $userName = $user ? $user->name : null;
        $greeting = $userName ? "The user's name is {$userName}." : '';

        $productBlock = '';
        if (!empty($toolResult['products'])) {
            $lines = [];
            foreach ($toolResult['products'] as $i => $p) {
                $stock = $p['stock'] > 0 ? "In stock ({$p['stock']})" : '⚠️ Out of stock';
                $lines[] = sprintf(
                    '%d. **%s** | %.3f TND | %s | %s | slug: %s',
                    $i + 1,
                    $p['name'],
                    $p['price'],
                    $p['category'],
                    $stock,
                    $p['slug']
                );
            }
            $productBlock = "AVAILABLE PRODUCTS (from live database):\n" . implode("\n", $lines);
        }

        $cartBlock = '';
        if (!empty($toolResult['cart_summary'])) {
            $cartLines = array_map(
                fn($item) => "- {$item['name']} × {$item['quantity']} = " . number_format($item['price'] * $item['quantity'], 3) . ' TND',
                $toolResult['cart_summary']
            );
            $cartBlock = "USER'S CURRENT CART:\n" . implode("\n", $cartLines) . "\nCart total: " . number_format($toolResult['cart_total'], 3) . ' TND';
        }

        $intentInstructions = match ($intent['type']) {
            'product_search'    => $this->promptProductSearch($toolResult, $intent),
            'compare_products'  => $this->promptCompare($toolResult),
            'trending_products' => $this->promptTrending($toolResult),
            'seller_onboarding' => $this->promptSellerOnboarding(),
            'checkout_guidance' => $this->promptCheckoutGuidance(),
            'cart_status'       => $this->promptCartStatus($toolResult),
            'flash_sales'       => $this->promptFlashSales($toolResult),
            'packs'             => $this->promptPacks($toolResult),
            default             => $this->promptGeneral(),
        };

        return <<<SYSTEM
You are a smart, friendly shopping assistant for {$platform}
{$greeting}

ABSOLUTE RULES — never break these:
1. NEVER invent products. Only reference products from AVAILABLE PRODUCTS below.
2. NEVER place orders, confirm purchases, or execute checkout. Only guide users to the checkout UI.
3. Respond in the SAME LANGUAGE as the user (Arabic, French, English, Tunisian Darija — auto-detect).
4. Be warm and helpful. When the user writes in Darija, respond in simple Darija. When in French, respond in French. When in English, respond in English. Never mix languages unprompted.
5. Do NOT list product names in your text — product cards are shown separately in the UI.
6. Keep responses concise: 1-3 sentences for simple queries, up to 5 for complex explanations.
7. If the user's message is ambiguous, ask ONE clarifying question.
8. For Tunisian Darija: respond warmly but use SIMPLE common words only. NEVER invent or use obscure slang. Stick to universally understood Tunisian phrases like "barcha", "bahi", "yezzi". Do NOT use words you are uncertain about.

{$productBlock}

{$cartBlock}

{$intentInstructions}
SYSTEM;
    }

    // ── Intent-specific prompt fragments ──────────────────────────────────

    private function promptFlashSales(array $toolResult): string
    {
        if ($toolResult['context'] === 'no_flash_sales') {
            return "SITUATION: No active promotions in the database right now.\nTell the user honestly there are no active flash sales, and suggest they browse /shop. Do NOT invent promotions.";
        }
        $count = count($toolResult['products'] ?? []);
        return "SITUATION: Found {$count} products currently on promotion.\nBriefly introduce these as the current deals. Mention discounts where visible. Encourage urgency (limited time) without being pushy. 1-2 sentences max.";
    }

    private function promptPacks(array $toolResult): string
    {
        if ($toolResult['context'] === 'no_packs') {
            return "SITUATION: No bundle packs available currently.\nTell the user there are no packs right now and suggest regular products.";
        }
        $count = count($toolResult['products'] ?? []);
        return "SITUATION: Found {$count} bundle packs.\nExplain these are curated bundles at a discounted price vs buying separately. 1-2 sentences.";
    }

    private function promptProductSearch(array $toolResult, array $intent): string
    {
        $count = count($toolResult['products'] ?? []);

        if ($toolResult['context'] === 'no_results') {
            return "SITUATION: No products found.\nTell the user no exact matches were found. Suggest different keywords or browsing /shop.";
        }
        if ($toolResult['context'] === 'product_fallback') {
            return "SITUATION: No exact keyword match — showing best available products.\nAcknowledge you couldn't find an exact match but here are relevant products. Suggest different terms.";
        }

        $priceNote = !empty($intent['price_max']) ? " under {$intent['price_max']} TND" : '';
        return "SITUATION: Found {$count} products{$priceNote}.\nBriefly introduce the results (1 sentence). Don't list all products — cards are shown separately.";
    }

    private function promptCompare(array $toolResult): string
    {
        $count = count($toolResult['products'] ?? []);
        return "SITUATION: User wants to compare products. Found {$count} options.\nHighlight key differences (price range, features from descriptions). Help the user decide based on use case. Be specific and practical. This is a reasoning task — be thorough but concise.";
    }

    private function promptTrending(array $toolResult): string
    {
        $count = count($toolResult['products'] ?? []);
        return "SITUATION: User asked for trending/popular products. Showing top {$count} by views.\nMention these are the most viewed on ChooseTounsi right now. Be enthusiastic but brief.";
    }

    private function promptSellerOnboarding(): string
    {
        return <<<PROMPT
SITUATION: User wants to become a seller on ChooseTounsi.
Explain the seller onboarding process:
1. Go to /become-a-vendor and fill out the application form
2. Choose a plan: Free (basic), Red Pepper (49 TND/month, analytics + AI tools), or Black Pepper (129 TND/month, full AI suite + sponsored products)
3. Submit business information and sample products for admin review
4. Once approved, access the seller dashboard at /seller to start listing products
5. All products require admin approval before going live
Be encouraging and mention that local Tunisian sellers are the heart of ChooseTounsi.
PROMPT;
    }

    private function promptCheckoutGuidance(): string
    {
        return <<<PROMPT
SITUATION: User needs help with checkout or payment.
Explain the checkout process:
- Add products to cart, then click the cart icon
- Review items in the cart drawer
- Click "Checkout" to enter delivery details (wilaya, address, phone)
- Choose payment: Cash on Delivery (COD), or Wallet
- Confirm the order — you'll receive an order confirmation
CRITICAL: NEVER offer to place the order yourself. Always direct to the checkout UI.
PROMPT;
    }

    private function promptCartStatus(array $toolResult): string
    {
        if ($toolResult['context'] === 'cart_not_logged_in') {
            return "SITUATION: User asked about their cart but is not logged in.\nTell them to log in to see their cart. Direct them to /login.";
        }
        $itemCount = count($toolResult['cart_summary'] ?? []);
        if ($itemCount === 0) {
            return "SITUATION: User's cart is empty.\nTell them their cart is empty and suggest browsing products.";
        }
        return "SITUATION: User's cart contains {$itemCount} item(s) as listed above.\nSummarize what's in their cart and the total. Offer to help find more products or guide to checkout.";
    }

    private function promptGeneral(): string
    {
        return "SITUATION: General question or greeting.\nRespond naturally and helpfully. If it's a greeting, welcome them warmly and ask what they're looking for.";
    }

    // =========================================================================
    // MESSAGE FORMAT BUILDERS
    // Gemini uses {role: 'user'|'model', parts: [{text}]}
    // OpenAI uses {role: 'user'|'assistant', content: string}
    // =========================================================================

    /**
     * Build Gemini-format contents array (for Gemini calls and cache keying).
     */
    private function buildGeminiMessages(array $history, string $currentMessage): array
    {
        $messages = [];

        foreach (array_slice($history, -6) as $turn) {
            $messages[] = [
                'role'  => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['content']]],
            ];
        }

        $messages[] = [
            'role'  => 'user',
            'parts' => [['text' => $currentMessage]],
        ];

        return $messages;
    }

    /**
     * Build OpenAI-compat messages array (for DeepSeek via OpenRouter).
     * System prompt is prepended by AiRouter::tryDeepSeek().
     */
    private function buildOpenAiMessages(array $history, string $currentMessage): array
    {
        $messages = [];

        foreach (array_slice($history, -6) as $turn) {
            $messages[] = [
                'role'    => $turn['role'], // 'user' | 'assistant' — both valid in OpenAI format
                'content' => $turn['content'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $currentMessage];

        return $messages;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function extractPriceMax(string $lower): ?float
    {
        if (preg_match('/(?:under|moins de|below|max|أقل من)\s*(\d+)/u', $lower, $m)) return (float) $m[1];
        if (preg_match('/(\d+)\s*(?:tnd|dt|دينار)/u', $lower, $m)) return (float) $m[1];
        foreach (['cheap', 'pas cher', 'رخيص', 'affordable', 'budget', 'rkhis'] as $s) {
            if (mb_strpos($lower, $s) !== false) return 200.0;
        }
        return null;
    }

    private function extractPriceMin(string $lower): ?float
    {
        if (preg_match('/(?:over|plus de|above|min|أكثر من)\s*(\d+)/u', $lower, $m)) return (float) $m[1];
        return null;
    }

    private function extractSort(string $lower): string
    {
        if (preg_match('/cheap|less|low.?price|pas cher|رخيص|rkhis|moins cher/u', $lower)) return 'price_asc';
        if (preg_match('/expensive|premium|luxe|غالي|best.?quality/u', $lower))             return 'price_desc';
        if (preg_match('/popular|trending|best.?sell|most.?view|الأكثر/u', $lower))         return 'views';
        return 'created_at';
    }

    private function extractSearchQuery(string $message): string
    {
        $query = $message;

        $fillerWords = [
            'i need', 'i want', 'i am looking for', "i'm looking for", 'show me', 'find me',
            'can you find', 'looking for', 'search for', 'do you have', 'please', 'can i get',
            'give me', 'list all', 'show all', 'show', 'find', 'get me', 'recommend',
            'je cherche', 'je veux', 'trouver', 'montrez moi', 'affiche moi', 'avez vous',
            'أريد', 'اريد', 'ابحث عن', 'أبحث عن', 'عندك', 'نحتاج', 'هل عندكم', 'اعطني',
            'warini', 'orini', 'arini', 'nheb', 'nchri', 'besh nchri',
        ];

        foreach ($fillerWords as $filler) {
            $query = trim(str_ireplace($filler, '', $query));
        }

        $query = preg_replace('/\d+\s*(tnd|dt|دينار)?/u', '', $query);
        $query = preg_replace('/under|over|moins de|plus de|أقل من|أكثر من|cheap|pas cher|رخيص/u', '', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));

        $genericWords = ['products', 'product', 'produit', 'produits', 'items', 'things', 'منتج', 'منتجات'];
        if (mb_strlen($query) < 2 || in_array(mb_strtolower($query), $genericWords)) {
            return '';
        }

        return $query;
    }

    private function formatProducts($products): array
    {
        return $products->map(function ($p) {
            return [
                'id'                => $p->id,
                'name'              => $p->name,
                'slug'              => $p->slug,
                'price'             => (float) $p->price,
                'stock'             => $p->stock,
                'short_description' => $p->short_description ?? '',
                'category'          => $p->category ? $p->category->name : '',
                'category_slug'     => $p->category ? $p->category->slug : '',
                'primary_image_url' => $p->primaryImage
                    ? Storage::url($p->primaryImage->image_path)
                    : null,
            ];
        })->toArray();
    }
}