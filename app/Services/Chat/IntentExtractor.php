<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of the chatbot: turn a user message into an intent (+ search filters).
 *
 * Cheapest path first:
 *   1. Greeting and platform how-to intents (become_vendor, place_order,
 *      track_order, returns_complaints, account_help, browse_categories) are
 *      matched with the multilingual DarijaLexicon concepts — no Groq call.
 *   2. Product search with an explicit price → rules only, no Groq call.
 *   3. Anything unclear → one Groq JSON call. Any price the regex finds
 *      always overrides what the LLM returns.
 *
 * Normalised result:
 * [
 *   'intent'      => one of self::INTENTS,
 *   'section'     => ?string,       // knowledge-base section for how-to intents
 *   'keywords'    => string[],      // multilingual terms for SQL LIKE matching
 *   'keywords_en' => string,        // English phrase for the MiniLM semantic search
 *   'category'    => ?string,       // category slug
 *   'min_price'   => ?float,
 *   'max_price'   => ?float,
 *   'sort'        => 'relevance'|'price_asc'|'price_desc'|'rating',
 *   'refine'      => ?string,       // 'cheaper'|'pricier'|'more' for follow-ups
 *   'language'    => 'en'|'fr'|'ar',
 *   'source'      => 'rules'|'llm',
 * ]
 */
class IntentExtractor
{
    public const INTENTS = [
        'search', 'product_question', 'greeting', 'other',
        'become_vendor', 'place_order', 'track_order', 'returns_complaints', 'account_help', 'browse_categories',
    ];

    /** Intents answered from the knowledge base / platform data, not product search. */
    public const PLATFORM_INTENTS = [
        'become_vendor', 'place_order', 'track_order', 'returns_complaints', 'account_help', 'browse_categories',
    ];

    private const SORTS = ['relevance', 'price_asc', 'price_desc', 'rating'];

    private const CURRENCY = '(?:\s*(?:dt|tnd|dinars?|dnr|د\.?\s?ت|دينار|دنانير|دينارا))?';

    private const STOPWORDS = [
        // English
        'i', 'me', 'my', 'a', 'an', 'the', 'and', 'or', 'for', 'to', 'of', 'in', 'on', 'with', 'is', 'are', 'it',
        'want', 'need', 'looking', 'look', 'find', 'show', 'give', 'get', 'buy', 'some', 'any', 'please', 'can',
        'you', 'do', 'have', 'there', 'what', 'which', 'good', 'nice', 'best', 'cheap', 'cheapest', 'price',
        'between', 'under', 'below', 'over', 'above', 'less', 'more', 'than', 'max', 'min', 'maximum', 'minimum',
        'budget', 'around', 'about', 'from', 'up', 'dt', 'tnd', 'dinar', 'dinars', 'one', 'ones', 'something',
        'help', 'product', 'products',
        // French
        'je', 'j', 'tu', 'il', 'on', 'nous', 'vous', 'un', 'une', 'des', 'de', 'du', 'la', 'le', 'les', 'l', 'd',
        'et', 'ou', 'pour', 'avec', 'sans', 'dans', 'sur', 'est', 'sont', 'veux', 'voudrais', 'cherche', 'recherche',
        'besoin', 'montre', 'montrez', 'moi', 'avez', 'quelque', 'chose', 'pas', 'cher', 'chers', 'chère',
        'prix', 'entre', 'moins', 'plus', 'jusqu', 'environ', 'bon', 'bonne', 'meilleur', 'svp', 'stp', 'merci',
        'qui', 'que', 'quel', 'quelle', 'ce', 'cette', 'ces', 'mon', 'ma', 'mes', 'au', 'aux', 'partir', 'aide',
        'trouver', 'produit', 'produits',
        // Arabic
        'أريد', 'اريد', 'ابحث', 'أبحث', 'عن', 'في', 'من', 'إلى', 'الى', 'على', 'بين', 'أقل', 'اقل', 'أكثر', 'اكثر',
        'سعر', 'بسعر', 'دينار', 'دنانير', 'هل', 'عندكم', 'لدیکم', 'لديكم', 'لي', 'ممكن', 'شيء', 'رخيص', 'أرخص',
        'ارخص', 'تحت', 'فوق', 'حوالي', 'ميزانية', 'و', 'او', 'أو', 'مع', 'هذا', 'هذه', 'منتج', 'منتجات',
        // Tunisian darija (Arabic + Latin script)
        'نحب', 'نلوج', 'عندكمش', 'فما', 'فمة', 'شنية', 'شنوة', 'بقداه', 'قداه', 'قداش', 'بقداش', 'باهي', 'برشة',
        'ورّيني', 'وريني', 'شوية', 'متاعي', 'متاع', 'كيفاش', 'نلقى', 'عاوني',
        'n7eb', 'nheb', 'nhib', 'n7ib', 'nlawej', 'nlawjou', '3andkom', '3andek', 'andkom', 'fama', 'famma', 'chneya',
        'chnoua', 'chnowa', 'bech', 'besh', 'barcha', 'bahi', 'behi', 'warini', 'wariny', 'choufli', 'mte3', 'mta3',
        'mte3i', 'ena', 'enti', 'inti', 'wala', 'zeda', 'chwaya', 'chwia', '9adeh', '9adech', 'bkadech', 'b9adech',
        'ya5i', 'brabi', 'aslema', 'ahla', 'rkhis', 'rkhes', 'ar5es', 'r5is', 'a9al', 'men', 'min', 'bin', 'mabin',
        'lel', 'l', 'w', 'fi', 'ghali', '8ali', 'ghalia',
        // Follow-up / refine words (not product words)
        'cheaper', 'pricier', 'expensive', 'other', 'others', 'another', 'else', 'same', 'autre', 'autres',
        'encore', 'chères', 'abordable', 'abordables', 'okhrin', 'o5rin', 'okhra', 'aghla', 'a8la',
        'غيرها', 'أخرى', 'اخرى', 'آخر', 'المزيد', 'أغلى', 'اغلى', 'نفس', 'أخرين', 'اخرين',
    ];

