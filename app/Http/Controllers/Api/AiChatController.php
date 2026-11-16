<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chat\ActionFactory;
use App\Services\Chat\ChatCatalog;
use App\Services\Chat\ChatLexicon;
use App\Services\Chat\IntentExtractor;
use App\Services\Chat\KnowledgeBase;
use App\Services\Chat\LanguageDetector;
use App\Services\Chat\OrderLookup;
use App\Services\Chat\ProductRetriever;
use App\Services\Chat\ReplyComposer;
use App\Services\Chat\SmallTalk;
use App\Services\ChatMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/ai/chat — Choose'Tounsi assistant.
 *
 *   A. LanguageDetector  the LATEST message decides language + script (fr, en, msa, tn_arabic, tn_arabizi)
 *   B. IntentExtractor   message → intent + slots (category, gender, price, colors ...). Rules first
 *                        (config/chatbot_darija.php), one Groq JSON call only when the rules are not enough.
 *   C. conversation state (ChatMemory) merges refinements ("rkhis akther", "bel abyedh", "lel rjel")
 *   D. ProductRetriever  real products, HARD filters (category, gender, final price, color, size)
 *   E. relaxation        color → size → price, announced honestly; gender and category never relaxed
 *   F. ReplyComposer     grounded Groq reply in the customer's language, or a template
 *   G. validation        ids ⊆ retrieved, prices from the DB, language + script check, then chips
 *
 * Response (always HTTP 200 with a reply, even when Groq / FastAPI are down):
 *   { success, reply, language, dialect, intent, products: [...], steps: [{title, description}],
 *     actions: [{type: "link", label, url} | {type: "quick_reply", label, message}] }
 *
 * The endpoint is public; a Bearer token is optional and only used for "my orders".
 */
class AiChatController extends Controller
{
    private const SESSION_MAX_PER_MINUTE = 10;
    private const RESPONSE_CACHE_SECONDS = 300;

    /**
     * Stored in memory / chat_messages instead of any user-specific answer
     * (order details, login prompts). Such answers are also never cached.
     */
    private const ORDERS_PLACEHOLDER = '[recent orders shown]';

