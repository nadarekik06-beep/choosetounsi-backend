<?php

namespace App\Services;

/**
 * ChatIntentClassifier
 *
 * Responsible for:
 *   - Detecting the user's language (EN / FR / AR / Darija)
 *   - Classifying the user's intent from their message + conversation history
 *   - Resolving contextual references ("show cheaper ones" → "cheap hoodie")
 *   - Extracting search queries, price filters, and sort preferences
 *
 * This service is stateless — every method is pure (input → output, no side effects).
 * Inject it via the constructor in AiChatController.
 */
class ChatIntentClassifier
{
    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Classify the user's intent using the full conversation context.
     * Returns an array with at minimum: ['type' => string, 'query' => string, 'raw_message' => string]
     */
    public function classify(string $message, array $history): array
    {
        $lower         = mb_strtolower($message);
        $resolvedQuery = $this->resolveContextualReference($message, $history);

        // ── -1. Pure greetings — NEVER inherit context ────────────────────
        $pureGreetings = [
            'hi', 'hello', 'hey', 'salut', 'bonjour', 'salam', 'ahlan',
            'bonsoir', 'yo', 'hola', 'مرحبا', 'أهلا', 'السلام عليكم',
            'wesh', 'wesh rak', 'labas', 'la bess',
        ];
        if (in_array(trim($lower), $pureGreetings, true)) {
            return ['type' => 'general', 'query' => $message, 'raw_message' => $message];
        }

        // ── 0. Context carry-forward (affirmative after bot mentioned selling) ──
        $affirmatives  = ['oui', 'yes', 'yeah', 'yep', 'ok', 'okay', 'bahi',
                          'ey', 'ayeh', 'na3m', 'نعم', 'أيوه', 'باهي'];
        $isAffirmative = in_array(trim($lower), $affirmatives, true)
                      && str_word_count($message) <= 2;

        if ($isAffirmative && !empty($history)) {
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

        // ── 1. Comparison → DeepSeek ──────────────────────────────────────
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
        if (preg_match('/flash.?sale|promotion|promo|solde|discount|r[ée]duction|offre|en promotion|بالتخفيض|تخفيضات|عروض|barcha tkhfidh/u', $lower)) {
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
        if (preg_match('/\bpacks?\b|bundle|lot\b|coffret|مجموعة|باقة/u', $lower)) {
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
        if (preg_match('/\bstore\b|open.*store|my store|\bsell\b|vendor|vendeur|devenir.*vendeur|become.*seller|how.*sell|بائع|كيف.*أبيع|كيف أصبح|أصبح تاجر|تاجر|nheb nbii|besh nbii|nwali|nweli|nbii|nbi3|كيف نبيع|كيف نولي|comment vendre|comment devenir|devenir vendeur/u', $lower)) {
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

        // ── 8. Browsing intent ────────────────────────────────────────────
        if (preg_match('/\bbrowse\b|browsing|just looking|voir les produits|تصفح/u', $lower)) {
            return [
                'type'        => 'trending_products',
                'query'       => null,
                'raw_message' => $message,
                'price_min'   => null,
                'price_max'   => null,
                'sort'        => 'created_at',
            ];
        }

        // ── 9. Contextual reference ("show cheaper ones", "show more") ────
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

        // ── 10. Explicit product search ───────────────────────────────────
        $searchSignals = [
            // English intent verbs only
            'need', 'want', 'looking for', 'find', 'show', 'search', 'buy',
            'get me', 'give me', 'recommend', 'suggest', 'price', 'cheap',
            'affordable', 'browse', 'explore', 'discover', 'do you have',
            'have you got', 'would like',
            // French intent verbs
            'cherche', 'trouver', 'acheter', 'besoin', 'montrer', 'veux',
            'affiche', 'avez vous', 'est ce que vous avez', 'souhaite',
            'je recherche', 'dénicher', 'acquérir', 'commander',
            // Arabic intent verbs
            'نحتاج', 'أريد', 'اريد', 'ابحث', 'أبحث', 'اشتري', 'عايز',
            'عندكم', 'وريني', 'بدي', 'ابغى', 'أرغب', 'أود', 'أبحث عن',
            // Darija intent verbs
            'warini', 'orini', 'arini', 'nheb', 'nchri', 'nlawj',
            '3andkom', 'jibli', 'n7eb',
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

        // ── 11. General / greeting / unknown ──────────────────────────────
        return ['type' => 'general', 'query' => $message, 'raw_message' => $message];
    }

    /**
     * Detect user language from message text.
     * Returns exactly one of: 'English' | 'French' | 'Arabic' | 'Tunisian Darija'
     */
    public function detectLanguage(string $message): string
    {
        $msg = mb_strtolower(trim($message));

        // ── 1. Arabic script → immediate ─────────────────────────────────
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $msg)) {
            return 'Arabic';
        }

        // ── 2. Tunisian Darija romanized — strong signals ─────────────────
        $darijaWords = [
            'bahi', '3andna', '3andek', 'mafamach', 'warini', 'nheb',
            'barcha', 'nchri', 'chnia', '9adeh', 'yezzi', 'mrigla',
            'hedha', 'hedhy', 'hedhom', 'emchi', 'nlawj', 'nwali',
            'besh nchri', 'kifesh', 'wesh', 'labas', 'la bess',
        ];
        foreach ($darijaWords as $word) {
            if (str_contains($msg, $word)) return 'Tunisian Darija';
        }

        // ── 3. French greetings (single word is enough) ───────────────────
        $frenchGreetings = [
            'bonjour', 'bonsoir', 'salut', 'merci', 'bienvenue', 'allô', 'allo',
        ];
        foreach ($frenchGreetings as $word) {
            if (str_contains($msg, $word)) return 'French';
        }

        // ── 4. English greetings (single word is enough) ──────────────────
        $englishGreetings = [
            'hello', 'hi', 'hey', 'good morning', 'good evening',
            'good afternoon', 'thanks', 'thank you',
        ];
        foreach ($englishGreetings as $word) {
            if (str_contains($msg, $word)) return 'English';
        }

        // ── 5. French strong phrases (1 is enough) ────────────────────────
        $frenchStrong = [
            'je cherche', 'je veux', "j'ai", 'est-ce que', 'est ce que',
            'comment devenir', 'devenir vendeur', 'je voudrais', 'avez vous',
            'quel est', 'puis-je', 'puis je',
        ];
        foreach ($frenchStrong as $phrase) {
            if (str_contains($msg, $phrase)) return 'French';
        }

        // ── 6. English strong phrases (1 is enough) ───────────────────────
        $englishStrong = [
            'i want', 'i need', 'i wanna', 'show me', 'can you', 'how to',
            'how do', 'what is', 'tell me', 'give me', 'looking for',
            'could you', 'do you have', "i'm", 'i am', 'my store',
            'become a', 'open my', 'steps to', 'where is', 'how much',
            'what are', 'please', 'would like',
        ];
        foreach ($englishStrong as $signal) {
            if (str_contains($msg, $signal)) return 'English';
        }

        // ── 7. French weak signals — need 2+ ──────────────────────────────
        $frenchWeak = [
            'je', 'tu', 'il', 'nous', 'vous', 'les', 'des', 'une',
            'pour', 'avec', 'sur', 'dans', 'veux', 'besoin', 'trouver',
            'cherche', 'montrer', 'acheter',
        ];
        $frenchCount = 0;
        foreach ($frenchWeak as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $msg)) {
                $frenchCount++;
            }
        }
        if ($frenchCount >= 2) return 'French';

        // ── 8. Default ────────────────────────────────────────────────────
        return 'English';
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Resolve "show cheaper ones" / "zid warini" into a concrete search query
     * by looking at the last meaningful user search in history.
     */
    private function resolveContextualReference(string $message, array $history): string
    {
        $lower = mb_strtolower($message);

        $contextSignals = [
            // English — phrases only (no single ambiguous words)
            'cheaper', 'more expensive', 'show more', 'show me more',
            'more like this', 'more of these', 'like this', 'something similar',
            'something else', 'different one', 'same brand', 'same style',
            'same price', 'same category', 'another one', 'similar ones',
            // French — phrases only
            'moins cher', 'plus cher', 'montre plus', 'plus comme ça',
            'comme ça', 'quelque chose de similaire', 'même marque',
            'même style', 'même prix', 'un autre', 'une autre',
            // Arabic
            'أرخص', 'أغلى', 'مشابه', 'مشابهة', 'نفس الماركة', 'نفس السعر',
            'واحد آخر', 'المزيد', 'أفضل',
            // Darija — phrases
            'zidni', 'zid akther', 'warini akther', 'warini haja okhra',
            'haja okhra', 'nafsou', 'kima heka', 'mrigel akther',
            'a7sen', 'okhra', 'okhrin', 'kima hedha', 'nafs soum',
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

        // Find last meaningful user search in history
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

        // Apply modifiers
        $modifiers = [];
        if (preg_match('/cheaper|moins cher|rkhis|أرخص/u', $lower))  $modifiers[] = 'cheap';
        if (preg_match('/expensive|premium|غالي/u', $lower))          $modifiers[] = 'premium';
        if (preg_match('/\bred\b|rouge|أحمر/u', $lower))              $modifiers[] = 'red';
        if (preg_match('/\bblue\b|bleu|أزرق/u', $lower))             $modifiers[] = 'blue';
        if (preg_match('/\bblack\b|noir|أسود/u', $lower))            $modifiers[] = 'black';
        if (preg_match('/\bwhite\b|blanc|أبيض/u', $lower))           $modifiers[] = 'white';

        $resolved = $lastUserSearch;
        if (!empty($modifiers)) {
            $resolved = implode(' ', $modifiers) . ' ' . $resolved;
        }

        return $resolved;
    }

    public function extractPriceMax(string $lower): ?float
    {
        if (preg_match('/(?:under|moins de|below|max|أقل من)\s*(\d+)/u', $lower, $m)) return (float) $m[1];
        if (preg_match('/(\d+)\s*(?:tnd|dt|دينار)/u', $lower, $m)) return (float) $m[1];
        foreach (['cheap', 'pas cher', 'رخيص', 'affordable', 'budget', 'rkhis'] as $s) {
            if (mb_strpos($lower, $s) !== false) return 200.0;
        }
        return null;
    }

    public function extractPriceMin(string $lower): ?float
    {
        if (preg_match('/(?:over|plus de|above|min|أكثر من)\s*(\d+)/u', $lower, $m)) return (float) $m[1];
        return null;
    }

    public function extractSort(string $lower): string
    {
        if (preg_match('/cheap|less|low.?price|pas cher|رخيص|rkhis|moins cher/u', $lower)) return 'price_asc';
        if (preg_match('/expensive|premium|luxe|غالي|best.?quality/u', $lower))             return 'price_desc';
        if (preg_match('/popular|trending|best.?sell|most.?view|الأكثر/u', $lower))         return 'views';
        return 'created_at';
    }

    public function extractSearchQuery(string $message): string
    {
        $query = $message;

        $fillerWords = [
            // English
            'give me the cheapest', 'cheapest', 'least expensive', 'most affordable',
            'give the cheapest', 'i need', 'i want', 'i wanna', 'looking for',
            'search for', 'show me', 'find me', 'recommend', 'can you find',
            'do you have', 'can i get', 'give me', 'show', 'find', 'get me',
            'would like', 'i am looking for', "i'm looking for",
            // French
            'je cherche', 'je veux', 'montre moi', 'montrez moi', 'affiche moi',
            'trouve moi', 'avez vous', 'est ce que vous avez', 'je besoin de',
            'donne moi', 'je voudrais', 'le moins cher',
            // Arabic
            'اريد', 'أريد', 'ابحث عن', 'أبحث عن', 'عندك', 'عندكم',
            'هل عندكم', 'اعطني', 'وريني', 'نحب', 'نحب نشري',
            // Darija
            'warini', 'orini', 'arini', 'nheb', 'nchri', 'besh nchri',
            '3andek', '3andkom', 'famma', 'lawajt 3la', 'nlawwej', 'nlawj',
            'jibli', 'n7eb',
            // Generic
            'need', 'want',
        ];

        // Sort by length descending so longer phrases are removed first
        usort($fillerWords, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($fillerWords as $filler) {
            $query = trim(str_ireplace($filler, '', $query));
        }

        $query = preg_replace('/\d+\s*(tnd|dt|دينار)?/u', '', $query);
        $query = preg_replace('/under|over|moins de|plus de|أقل من|أكثر من|cheap|pas cher|رخيص/u', '', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));

        $genericWords = ['products', 'product', 'produit', 'produits', 'items',
                         'things', 'منتج', 'منتجات', 'something', 'quelque chose'];

        if (mb_strlen($query) < 2 || in_array(mb_strtolower($query), $genericWords)) {
            return '';
        }

        return $query;
    }
}