    public function __construct(
        private GroqClient    $groq,
        private DarijaLexicon $lexicon,
    ) {}

    /**
     * @param array $memory   ChatMemory::get() result
     * @param bool  $forceLlm skip the rules fast path (used when it found nothing)
     */
    public function extract(string $message, array $memory, bool $forceLlm = false): array
    {
        $text     = $this->normalize($message);
        $previous = $memory['filters'] ?? null;
        $language = $this->detectLanguage($message) ?? ($memory['language'] ?? null);

        if ($this->isGreeting($text)) {
            return $this->result('greeting', [], $language, 'rules');
        }

        $concepts = $this->lexicon->concepts($text);
        $price    = $this->parsePrice($text);
        $sort     = $this->parseSort($text);
        $keywords = $this->ruleKeywords($text);

        // How-to / platform questions: answered from the knowledge base, no Groq.
        if (!$forceLlm && !($price && $keywords)) {
            $platform = $this->platformIntent($text, $concepts, $keywords);
            if ($platform !== null) {
                return $this->result($platform, [
                    'section' => $this->section($platform, $text, $concepts),
                ], $language, 'rules');
            }
        }

        // "cheaper ones" refines the last search; "robe rouge moins chère" is a new one.
        $refine   = $previous && count($keywords) <= 1 ? $this->parseRefine($text) : null;
        $followUp = $previous && ($refine !== null || $this->looksLikeFollowUp($text, $keywords));

        // Fast path: explicit price + simple product words + known language, not a follow-up.
        if (!$forceLlm && $price && $language && !$followUp && $keywords) {
            return $this->result('search', [
                'keywords'  => $this->lexicon->expandKeywords($keywords),
                'min_price' => $price['min'],
                'max_price' => $price['max'],
                'sort'      => $sort ?? 'relevance',
            ], $language, 'rules');
        }

        $llm = $this->askLlm($message, $memory);

        if ($llm === null) {
            return $this->ruleFallback($keywords, $price, $sort, $refine, $followUp, $previous, $language);
        }

        $intent = $llm['intent'] ?? null;
        if ($intent === 'order_help') {
            $intent = 'track_order';
        }
        if (!in_array($intent, self::INTENTS, true)) {
            $intent = 'search';
        }

        $llmLang  = in_array($llm['language'] ?? null, ['en', 'fr', 'ar'], true) ? $llm['language'] : null;
        $language = $language ?? $llmLang ?? config('services.groq.default_language', 'fr');

        if (in_array($intent, self::PLATFORM_INTENTS, true)) {
            return $this->result($intent, ['section' => $this->section($intent, $text, $concepts)], $language, 'llm');
        }
        if ($intent === 'greeting') {
            return $this->result('greeting', [], $language, 'llm');
        }

        $data = [
            'keywords'    => $this->cleanList($llm['keywords'] ?? []),
            'keywords_en' => is_string($llm['keywords_en'] ?? null) ? mb_substr(trim($llm['keywords_en']), 0, 80) : '',
            'category'    => $this->validCategory($llm['category'] ?? null),
            'min_price'   => $this->toPrice($llm['min_price'] ?? null),
            'max_price'   => $this->toPrice($llm['max_price'] ?? null),
            'sort'        => in_array($llm['sort'] ?? null, self::SORTS, true) ? $llm['sort'] : 'relevance',
            'refine'      => in_array($llm['refine'] ?? null, ['cheaper', 'pricier', 'more'], true) ? $llm['refine'] : null,
        ];

        // Deterministic signals win over the model.
        if ($price) {
            $data['min_price'] = $price['min'];
            $data['max_price'] = $price['max'];
        }
        if ($sort) {
            $data['sort'] = $sort;
        }
        if ($refine) {
            $data['refine'] = $refine;
        }
        if (!$data['keywords'] && $keywords && in_array($intent, ['search', 'product_question'], true)) {
            $data['keywords'] = $this->lexicon->expandKeywords($keywords);
        }

        return $this->result($intent, $data, $language, 'llm');
    }

