<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AiChatController — POST /api/ai/chat
 *
 * Flow (1 Gemini call max per unique message):
 *   1. Extract search intent locally via PHP keywords (zero API cost)
 *   2. Query real products — with smart 2-pass fallback:
 *        Pass 1: keyword search (name/description LIKE)
 *        Pass 2: if 0 results, return all available products sorted by intent
 *   3. Check cache — if answered recently, return instantly (zero API cost)
 *   4. Call Gemini once to generate a grounded response
 *   5. Cache the response for 10 minutes
 *
 * Laravel 8 compatible. Free Gemini tier safe.
 */
class AiChatController extends Controller
{
    private const GEMINI_URL    = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    private const MAX_PRODUCTS  = 8;
    private const MAX_CARDS     = 6;
    private const CACHE_MINUTES = 10;

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function handle(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $userMessage = trim($request->input('message'));
        $apiKey      = config('services.gemini.api_key');

        if (empty($apiKey)) {
            Log::error('[AiChat] GEMINI_API_KEY is not set in .env');
            return response()->json([
                'success' => false,
                'message' => 'AI service is not configured. Please contact support.',
            ], 503);
        }

        try {
            // [1] Local intent extraction — no API call, no quota
            $intent = $this->extractIntentLocally($userMessage);
            Log::info('[AiChat] Intent extracted locally', $intent);

            // [2] Query real products — with 2-pass fallback
            $products = $this->searchProducts($intent);
            Log::info('[AiChat] Products found: ' . count($products));

            // [3] Cache key = message + product IDs (same query = skip Gemini)
            $productIds = array_column($products, 'id');
            sort($productIds);
            $cacheKey   = 'ai_chat_' . md5(mb_strtolower(trim($userMessage)) . implode(',', $productIds));
            $cachedText = Cache::get($cacheKey);

            if ($cachedText) {
                Log::info('[AiChat] Cache HIT — Gemini call skipped');
                return response()->json([
                    'success'  => true,
                    'message'  => $cachedText,
                    'products' => array_slice($products, 0, self::MAX_CARDS),
                    'intent'   => $intent,
                    'cached'   => true,
                ]);
            }

            // [4] ONE Gemini call — grounded on real product data only
            $aiText = $this->generateResponse($userMessage, $products, $apiKey);

            // [5] Cache for 10 minutes
            Cache::put($cacheKey, $aiText, now()->addMinutes(self::CACHE_MINUTES));

            return response()->json([
                'success'  => true,
                'message'  => $aiText,
                'products' => array_slice($products, 0, self::MAX_CARDS),
                'intent'   => $intent,
                'cached'   => false,
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChat] Error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The AI assistant encountered an error. Please try again.',
            ], 500);
        }
    }

    // =========================================================================
    // STEP 1 — LOCAL INTENT EXTRACTION (zero API cost)
    // =========================================================================

    /**
     * Detects shopping intent and extracts search params from the user message.
     * Supports English, French, Arabic, Tunisian Darija.
     */
    private function extractIntentLocally(string $message): array
    {
        $lower = mb_strtolower($message);

        // --- Detect product search intent ---
        $searchSignals = [
            // English
            'need', 'want', 'looking for', 'find', 'show', 'search', 'buy',
            'price', 'cheap', 'affordable', 'expensive', 'popular', 'best',
            'recommend', 'product', 'item', 'shop', 'give me', 'list',
            // French
            'cherche', 'trouver', 'acheter', 'besoin', 'montrer', 'veux',
            'moins cher', 'pas cher', 'populaire', 'meilleur', 'produit',
            'affiche', 'montre',
            // Arabic MSA
            'نحتاج', 'أريد', 'ابحث', 'أبحث', 'اشتري', 'عايز',
            'رخيص', 'غالي', 'هاتف', 'تلفون', 'لابتوب', 'حاسوب', 'ملابس',
            'اريد', 'عرض', 'منتج', 'منتجات',
            // Tunisian Darija
            'warini', 'orini', 'arini', 'wriha', 'besh nechri',
            'nheb', 'nchri', 'nلقى', 'فما', 'عندكم', 'عندك',
            'pc', 'laptop', 'telephone', 'portable',
        ];

        $isProductSearch = false;
        foreach ($searchSignals as $signal) {
            if (mb_strpos($lower, $signal) !== false) {
                $isProductSearch = true;
                break;
            }
        }

        // --- Extract price limits ---
        $priceMin = null;
        $priceMax = null;

        if (preg_match('/(?:under|moins de|below|max|moins|أقل من)\s*(\d+)/u', $lower, $m)) {
            $priceMax = (float) $m[1];
        }
        if (preg_match('/(?:over|plus de|above|min|أكثر من)\s*(\d+)/u', $lower, $m)) {
            $priceMin = (float) $m[1];
        }
        if (!$priceMax && preg_match('/(\d+)\s*(?:tnd|dt|دينار)/u', $lower, $m)) {
            $priceMax = (float) $m[1];
        }

        $cheapSignals = ['cheap', 'pas cher', 'رخيص', 'affordable', 'low price', 'budget', 'rkhis'];
        foreach ($cheapSignals as $s) {
            if (mb_strpos($lower, $s) !== false && !$priceMax) {
                $priceMax = 150;
                break;
            }
        }

        // --- Determine sort order ---
        $sort = 'created_at';
        if (preg_match('/cheap|less|low price|pas cher|رخيص|rkhis/u', $lower)) {
            $sort = 'price_asc';
        } elseif (preg_match('/expensive|premium|luxe|غالي|best quality/u', $lower)) {
            $sort = 'price_desc';
        } elseif (preg_match('/popular|trending|best.?sell|most view|populaire|الأكثر/u', $lower)) {
            $sort = 'views';
        }

        // --- Extract core product keywords ---
        // Strip filler words to get the actual product name/type the user wants
        $query = $message;
        $fillerWords = [
            // English
            'i need', 'i want', 'i am looking for', "i'm looking for",
            'show me', 'find me', 'can you find', 'looking for', 'search for',
            'do you have', 'please', 'can i get', 'give me', 'list all',
            'show all', 'show', 'find',
            // French
            'je cherche', 'je veux', 'trouver', 'montrez moi', 'affiche moi',
            'montre moi', 'avez vous',
            // Arabic
            'أريد', 'اريد', 'ابحث عن', 'أبحث عن', 'عندك', 'نحتاج',
            'هل عندكم', 'هل لديكم', 'اعطني', 'أعطني',
            // Darija
            'warini', 'orini', 'arini', 'nheb', 'nchri', 'besh nchri',
        ];

        foreach ($fillerWords as $filler) {
            $query = trim(str_ireplace($filler, '', $query));
        }

        // Strip price references from query
        $query = preg_replace('/\d+\s*(tnd|dt|دينار)?/u', '', $query);
        $query = preg_replace('/under|over|moins de|plus de|أقل من|أكثر من/u', '', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));

        // If stripping left nothing meaningful, set query to null
        // so searchProducts() falls back to showing all products
        $genericWords = ['products', 'product', 'produit', 'produits', 'items', 'things', 'stuff', 'منتج', 'منتجات'];
        $queryIsGeneric = mb_strlen($query) < 3 || in_array(mb_strtolower($query), $genericWords);

        return [
            'is_product_search' => $isProductSearch,
            'query'             => $queryIsGeneric ? null : $query,
            'raw_message'       => $message,
            'category_slug'     => null,
            'price_min'         => $priceMin,
            'price_max'         => $priceMax,
            'sort'              => $sort,
        ];
    }

    // =========================================================================
    // STEP 2 — DB PRODUCT SEARCH (2-pass with fallback)
    // =========================================================================

    /**
     * Pass 1: keyword search on name + short_description
     * Pass 2: if 0 results, return all available products (sorted by intent)
     *
     * This ensures the user always sees real products even when their phrasing
     * doesn't exactly match product names (e.g. "warini pc" vs "JBL_HEADPHON").
     */
    private function searchProducts(array $intent): array
    {
        if (!$intent['is_product_search']) {
            return [];
        }

        // Build the base query (shared by both passes)
        $baseQuery = function () use ($intent) {
            $q = Product::available()->with([
                'category:id,name,slug',
                'primaryImage',
            ]);

            if (!empty($intent['price_min'])) {
                $q->where('price', '>=', $intent['price_min']);
            }
            if (!empty($intent['price_max'])) {
                $q->where('price', '<=', $intent['price_max']);
            }

            $sort = $intent['sort'] ?? 'created_at';
            match ($sort) {
                'price_asc'  => $q->orderBy('price'),
                'price_desc' => $q->orderByDesc('price'),
                'views'      => $q->orderByDesc('views'),
                default      => $q->orderByDesc('created_at'),
            };

            return $q;
        };

        // Pass 1: keyword search (only if we have a meaningful query)
        if (!empty($intent['query'])) {
            $search  = $intent['query'];
            $results = $baseQuery()->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%");
            })->take(self::MAX_PRODUCTS)->get();

            if ($results->isNotEmpty()) {
                Log::info('[AiChat] Pass 1 matched: ' . $results->count() . ' products');
                return $this->formatProducts($results);
            }

            Log::info('[AiChat] Pass 1 found 0 — falling back to Pass 2 (all products)');
        }

        // Pass 2: fallback — return all available products with price/sort filters only
        // This ensures the user always sees something real even with vague queries
        $results = $baseQuery()->take(self::MAX_PRODUCTS)->get();
        Log::info('[AiChat] Pass 2 returning: ' . $results->count() . ' products');

        return $this->formatProducts($results);
    }

    /**
     * Convert a product collection to the array format used by Gemini and the frontend.
     */
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

    // =========================================================================
    // STEP 3 — SINGLE GEMINI CALL
    // =========================================================================

    /**
     * One Gemini call: generate a friendly response grounded on real DB products.
     * AI is strictly forbidden from inventing products not in the provided list.
     */
    private function generateResponse(string $userMessage, array $products, string $apiKey): string
    {
        if (empty($products)) {
            $productContext = 'No products found in the database.';
        } else {
            $lines = [];
            foreach ($products as $i => $p) {
                $lines[] = sprintf(
                    '%d. %s | %.3f TND | %s | %s',
                    $i + 1,
                    $p['name'],
                    $p['price'],
                    $p['category'],
                    $p['stock'] > 0 ? 'In stock' : 'Out of stock'
                );
            }
            $productContext = implode("\n", $lines);
        }

        $prompt = <<<PROMPT
You are a friendly shopping assistant for ChooseTounsi, a Tunisian e-commerce marketplace.

STRICT RULES:
1. NEVER invent or mention any product not in PRODUCT DATA below.
2. If no products found, say so and suggest trying different keywords.
3. Respond in the SAME LANGUAGE the user wrote in (Arabic, French, English, or Darija).
4. Keep your response to 1-2 short sentences only. Product cards are shown separately.
5. Be warm and helpful like a knowledgeable Tunisian shopkeeper.
6. Do NOT list product names in your text — the cards already show them.

PRODUCT DATA (only these real products exist — reference no others):
{$productContext}

User message: "{$userMessage}"

Your short response (1-2 sentences max):
PROMPT;

        $result = $this->callGemini($prompt, $apiKey, 150);

        return $result ?? 'Here are the results I found for you! 🛍️';
    }

    // =========================================================================
    // GEMINI HTTP CALLER (Laravel 8 compatible)
    // =========================================================================

    private function callGemini(string $prompt, string $apiKey, int $maxTokens = 150): ?string
    {
        $url = self::GEMINI_URL . '?key=' . urlencode($apiKey);

        $response = Http::timeout(20)->post($url, [
            'contents' => [
                [
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature'     => 0.3,
            ],
        ]);

        if ($response->failed()) {
            Log::error('[AiChat] Gemini API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Gemini API request failed: ' . $response->status());
        }

        return $response->json('candidates.0.content.parts.0.text');
    }
}