    public function __construct(
        private IntentExtractor  $extractor,
        private ProductRetriever $retriever,
        private ReplyComposer    $composer,
        private KnowledgeBase    $kb,
        private OrderLookup      $orders,
        private ActionFactory    $actions,
        private ChatMemory       $memory,
        private LanguageDetector $detector,
        private ChatCatalog      $catalog,
        private ChatLexicon      $lex,
        private SmallTalk        $talk,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => 'required|string|max:500',
            'session_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'locale'     => 'nullable|string|in:fr,ar,en',
        ]);

        $started   = microtime(true);
        $sessionId = $request->input('session_id');
        $message   = trim($request->input('message'));

        // Optional auth: the route is public, a valid Sanctum token identifies the user.
        /** @var User|null $user */
        $user   = Auth::guard('sanctum')->user();
        $userId = $user?->id;

        $memory = $this->memory->get($sessionId, $userId);

        // The LATEST message decides the language and script. No signal ("iphone 13"):
        // the dialect of the conversation, else the storefront language picked by the shopper.
        $detected = $this->detector->detect($message);
        $previous = $memory['dialect'] ?? null;
        // Tunisians mix French into Darija: a short follow-up ("pour homme", "moins cher", "en rouge", "taille M")
        // in a Tunisian conversation stays Tunisian. A full sentence in French / English switches.
        if (in_array($detected, ['fr', 'en'], true) && in_array($previous, ['tn_arabizi', 'tn_arabic'], true)
            && count(preg_split('/\s+/u', $message, -1, PREG_SPLIT_NO_EMPTY)) <= 4
            && $this->extractor->hasRefinementSignal($message)) {
            $detected = $previous;
        }
        $dialect  = $detected
            ?? $previous
            ?? $this->detector->fromLocale($request->input('locale') ?: app()->getLocale());
        $language = LanguageDetector::baseLanguage($dialect);

        $limitKey = 'chat:session:' . $sessionId;
        if (RateLimiter::tooManyAttempts($limitKey, self::SESSION_MAX_PER_MINUTE)) {
            return $this->respond(['reply' => $this->composer->tooFast($dialect), 'language' => $language, 'dialect' => $dialect, 'intent' => 'rate_limited']);
        }
        RateLimiter::hit($limitKey, 60);

        try {
            // Identical question in the same conversation state → no Groq call.
            // Never holds personal data: track_order answers are not cached.
            $cacheKey = 'chat:resp:v4:' . md5(implode('|', [
                $dialect,
                $this->extractor->normalize($message),
                json_encode($memory['filters']),
                json_encode(array_column($memory['shown'], 'id')),
            ]));

            if ($cached = Cache::get($cacheKey)) {
                $this->memory->remember($sessionId, $userId, $message, $cached['reply'], $cached['language'], $cached['filters'], $cached['shown'], $cached['dialect'] ?? $dialect);
                Log::info('[AiChat] Response cache hit', ['session' => $sessionId]);
                return $this->respond($cached);
            }

            $intent             = $this->extractor->extract($message, $memory);
            $intent['dialect']  = $dialect;
            $intent['language'] = $language;

            $out = match ($intent['intent']) {
                'greeting'          => $this->greeting($dialect, (bool) ($intent['salam'] ?? false)),
                'speaks_tounsi', 'founders', 'identity', 'thanks', 'bye'
                                    => $this->smallTalk($intent['intent'], $dialect),
                'browse_categories' => $this->categories($dialect),
                'track_order'       => $this->trackOrder($intent, $message, $dialect, $user),
                'become_vendor', 'place_order', 'returns_complaints', 'account_help'
                                    => $this->knowledge($intent, $message, $dialect),
                'other'             => $this->hasSearchSignal($intent)
                                        ? $this->search($message, $intent, $memory, $dialect)
                                        : $this->other($dialect),
                default             => $this->search($message, $intent, $memory, $dialect),
            };

            $out += ['products' => [], 'steps' => [], 'actions' => [], 'filters' => null, 'personal' => false];
            $out['language'] = $out['language'] ?? $language;
            $out['dialect']  = $dialect;
            $out['intent']   = $out['intent'] ?? $intent['intent'];
            $out['actions']  = $this->actions->finalize($out['actions'], $out['intent'] === 'browse_categories' ? 6 : 4);

            // "salem, n7eb sabat": a short hello in front of the product answer
            if (!empty($intent['greeting_prefix']) && $out['intent'] !== 'greeting') {
                $hello = !empty($intent['salam']) ? $this->talk->reply('salam', $dialect) : $this->talk->reply('greet_short', $dialect);
                $out['reply'] = trim($hello . ' ' . $out['reply']);
            }

            $shown       = array_map(fn ($p) => ['id' => $p['id'], 'price' => $p['price']], $out['products']);
            $storedReply = $out['personal'] ? self::ORDERS_PLACEHOLDER : $out['reply'];

            $this->memory->remember($sessionId, $userId, $message, $storedReply, $out['language'], $out['filters'], $shown, $out['remember_dialect'] ?? $dialect);
            $this->persist($sessionId, $message, $storedReply, $out['intent']);

            // A template written because Groq was rate limited or down is never cached: the next customer gets a Groq reply
            if (!$out['personal'] && ($out['reply_source'] ?? null) !== 'template') {
                Cache::put($cacheKey, [
                    'reply'    => $out['reply'],
                    'products' => $out['products'],
                    'steps'    => $out['steps'],
                    'actions'  => $out['actions'],
                    'language' => $out['language'],
                    'dialect'  => $dialect,
                    'intent'   => $out['intent'],
                    'filters'  => $out['filters'],
                    'shown'    => $shown,
                ], self::RESPONSE_CACHE_SECONDS);
            }

            Log::info('[AiChat] Answered', [
                'session'  => $sessionId,
                'intent'   => $out['intent'],
                'section'  => $intent['section'] ?? null,
                'source'   => $intent['source'],
                'dialect'  => $dialect,
                'results'  => count($out['products']),
                'ms'       => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $this->respond($out);

        } catch (\Throwable $e) {
            Log::error('[AiChat] Unhandled error: ' . $e->getMessage(), [
                'session' => $sessionId,
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return $this->respond([
                'reply'    => $this->composer->error($dialect),
                'language' => $language,
                'dialect'  => $dialect,
                'intent'   => 'error',
                'actions'  => $this->kb->starterActions($language),
            ]);
        }
    }

    // ── Intents ───────────────────────────────────────────────────────────

    private function greeting(string $dialect, bool $salam = false): array
    {
        $reply = $this->talk->reply('greeting', $dialect);
        if ($salam) {
            $reply = $this->talk->reply('salam', $dialect) . ' ' . $reply;
        }

        return ['reply' => $reply, 'actions' => $this->starter($dialect)];
    }

    /** Not about a product (or too unclear): ask what they look for, with example searches. */
    private function other(string $dialect): array
    {
        return ['reply' => $this->talk->reply('unclear', $dialect), 'actions' => $this->exampleChips($dialect)];
    }

    /** Rule-based small talk: fixed replies from config/chatbot_smalltalk.php. */
    private function smallTalk(string $type, string $dialect): array
    {
        $out = ['reply' => $this->talk->reply($type, $dialect)];

        switch ($type) {
            case 'speaks_tounsi':
                // From now on the conversation continues in Tunisian, unless the customer writes in another language
                $out['remember_dialect'] = LanguageDetector::isArabicScript($dialect) ? 'tn_arabic' : 'tn_arabizi';
                $out['actions'] = $this->exampleChips($out['remember_dialect']);
                break;
            case 'founders':
                $label = config("chatbot_smalltalk.replies.founders_chip.{$dialect}") ?? config('chatbot_smalltalk.replies.founders_chip.fr');
                $out['actions'] = [$this->actions->link($label, config('chatbot_smalltalk.about_route', '/about')), $this->kb->quickReply('find_product', $this->kbLang($dialect))];
                break;
            case 'identity':
                $out['actions'] = $this->exampleChips($dialect);
                break;
            case 'thanks':
                $out['actions'] = $this->starter($dialect);
                break;
            default: // bye
                $out['actions'] = [];
        }

        return $out;
    }

    private function exampleChips(string $dialect): array
    {
        $key   = $dialect === 'msa' ? 'tn_arabic' : $dialect;
        $pairs = config("chatbot_smalltalk.example_chips.{$key}") ?? config('chatbot_smalltalk.example_chips.fr');
        $chips = array_map(fn ($p) => $this->actions->quickReply($p[0], $p[1]), $pairs);
        $chips[] = $this->kb->quickReply('other_categories', $this->kbLang($dialect));

        return $chips;
    }

    /** Starter buttons; Arabizi gets Latin-script buttons (the knowledge base only has fr / en / Arabic script). */
    private function starter(string $dialect): array
    {
        return $this->kb->starterActions($this->kbLang($dialect));
    }

    /** The language key the knowledge base understands (fr | en | ar, or tn_arabizi for Latin-script buttons). */
    private function kbLang(string $dialect): string
    {
        return $dialect === 'tn_arabizi' ? 'tn_arabizi' : LanguageDetector::baseLanguage($dialect);
    }

    private function knowledge(array $intent, string $message, string $dialect): array
    {
        $lang = LanguageDetector::baseLanguage($dialect);
        $kbLang = $this->kbLang($dialect);

        // Payment and delivery wording is fixed (cash on delivery, free delivery), in every dialect.
        $faqKey = match (true) {
            $intent['intent'] === 'place_order' && ($intent['section'] ?? null) === 'payment'  => 'payment',
            $intent['intent'] === 'place_order' && ($intent['section'] ?? null) === 'delivery' => 'delivery',
            default => null,
        };
        $faq = $faqKey ? config("chatbot_darija.templates.faq.{$faqKey}.{$dialect}") : null;
        if ($faq) {
            return [
                'reply'   => $faq['intro'],
                'steps'   => $faq['steps'],
                'actions' => array_filter([
                    $this->actions->link($this->lex->config("chips.checkout.{$dialect}") ?? ['en' => 'Go to checkout', 'fr' => 'Aller au paiement', 'ar' => 'صفحة الخلاص'][$lang], '/checkout'),
                    $this->kb->quickReply('find_product', $kbLang),
                ]),
            ];
        }

        $kb = $this->kb->answer($intent['intent'], ($intent['section'] ?? null) === 'delivery' ? 'overview' : ($intent['section'] ?? null), $kbLang);

        return [
            'reply'   => $this->composer->knowledge($dialect, $message, $kb),
            'steps'   => $kb['steps'],
            'actions' => $kb['actions'],
        ];
    }

    private function trackOrder(array $intent, string $message, string $dialect, ?User $user): array
    {
        $lang = LanguageDetector::baseLanguage($dialect);
        $kbLang = $this->kbLang($dialect);
        $arabizi = fn (string $en) => config("chatbot_darija.kb_labels.{$en}");

        // "What does pending mean?" → status glossary from the KB (not personal).
        if (($intent['section'] ?? null) === 'statuses') {
            return $this->knowledge($intent, $message, $dialect);
        }

        if (!$user) {
            return [
                'reply'   => $this->composer->loginForOrders($dialect),
                'actions' => [
                    $this->actions->link($dialect === 'tn_arabizi' ? $arabizi('Log in') : ['en' => 'Log in', 'fr' => 'Se connecter', 'ar' => 'ادخل'][$lang], '/auth/login?redirect=/orders'),
                    $this->kb->quickReply('order_statuses', $kbLang),
                    $this->kb->quickReply('complaint_how', $kbLang),
                ],
                // Depends on who is asking → never served from the shared response cache.
                'personal' => true,
            ];
        }

        $orders = $this->orders->recentFor($user, $message, $lang);

        if (!$orders) {
            return [
                'reply'    => $this->composer->noOrders($dialect),
                'actions'  => [$this->kb->quickReply('find_product', $kbLang), $this->kb->quickReply('order_how', $kbLang)],
                'personal' => true,
            ];
        }

        return [
            'reply'    => $this->composer->ordersIntro($dialect, count($orders)),
            'steps'    => array_map(fn ($o) => ['title' => $o['title'], 'description' => $o['description']], $orders),
            'actions'  => [
                $this->actions->link($dialect === 'tn_arabizi' ? $arabizi('My orders') : ['en' => 'My orders', 'fr' => 'Mes commandes', 'ar' => 'طلباتي'][$lang], '/orders'),
                $this->kb->quickReply('order_statuses', $kbLang),
                $this->kb->quickReply('complaint_how', $kbLang),
            ],
            'personal' => true,
        ];
    }

    private function categories(string $dialect): array
    {
        $lang = LanguageDetector::baseLanguage($dialect);
        $categories = Cache::remember('chat:categories', 3600, fn () => DB::table('categories')
            ->where('is_active', true)->orderBy('order')->get(['name', 'name_ar', 'name_fr', 'slug'])->all());

        $actions = [];
        foreach ($categories as $c) {
            $col       = LanguageDetector::isArabicScript($dialect) ? 'name_ar' : ($dialect === 'en' ? null : 'name_fr');
            $label     = ($col ? $c->{$col} : null) ?: $c->name;
            $actions[] = $this->actions->link($label, '/category/' . $c->slug);
        }

        return ['reply' => $this->composer->categoriesIntro($dialect), 'actions' => $actions];
    }

    // ── Product search ────────────────────────────────────────────────────

    private function search(string $message, array $intent, array $memory, string $dialect): array
    {
        $shown   = $memory['shown'] ?? [];
        $filters = $this->resolveHard($this->filtersFrom($intent, $memory));
        $exclude = $this->applyRefine($filters, $intent['refine'] ?? null, $shown);

        $result  = $this->retriever->searchRelaxed($filters, $exclude);

        // The rules-only fast path knew no product word and found nothing, even after dropping the unknown words:
        // Groq reads the message once (free tier) and maps it to a real category / product type.
        if (!$result['products'] && $intent['source'] === 'rules' && empty($filters['concept']) && empty($filters['categories'])) {
            $llmIntent = $this->extractor->extract($message, $memory, true);
            if ($llmIntent['source'] === 'llm' && $llmIntent['intent'] === 'other' && !$this->hasSearchSignal($llmIntent)) {
                $this->logUnmatched($message, $dialect, $intent, $filters, 0, ['unclear']);

                return $this->other($dialect) + ['language' => LanguageDetector::baseLanguage($dialect), 'intent' => 'other'];
            }
            if ($llmIntent['source'] === 'llm' && in_array($llmIntent['intent'], ['search', 'product_question', 'other'], true)) {
                $intent  = ['dialect' => $dialect, 'language' => $intent['language']] + $llmIntent; // keep the detected language
                $filters = $this->resolveHard($this->filtersFrom($intent, $memory));
                $exclude = $this->applyRefine($filters, $intent['refine'] ?? null, $shown);
                $result  = $this->retriever->searchRelaxed($filters, $exclude);
            }
        }

        // Unknown words and nothing else to go on ("zigzog"): do not show random products, ask what they look for
        if (!empty($filters['keywords']) && in_array('keyword', $result['relaxed'], true) && !$this->hasStructure($filters)) {
            $this->logUnmatched($message, $dialect, $intent, $filters, 0, ['unclear']);

            return $this->other($dialect) + ['language' => LanguageDetector::baseLanguage($dialect), 'intent' => 'other'];
        }

        $lang    = LanguageDetector::baseLanguage($dialect);
        $concept = $filters['concept'] ?? null;
        $broad   = $concept ?: $this->broadConcept($filters);
        $label   = $this->lex->term($broad, $dialect);
        $gender  = in_array($filters['gender'] ?? null, ['women', 'men', 'kids'], true) ? $filters['gender'] : null;
        $genderStrict = !empty($filters['gender_strict']) ? $gender : null;

        // Words the lexicon / catalog did not understand, or a search that needed relaxing, are logged for the admin
        if (!$result['products'] || array_intersect($result['relaxed'], ['keyword', 'subcategory'])) {
            $this->logUnmatched($message, $dialect, $intent, $filters, count($result['products']), $result['relaxed']);
        }

        if (!$result['products']) {
            $refine = $shown ? ($intent['refine'] ?? null) : null;
            $ctx    = ['concept_label' => $label, 'gender' => $genderStrict];

            // "cheaper" / "others" with nothing new: say so, no products
            if (in_array($refine, ['cheaper', 'pricier', 'more'], true)) {
                return [
                    'reply' => $this->composer->noResults($dialect, $filters, ['count' => 0], $refine, $ctx),
                    'language' => $lang, 'intent' => 'search', 'filters' => $filters,
                    'actions' => $this->noResultChips($dialect, $filters, $label, $gender, null),
                ];
            }

            // Nothing in this budget but the product exists at other prices: show the closest ones, honestly
            if (!empty($filters['min_price']) || !empty($filters['max_price'])) {
                $near = $this->retriever->nearest($filters);
                if ($near['count'] > 0) {
                    $closest = $this->retriever->search(array_replace($filters, ['min_price' => null, 'max_price' => null, 'sort' => 'price_asc']))['products'];

                    return [
                        'reply'    => $this->composer->noResults($dialect, $filters, $near, null, $ctx),
                        'language' => $lang,
                        'intent'   => 'search',
                        'products' => $closest,
                        'filters'  => $filters,
                        'actions'  => $this->noResultChips($dialect, $filters, $label, $gender, $near['category_slug'] ?? ($closest[0]['category']['slug'] ?? null)),
                    ];
                }
            }

            // The whole category (+ gender) is empty: say so honestly and show the most popular products of the nearest category
            $cats = $filters['categories'] ?? ($filters['relax_categories'] ?? []);
            if (!$cats) {
                $near = $this->retriever->nearest($filters);
                $cats = !empty($near['category_slug']) ? [$near['category_slug']] : [];
            }
            $popular = $this->retriever->popular($cats) ?: ($cats ? $this->retriever->popular([]) : []);

            return [
                'reply'    => $this->composer->noResults($dialect, $filters, ['count' => 0], null, $ctx + ['popular' => (bool) $popular]),
                'language' => $lang,
                'intent'   => 'search',
                'products' => $popular,
                'filters'  => $filters,
                'actions'  => $this->noResultChips($dialect, $filters, $label, $gender, $cats[0] ?? ($popular[0]['category']['slug'] ?? null)),
            ];
        }

        $products = $result['products'];

        // Original request, so the reply can say what was relaxed
        $replyFilters = $result['filters'] + [
            'orig_colors' => $filters['colors'] ?? [],
            'orig_sizes'  => $filters['sizes'] ?? [],
            'orig_range'  => ['min_price' => $filters['min_price'] ?? null, 'max_price' => $filters['max_price'] ?? null],
        ];

        $reply = $this->composer->products($dialect, $message, $products, $replyFilters, $memory['turns'] ?? [], [
            'recipient'     => $filters['recipient'] ?? null,
            'gender'        => $genderStrict,
            'relaxed'       => $result['relaxed'],
            'unisex'        => $result['unisex'],
            'concept_label' => array_intersect($result['relaxed'], ['keyword', 'subcategory']) ? $this->lex->term($this->broadConcept($result['filters']), $dialect) : $label,
            'term_singular' => $this->lex->term($broad, $dialect, false),
            'size_attr'     => $this->lex->sizeAttr($broad),
        ]);

        // The model's picks come first; cards always come from the retrieved list
        if ($reply['picks'] && in_array($filters['sort'] ?? 'relevance', ['relevance', 'rating', 'newest', 'popular'], true)) {
            usort($products, fn ($a, $b) => (int) !in_array($a['id'], $reply['picks'], true) <=> (int) !in_array($b['id'], $reply['picks'], true));
        }

        return [
            'reply'    => $reply['reply'],
            'reply_source' => $reply['source'] ?? null,
            'language' => $lang,
            'intent'   => $intent['intent'] === 'product_question' ? 'product_question' : 'search',
            'products' => $products,
            'filters'  => $filters,
            'actions'  => $this->searchChips($dialect, $filters, $products, $label, $gender),
        ];
    }

    /** A broad word for the category of a search without a product word ("vêtements" for Mode & Vêtements). */
    private function broadConcept(array $filters): ?string
    {
        $cats = $filters['categories'] ?? ($filters['relax_categories'] ?? []);

        return in_array('fashion-clothing', $cats, true) ? 'clothes' : null;
    }

    /** Does the search say anything beyond free words? (product type, category, gender, price, color, size ...) */
    private function hasStructure(array $f): bool
    {
        return !empty($f['concept']) || !empty($f['category']) || !empty($f['categories']) || !empty($f['gender'])
            || !empty($f['min_price']) || !empty($f['max_price']) || !empty($f['colors']) || !empty($f['sizes']) || !empty($f['only_promo']);
    }

    /** Queries that ended with nothing, relaxed words or unknown words: the admin sees which words to add to the lexicon. */
    private function logUnmatched(string $message, string $dialect, array $intent, array $filters, int $count, array $relaxed): void
    {
        try {
            DB::table('chatbot_unmatched_queries')->insert([
                'message'      => mb_substr($message, 0, 500),
                'dialect'      => $dialect,
                'slots'        => json_encode([
                    'concept'    => $filters['concept'] ?? null,
                    'categories' => $filters['categories'] ?? ($filters['category'] ?? null),
                    'gender'     => $filters['gender'] ?? null,
                    'keywords'   => $filters['keywords'] ?? [],
                    'colors'     => $filters['colors'] ?? [],
                    'max_price'  => $filters['max_price'] ?? null,
                    'relaxed'    => $relaxed,
                    'source'     => $intent['source'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'result_count' => $count,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AiChat] Could not log an unmatched query: ' . $e->getMessage());
        }
    }

    /** Chips after a search with nothing to show: useful and filtered, never the generic category list. */
    private function noResultChips(string $dialect, array $filters, ?string $label, ?string $gender, ?string $categorySlug): array
    {
        $chips = [];
        $linkGen = in_array($gender, ['women', 'men'], true) && !empty($filters['gender_strict']) ? $gender : null;
        if ($categorySlug) {
            $query = array_filter([$linkGen ? "gender={$linkGen}" : null, ($filters['concept'] ?? null) && $this->catalog->concept($filters['concept']) && empty($filters['child']) && empty($this->catalog->concept($filters['concept'])['broad']) ? 'concept=' . $filters['concept'] : null]);
            $gen = $linkGen ? ($this->lex->config("chips.more_gender.{$linkGen}.{$dialect}") ?? '') : '';
            $chips[] = $this->actions->link(
                strtr($this->lex->config("chips.more_in.{$dialect}") ?? $this->lex->config('chips.more_in.fr'), ['{what}' => $label ?: $this->lex->term(null, $dialect), '{gender}' => $gen]),
                '/category/' . $categorySlug . ($query ? '?' . implode('&', $query) : '')
            );
        } else {
            $chips[] = $this->chip('categories', $dialect);
        }
        $chips[] = $this->chip('cheaper', $dialect);
        $chips[] = $this->actions->link($this->lex->config("chips.shop.{$dialect}") ?? $this->lex->config('chips.shop.fr'), '/shop');

        return $chips;
    }

    // ── Chips (localized, built from the conversation state) ──────────────

    private function searchChips(string $dialect, array $filters, array $products, ?string $label, ?string $gender): array
    {
        $chips = [$this->chip('cheaper', $dialect)];

        // "Other color": propose a color that is not already filtered on
        $used = $filters['colors'] ?? [];
        foreach (array_keys($this->lex->config('colors', [])) as $color) {
            if (!in_array($color, $used, true)) {
                $name    = $this->lex->config("color_names.{$color}.{$dialect}") ?? $color;
                $chips[] = $this->chip('other_color', $dialect, ['{color}' => $name]);
                break;
            }
        }

        $chips[] = $this->chip('pay', $dialect);

        // Link to the matching storefront page with the same gender (and subcategory when there is only one)
        $category = $products[0]['category'] ?? null;
        if ($category) {
            $subs    = array_unique(array_filter(array_column($products, 'subcategory')));
            $linkGen = in_array($gender, ['women', 'men'], true) ? $gender : null;
            // A product word links to ALL the subcategories of that product type (shoes, dresses ...) with the same gender
            $concept = $filters['concept'] ?? null;
            $useConcept = $concept && empty($filters['child']) && $this->catalog->concept($concept) && empty($this->catalog->concept($concept)['broad']);
            $query   = array_filter([
                $linkGen ? "gender={$linkGen}" : null,
                $useConcept ? "concept={$concept}" : (count($subs) === 1 ? 'sub=' . reset($subs) : null),
            ]);
            $url  = '/category/' . $category['slug'] . ($query ? '?' . implode('&', $query) : '');
            $what = $label ?: ((LanguageDetector::isArabicScript($dialect) ? ($category['name_ar'] ?? null) : ($dialect === 'en' ? null : ($category['name_fr'] ?? null))) ?: $category['name']);
            $gen  = $linkGen ? ($this->lex->config("chips.more_gender.{$linkGen}.{$dialect}") ?? '') : '';
            $chips[] = $this->actions->link(
                strtr($this->lex->config("chips.more_in.{$dialect}") ?? $this->lex->config('chips.more_in.fr'), ['{what}' => $what, '{gender}' => $gen]),
                $url
            );
        } else {
            $chips[] = $this->chip('categories', $dialect);
        }

        return $chips;
    }

    private function chip(string $key, string $dialect, array $repl = []): ?array
    {
        $pair = $this->lex->config("chips.{$key}.{$dialect}") ?? $this->lex->config("chips.{$key}.fr");
        if (!is_array($pair)) {
            return null;
        }

        return $this->actions->quickReply(strtr($pair[0], $repl), strtr($pair[1], $repl));
    }

    private function hasSearchSignal(array $intent): bool
    {
        return $intent['keywords'] || $intent['category'] || $intent['min_price'] || $intent['max_price'] || $intent['refine']
            || $intent['concept'] || $intent['gender'] || $intent['colors'] || $intent['only_promo'];
    }

    /**
     * Step C: this message's slots merged with the conversation state.
     * A refinement ("rkhis akther", "bel abyedh", "lel rjel") keeps the previous slots and changes
     * only what the customer changed; a new product word starts a new search.
     */
    private function filtersFrom(array $intent, array $memory): array
    {
        $filters = [
            'keywords'    => $intent['keywords'],
            'keywords_en' => $intent['keywords_en'],
            'category'    => $intent['category'],
            'min_price'   => $intent['min_price'],
            'max_price'   => $intent['max_price'],
            'sort'        => $intent['sort'],
            'concept'     => $intent['concept'],
            'gender'      => $intent['gender'],
            'recipient'   => $intent['recipient'],
            'child'       => $intent['child'],
            'colors'      => $intent['colors'],
            'sizes'       => $intent['sizes'],
            'brand'       => $intent['brand'],
            'only_promo'  => $intent['only_promo'],
        ];

        $previous = $memory['filters'] ?? null;
        $carry    = $previous && ($intent['refine'] || $intent['followup']) && !$filters['concept'] && !$filters['category'];
        if ($carry) {
            $new = $filters;
            // The new value REPLACES the old one for every slot; earlier words are never concatenated into a text query
            $filters['keywords'] = $new['keywords'] ?: ($previous['keywords'] ?? []);
            foreach (['keywords_en', 'category', 'concept', 'brand'] as $k) {
                $filters[$k] = $new[$k] ?: ($previous[$k] ?? $new[$k]);
            }
            // A new gender replaces the old one AND the recipient that went with it (kids → men: no more "child")
            $filters['recipient'] = $new['gender'] ? $new['recipient'] : ($previous['recipient'] ?? $new['recipient']);
            foreach (['gender', 'child'] as $k) {
                $filters[$k] = $new['gender'] ? $new[$k] : ($previous[$k] ?? $new[$k]);
            }
            foreach (['colors', 'sizes'] as $k) {
                $filters[$k] = $new[$k] ?: ($previous[$k] ?? []);
            }
            $hasNewPrice = $new['min_price'] !== null || $new['max_price'] !== null;
            $filters['min_price'] = $hasNewPrice ? $new['min_price'] : ($previous['min_price'] ?? null);
            $filters['max_price'] = $hasNewPrice ? $new['max_price'] : ($previous['max_price'] ?? null);
            $filters['sort']       = $new['sort'] !== 'relevance' ? $new['sort'] : ($previous['sort'] ?? 'relevance');
            $filters['only_promo'] = $new['only_promo'] || !empty($previous['only_promo']);
        }

        return $filters;
    }

    /**
     * Adds the hard filters that come from the slots: real subcategory ids of the concept,
     * the concept's category, and whether the gender filter applies (gendered product types only).
     */
    private function resolveHard(array $f): array
    {
        $concept = $f['concept'] ?? null;
        $def     = $this->catalog->concept($concept);

        $f['sub_ids']          = [];
        $f['concept_text']     = [];
        $f['categories']       = [];
        $f['relax_categories'] = [];
        if ($def) {
            if (!empty($def['broad'])) {
                // A whole category ("dbach", "7wayej"): the category is the filter. Kids' clothes live in Kids & Baby too.
                $f['categories'] = [$def['category']];
                if (($f['gender'] ?? null) === 'kids') {
                    $f['categories']      = array_merge($f['categories'], $def['kids_categories'] ?? []);
                    $f['kids_categories'] = $def['kids_categories'] ?? [];
                    $f['kids_text']       = $def['kids_name_words'] ?? [];
                }
                $f['relax_categories'] = $f['categories'];
            } else {
                $f['sub_ids'] = $this->catalog->subIds($concept, !empty($f['child']));
                if (!$f['sub_ids']) {
                    $f['concept_text'] = array_slice($def['keywords'] ?? [], 0, 6);
                }
                if (empty($f['category']) && !empty($def['category'])) {
                    $f['category'] = $def['category'];
                }
                // The exact product type may be relaxed to the broad category it belongs to
                $f['relax_categories'] = $f['sub_ids'] ? $this->catalog->parentCategorySlugs($f['sub_ids']) : array_filter([$f['category'] ?? null]);
                // A gendered product type the catalog has no subcategory for (socks, pyjamas ...): relax to the clothes category
                if (!$f['relax_categories'] && !empty($def['gendered'])) {
                    $f['relax_categories'] = ['fashion-clothing'];
                }
            }
        }

        // A gender filter makes no sense for honey or phones; with no product word it applies to everything
        $gendered = $def ? !empty($def['gendered']) : true;
        $f['gender_strict'] = !empty($f['gender']) && in_array($f['gender'], ['women', 'men', 'kids'], true) && $gendered;

        return $f;
    }

    /**
     * Turns a follow-up into concrete filters. Returns product ids to exclude.
     */
    private function applyRefine(array &$filters, ?string $refine, array $shown): array
    {
        if (!$refine || !$shown) {
            return [];
        }

        $prices = array_column($shown, 'price');

        // Products already shown are never repeated in a follow-up.
        switch ($refine) {
            case 'cheaper':
                $filters['max_price'] = (float) min($prices);
                $filters['min_price'] = null;
                $filters['refine_price'] = true; // never relax a "cheaper" request to pricier items
                $filters['sort']      = 'price_desc'; // closest cheaper options first
                break;
            case 'pricier':
                $filters['min_price'] = (float) max($prices);
                $filters['max_price'] = null;
                $filters['refine_price'] = true;
                $filters['sort']      = 'price_asc';
                break;
        }

        return array_column($shown, 'id');
    }

    // ── Output ────────────────────────────────────────────────────────────

    private function persist(string $sessionId, string $message, string $reply, string $intent): void
    {
        try {
            $now = now();
            DB::table('chat_messages')->insert([
                ['session_id' => $sessionId, 'role' => 'user', 'content' => $message, 'intent' => null, 'created_at' => $now, 'updated_at' => $now],
                ['session_id' => $sessionId, 'role' => 'assistant', 'content' => $reply, 'intent' => $intent, 'created_at' => $now, 'updated_at' => $now],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AiChat] Could not save chat_messages: ' . $e->getMessage());
        }
    }

    private function respond(array $out): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'reply'    => $out['reply'],
            'language' => $out['language'],
            'dialect'  => $out['dialect'] ?? null,
            'intent'   => $out['intent'],
            'products' => $out['products'] ?? [],
            'steps'    => $out['steps'] ?? [],
            'actions'  => array_values(array_filter($out['actions'] ?? [])),
        ]);
    }
}