    // ── Platform intents ─────────────────────────────────────────────────

    /**
     * Rule-based detection of the how-to intents from lexicon concepts.
     * Order matters: the most specific intents are checked first.
     *
     * @param string[] $concepts
     * @param string[] $keywords product words left in the message
     */
    public function platformIntent(string $text, array $concepts, array $keywords): ?string
    {
        $has = fn (string ...$c) => (bool) array_intersect($c, $concepts);

        if ($has('CATEGORIES') && !$keywords) {
            return 'browse_categories';
        }
        if (($has('MY_ORDERS') && !$has('HOW'))
            || ($has('ORDER', 'DELIVERY') && $has('WHERE', 'TRACK'))
            || ($has('ORDER') && $has('MEANING'))) {
            return 'track_order';
        }
        if ($has('RETURN')) {
            return 'returns_complaints';
        }
        // "can I use a seller coupon on a pack?" is a buyer question, not selling.
        if ($has('COUPON') && !$has('COMMISSION', 'PLAN', 'ADD_PRODUCT')) {
            return 'place_order';
        }
        if ($has('SELL', 'PLAN', 'COMMISSION', 'ADD_PRODUCT') || ($has('OPEN') && $has('STORE'))
            || ($has('REQUIRE') && $has('STORE'))) {
            return 'become_vendor';
        }
        if ($has('PASSWORD', 'SIGNUP', 'LOGIN', 'GOOGLE') || ($has('WALLET') && !$has('PAY'))
            || ($has('ACCOUNT') && !$keywords)) {
            return 'account_help';
        }
        if ($has('PAY', 'COUPON', 'CART') || ($has('HOW') && $has('ORDER', 'BUY'))) {
            return 'place_order';
        }
        return null;
    }

    /** Knowledge-base section for a platform intent. */
    public function section(string $intent, string $text, array $concepts): ?string
    {
        $has = fn (string ...$c) => (bool) array_intersect($c, $concepts);

        switch ($intent) {
            case 'become_vendor':
                if ($has('COMMISSION')) {
                    return 'commission';
                }
                if ($has('ADD_PRODUCT')) {
                    return 'add_products';
                }
                if ($has('PLAN', 'HOW_MUCH') || preg_match('/\b(plans?|price|prices|pricing|cost|tarifs?|coûts?|coute)\b|أسعار|سوم|الأسوام/u', $text)) {
                    return 'plans';
                }
                return 'apply';
            case 'place_order':
                if ($has('COUPON')) {
                    return 'coupon';
                }
                return $has('PAY') ? 'payment' : 'overview';
            case 'account_help':
                if ($has('PASSWORD')) {
                    return 'password';
                }
                if ($has('WALLET')) {
                    return 'wallet';
                }
                if ($has('SIGNUP', 'LOGIN', 'GOOGLE')) {
                    return 'signup';
                }
                return 'profile';
            case 'track_order':
                // "what does pending mean?" → status glossary; otherwise the user's own orders.
                return $has('MEANING') ? 'statuses' : 'mine';
            case 'returns_complaints':
                return 'file';
        }
        return null;
    }

    // ── Language ──────────────────────────────────────────────────────────

    /**
     * 'ar' for Arabic script or Latin-script darija (including mixed
     * darija/French), 'fr' / 'en' by word scoring, null when there is no
     * signal (e.g. "iphone 13").
     */
    public function detectLanguage(string $message): ?string
    {
        if (preg_match('/\p{Arabic}/u', $message)) {
            return 'ar';
        }

        // Ignore all-caps codes with digits (order numbers "ORD-X3YZ7K9A", SKUs): not arabizi.
        $lower = mb_strtolower(preg_replace('/\b(?=[A-Z0-9-]*\d)[A-Z][A-Z0-9-]{3,}\b/', ' ', $message));
        $words = preg_split('/[^\p{L}\p{N}\']+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);

        // Arabizi: digits used as letters inside words (n7eb, 3andek, 9adech)
        if (preg_match('/\b[a-z]*[a-z][2379][a-z]+\b|\b[3579][a-z]{3,}\b/', $lower)
            || array_intersect($words, DarijaLexicon::DARIJA_LATIN)) {
            return 'ar';
        }

        $fr = ['je', 'veux', 'voudrais', 'cherche', 'bonjour', 'bonsoir', 'salut', 'merci', 'entre', 'moins', 'cher',
               'prix', 'pour', 'avec', 'une', 'des', 'les', 'est', 'pas', 'dinars', 'avez', 'vous', 'montre', 'moi',
               'quel', 'quelle', 'quels', 'commande', 'livraison', 'jusqu', 'plus', 'et', 'de', 'du', 'la', 'le',
               'rouge', 'noir', 'blanc', 'chaussures', 'robe', 'sac', 'comment', 'où', 'vendre', 'vendeur',
               'boutique', 'compte', 'mot', 'passe', 'remboursement', 'retour', 'payer', 'paiement', 'réclamation',
               'combien', 'mes', 'mon', 'ma', 'suivre', 'oublié', 'panier', 'catégories', 'devenir', 'faire'];
        $en = ['i', 'want', 'need', 'looking', 'for', 'the', 'show', 'me', 'hello', 'hi', 'hey', 'under', 'between',
               'and', 'cheap', 'cheaper', 'what', 'is', 'do', 'you', 'have', 'price', 'order', 'my', 'with', 'red',
               'black', 'white', 'shoes', 'dress', 'bag', 'any', 'some', 'please', 'thanks', 'less', 'than', 'how',
               'where', 'track', 'seller', 'sell', 'store', 'account', 'password', 'return', 'refund', 'pay',
               'forgot', 'become', 'much', 'orders', 'categories', 'complaint', 'can', 'does', 'work', 'wrong',
               'received', 'broken', 'damaged', 'size', 'item', 'delivery', 'login', 'sign', 'wallet', 'where\'s',
               'coupon', 'plans', 'commission', 'products', 'add', 'status', 'mean'];

        $frScore = count(array_intersect($words, $fr)) + (preg_match('/[éèêàçùûôî]/u', $lower) ? 2 : 0);
        $enScore = count(array_intersect($words, $en));

        if ($frScore === $enScore) {
            return null;
        }
        return $frScore > $enScore ? 'fr' : 'en';
    }

    // ── Prices ────────────────────────────────────────────────────────────

    /**
     * Returns ['min' => ?float, 'max' => ?float] or null when no price is mentioned.
     * Works on normalize()d text (lowercase, Western digits).
     */
    public function parsePrice(string $text): ?array
    {
        $n   = '(\d+(?:[.,]\d+)?)';
        $cur = self::CURRENCY;

        $range = [
            // between 20 and 50 / entre 20 et 50 / بين 20 و 50 / من 20 إلى 50 / bin 20 w 50
            '/(?:between|entre|from|de|du|من|بين|ما\s*بين|bin|mabin|men|min)\s*' . $n . $cur . '\s*(?:and|et|to|à|a|au|-|–|إلى|الى|ل|و|w|l|lel)\s*' . $n . $cur . '/u',
            // 20-50 dt / 20 à 50 dinars
            '/' . $n . $cur . '\s*(?:-|–|à|to|a|إلى|الى)\s*' . $n . '\s*(?:dt|tnd|dinars?|د\.?\s?ت|دينار|دنانير)/u',
        ];
        foreach ($range as $re) {
            if (preg_match($re, $text, $m)) {
                $a = $this->toPrice($m[1]);
                $b = $this->toPrice($m[2]);
                if ($a !== null && $b !== null) {
                    return ['min' => min($a, $b), 'max' => max($a, $b)];
                }
            }
        }

        $max = '/(?:under|below|less\s+than|cheaper\s+than|up\s+to|at\s+most|max(?:imum)?|budget(?:\s+(?:of|de|is))?|moins\s+de|pas\s+plus\s+de|jusqu\'?\s*[àa]|inf[ée]rieur\s+[àa]|maximum|أقل\s+من|اقل\s+من|بأقل\s+من|تحت|لا\s+يتجاوز|أقصى|حد\s+أقصى|ميزانية|ميزانيتي|a9al\s+(?:men|min)|ta7t|ma\s*yfoutech|budget\s*mte3i|<=?|≤)\s*' . $n . $cur . '/u';
        if (preg_match($max, $text, $m)) {
            return ['min' => null, 'max' => $this->toPrice($m[1])];
        }

        // "30dt max", "100 dinars maximum"
        if (preg_match('/' . $n . '\s*(?:dt|tnd|dinars?|د\.?\s?ت|دينار)\s*(?:max(?:imum)?|au\s+plus|or\s+less|ou\s+moins|على\s+الأكثر)/u', $text, $m)) {
            return ['min' => null, 'max' => $this->toPrice($m[1])];
        }

        $min = '/(?:over|above|more\s+than|at\s+least|min(?:imum)?|plus\s+de|au\s+moins|[àa]\s+partir\s+de|sup[ée]rieur\s+[àa]|أكثر\s+من|اكثر\s+من|فوق|على\s+الأقل|akther\s+(?:men|min)|a5ter\s+(?:men|min)|>=?|≥)\s*' . $n . $cur . '/u';
        if (preg_match($min, $text, $m)) {
            return ['min' => $this->toPrice($m[1]), 'max' => null];
        }

        return null;
    }

    // ── Rule helpers ──────────────────────────────────────────────────────

    public function normalize(string $message): string
    {
        $text = strtr($message, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '’' => "'", '،' => ',', '؟' => '?',
        ]);
        $text = mb_strtolower(trim($text));
        // "20dt" → "20 dt", "dt20" → "dt 20". Arabizi words like "n7eb" stay intact.
        $text = preg_replace('/(\d)(dt|tnd|dinars?|dnr|د\.?\s?ت|دينار|دنانير)/u', '$1 $2', $text);
        $text = preg_replace('/\b(dt|tnd)(\d)/u', '$1 $2', $text);
        return preg_replace('/\s+/u', ' ', $text);
    }

    private function isGreeting(string $text): bool
    {
        $clean = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $clean = preg_replace('/\s+/u', ' ', $clean);

        return (bool) preg_match(
            '/^(hi|hello|hey|hiya|good\s+(morning|evening|afternoon)|salut|bonjour|bonsoir|coucou|cc|slt|bjr|'
            . 'salam|slm|salem|aslema|asslema|ahla|ahlan|marhba|3aslema|'
            . 'مرحبا|أهلا|اهلا|السلام\s+عليكم|سلام|عسلامة|صباح\s+الخير|مساء\s+الخير|أهلا\s+وسهلا)'
            . '(\s+(there|all|everyone|tout\s+le\s+monde|[çc]a\s+va|comment\s+[çc]a\s+va|labes|chnahwalek|how\s+are\s+you|3likom|alaikom|عليكم|لاباس|شنحوالك|كيف\s+حالك))*$/u',
            $clean
        );
    }

    private function parseSort(string $text): ?string
    {
        if (preg_match('/cheapest|lowest\s+price|moins\s+cher|pas\s+cher|le\s+moins|الأرخص|ارخص|أرخص|rkhis|ar5es|r5is|a9al\s+soum/u', $text)) {
            return 'price_asc';
        }
        if (preg_match('/most\s+expensive|highest\s+price|plus\s+cher|haut\s+de\s+gamme|الأغلى|أغلى|اغلى/u', $text)) {
            return 'price_desc';
        }
        if (preg_match('/best\s+rated|top\s+rated|highest\s+rated|mieux\s+not[ée]|meilleure?s?\s+note|الأفضل\s+تقييم|أعلى\s+تقييم|a7san\s+wa7ed/u', $text)) {
            return 'rating';
        }
        return null;
    }

    private function parseRefine(string $text): ?string
    {
        if (preg_match('/cheaper|less\s+expensive|moins\s+ch[eè]re?s?|plus\s+abordable|أرخص|ارخص|rkhis|ar5es|a9al\s+soum/u', $text)) {
            return 'cheaper';
        }
        if (preg_match('/more\s+expensive|pricier|higher\s+end|plus\s+ch[eè]re?s?|أغلى|اغلى|a8la|aghla/u', $text)) {
            return 'pricier';
        }
        if (preg_match('/\b(more|others?|another|else|autres?|encore|d\'autres|okhrin|o5rin|okhra|zeda)\b|غيرها|أخرى|اخرى|آخر|أخرين|اخرين|المزيد/u', $text)) {
            return 'more';
        }
        return null;
    }

    private function looksLikeFollowUp(string $text, array $keywords): bool
    {
        // "in red?", "en noir", "w bel a7mer", "بالأحمر", "and for kids?"
        return count($keywords) <= 2 && (bool) preg_match(
            '/^(and|et|w|و|ou|or|in|en|with|avec|b|bel|ب|same|meme|même|نفس)(\s|$)/u',
            $text
        );
    }

    /** Product words left after removing prices, currencies, filler and intent words. */
    private function ruleKeywords(string $text): array
    {
        // Drop standalone numbers only; keep Arabizi words such as "n7eb".
        $stripped = preg_replace('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?(?![\p{L}\p{N}])/u', ' ', $text);
        $words    = preg_split('/[^\p{L}\p{N}\']+/u', $stripped, -1, PREG_SPLIT_NO_EMPTY);

        $stop = array_flip(array_merge(self::STOPWORDS, $this->lexicon->conceptWords()));
        $out  = [];
        foreach ($words as $w) {
            $w = trim($w, "'");
            if (mb_strlen($w) < 3 || isset($stop[$w])) {
                continue;
            }
            $out[] = $w;
            // Arabic article: "الأحذية" → keep both forms for matching
            if (preg_match('/^ال\p{Arabic}{3,}$/u', $w)) {
                $out[] = mb_substr($w, 2);
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    private function ruleFallback(
        array $keywords, ?array $price, ?string $sort, ?string $refine,
        bool $followUp, ?array $previous, ?string $language
    ): array {
        $language = $language ?? config('services.groq.default_language', 'fr');

        $data = [
            'keywords'  => $this->lexicon->expandKeywords($keywords),
            'min_price' => $price['min'] ?? null,
            'max_price' => $price['max'] ?? null,
            'sort'      => $sort ?? 'relevance',
            'refine'    => $refine,
        ];

        if ($followUp && $previous) {
            // Keep the previous search and add any new words ("in red" → + red)
            $data['keywords']    = array_values(array_unique(array_merge($previous['keywords'] ?? [], $keywords)));
            $data['keywords_en'] = $previous['keywords_en'] ?? '';
            $data['category']    = $previous['category'] ?? null;
            $data['min_price']   = $price ? $price['min'] : ($previous['min_price'] ?? null);
            $data['max_price']   = $price ? $price['max'] : ($previous['max_price'] ?? null);
        }

        return $this->result($data['keywords'] || $price || $refine ? 'search' : 'other', $data, $language, 'rules');
    }

    // ── LLM ───────────────────────────────────────────────────────────────

    private function askLlm(string $message, array $memory): ?array
    {
        $previous = $memory['filters'] ?? null;
        $prevJson = $previous ? json_encode(array_intersect_key($previous, array_flip([
            'keywords', 'keywords_en', 'category', 'min_price', 'max_price', 'sort',
        ])), JSON_UNESCAPED_UNICODE) : 'none';

        $lastUser = collect($memory['turns'] ?? [])->where('role', 'user')->pluck('content')->take(-2)
            ->map(fn ($t) => '- ' . mb_substr($t, 0, 150))->implode("\n");

        $system = <<<PROMPT
You classify messages for Choose'Tounsi, a Tunisian marketplace (prices in DT/TND).
Users write English, French, Arabic, Tunisian darija (Arabic script, or Latin with digits: 7=ح 3=ع 9=ق 5=خ) or a mix.
Return ONLY a JSON object:
{"intent":"","keywords":[],"keywords_en":"","category":null,"min_price":null,"max_price":null,"sort":"relevance","refine":null,"language":"en|fr|ar"}
intent is one of:
- search: looking for products (default)
- product_question: a question about products we sell
- become_vendor: selling on the platform, opening a store, seller plans, commission
- place_order: how to order, cart, checkout, payment, coupons
- track_order: where is my order, order status, delivery of an existing order
- returns_complaints: problem with an order, return, refund, exchange, complaint
- account_help: sign up, login, Google login, password, wallet, profile
- browse_categories: what categories/products do you have in general
- greeting: only a greeting; other: anything else
Rules for search:
- keywords: max 6 short product words (singular). Include the user's word AND its French, English and Arabic equivalents (e.g. sabbat -> ["sabbat","chaussure","shoe","حذاء"]). Colors/materials count. No prices, no filler words.
- keywords_en: short English product phrase, "" if none.
- category: one of [{$this->categoryList()}] or null.
- refine: "cheaper", "pricier" or "more" when the user asks for cheaper / pricier / other items than last time, else null.
- Follow-ups ("in red?", "cheaper ones", "w lel sghar?"): merge with PREVIOUS filters and keep their keywords.
- language: "ar" for Arabic script or darija, otherwise "fr" or "en".
PROMPT;

        $user = "PREVIOUS: {$prevJson}\n"
            . ($lastUser ? "EARLIER MESSAGES:\n{$lastUser}\n" : '')
            . 'MESSAGE: ' . mb_substr($message, 0, 400);

        return $this->groq->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], 'intent');
    }

    // ── Small helpers ─────────────────────────────────────────────────────

    private function result(string $intent, array $data, ?string $language, string $source): array
    {
        return array_merge([
            'intent'      => $intent,
            'section'     => null,
            'keywords'    => [],
            'keywords_en' => '',
            'category'    => null,
            'min_price'   => null,
            'max_price'   => null,
            'sort'        => 'relevance',
            'refine'      => null,
        ], $data, [
            'language' => $language ?? config('services.groq.default_language', 'fr'),
            'source'   => $source,
        ]);
    }

    private function cleanList($list): array
    {
        if (is_string($list)) {
            $list = preg_split('/[,;]+/u', $list);
        }
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim(preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', mb_strtolower($item)));
            if (mb_strlen($item) >= 2 && mb_strlen($item) <= 40) {
                $out[] = $item;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    private function toPrice($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = is_string($value) ? str_replace(',', '.', $value) : $value;
        if (!is_numeric($value)) {
            return null;
        }
        $price = (float) $value;
        return $price > 0 && $price < 1000000 ? $price : null;
    }

    private function validCategory($slug): ?string
    {
        return is_string($slug) && in_array($slug, $this->categorySlugs(), true) ? $slug : null;
    }

    private function categorySlugs(): array
    {
        return Cache::remember('chat:category_slugs', 3600, fn () => DB::table('categories')
            ->where('is_active', true)->orderBy('order')->pluck('slug')->all());
    }

    private function categoryList(): string
    {
        return implode(', ', $this->categorySlugs());
    }
}
