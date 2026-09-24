<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Chat\ActionFactory;
use App\Services\Chat\IntentExtractor;
use App\Services\Chat\KnowledgeBase;
use App\Services\Chat\OrderLookup;
use App\Services\Chat\ProductRetriever;
use App\Services\Chat\ReplyComposer;
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
 *   1. IntentExtractor   message → intent (+ search filters). Rules first, Groq JSON only when needed.
 *   2a. Product search   ProductRetriever (MySQL + optional FastAPI) → ReplyComposer (grounded Groq or template)
 *   2b. How-to intents   KnowledgeBase (config/chatbot_kb.php + live PlatformFacts) → steps + buttons
 *   2c. track_order      OrderLookup — only the Sanctum token owner's orders
 *
 * Response (always HTTP 200 with a reply, even when Groq / FastAPI are down):
 *   { success, reply, language, intent, products: [...], steps: [{title, description}],
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
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => 'required|string|max:500',
            'session_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ]);

        $started   = microtime(true);
        $sessionId = $request->input('session_id');
        $message   = trim($request->input('message'));

        // Optional auth: the route is public, a valid Sanctum token identifies the user.
        /** @var User|null $user */
        $user   = Auth::guard('sanctum')->user();
        $userId = $user?->id;

        $memory   = $this->memory->get($sessionId, $userId);
        $language = $this->extractor->detectLanguage($message)
            ?? $memory['language']
            ?? config('services.groq.default_language', 'fr');

        $limitKey = 'chat:session:' . $sessionId;
        if (RateLimiter::tooManyAttempts($limitKey, self::SESSION_MAX_PER_MINUTE)) {
            return $this->respond(['reply' => $this->composer->tooFast($language), 'language' => $language, 'intent' => 'rate_limited']);
        }
        RateLimiter::hit($limitKey, 60);

        try {
            // Identical question in the same conversation state → no Groq call.
            // Never holds personal data: track_order answers are not cached.
            $cacheKey = 'chat:resp:' . md5(implode('|', [
                $this->extractor->normalize($message),
                json_encode($memory['filters']),
                json_encode(array_column($memory['shown'], 'id')),
            ]));

            if ($cached = Cache::get($cacheKey)) {
                $this->memory->remember($sessionId, $userId, $message, $cached['reply'], $cached['language'], $cached['filters'], $cached['shown']);
                Log::info('[AiChat] Response cache hit', ['session' => $sessionId]);
                return $this->respond($cached);
            }

            $intent   = $this->extractor->extract($message, $memory);
            $language = $intent['language'];

            $out = match ($intent['intent']) {
                'greeting'          => $this->greeting($language),
                'browse_categories' => $this->categories($language),
                'track_order'       => $this->trackOrder($intent, $message, $language, $user),
                'become_vendor', 'place_order', 'returns_complaints', 'account_help'
                                    => $this->knowledge($intent, $message, $language),
                'other'             => $this->hasSearchSignal($intent)
                                        ? $this->search($message, $intent, $memory)
                                        : $this->other($language),
                default             => $this->search($message, $intent, $memory),
            };

            $out += ['products' => [], 'steps' => [], 'actions' => [], 'filters' => null, 'personal' => false];
            $out['language'] = $out['language'] ?? $language;
            $out['intent']   = $out['intent'] ?? $intent['intent'];
            $out['actions']  = $this->actions->finalize($out['actions'], $out['intent'] === 'browse_categories' ? 6 : 4);

            $shown       = array_map(fn ($p) => ['id' => $p['id'], 'price' => $p['price']], $out['products']);
            $storedReply = $out['personal'] ? self::ORDERS_PLACEHOLDER : $out['reply'];

            $this->memory->remember($sessionId, $userId, $message, $storedReply, $out['language'], $out['filters'], $shown);
            $this->persist($sessionId, $message, $storedReply, $out['intent']);

            if (!$out['personal']) {
                Cache::put($cacheKey, [
                    'reply'    => $out['reply'],
                    'products' => $out['products'],
                    'steps'    => $out['steps'],
                    'actions'  => $out['actions'],
                    'language' => $out['language'],
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
                'language' => $out['language'],
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
                'reply'    => $this->composer->error($language),
                'language' => $language,
                'intent'   => 'error',
                'actions'  => $this->kb->starterActions($language),
            ]);
        }
    }

    // ── Intents ───────────────────────────────────────────────────────────

    private function greeting(string $lang): array
    {
        return ['reply' => $this->composer->greeting($lang), 'actions' => $this->kb->starterActions($lang)];
    }

    private function other(string $lang): array
    {
        return ['reply' => $this->composer->other($lang), 'actions' => $this->kb->starterActions($lang)];
    }

    private function knowledge(array $intent, string $message, string $lang): array
    {
        $kb = $this->kb->answer($intent['intent'], $intent['section'] ?? null, $lang);

        return [
            'reply'   => $this->composer->knowledge($lang, $message, $kb),
            'steps'   => $kb['steps'],
            'actions' => $kb['actions'],
        ];
    }

    private function trackOrder(array $intent, string $message, string $lang, ?User $user): array
    {
        // "What does pending mean?" → status glossary from the KB (not personal).
        if (($intent['section'] ?? null) === 'statuses') {
            return $this->knowledge($intent, $message, $lang);
        }

        if (!$user) {
            return [
                'reply'   => $this->composer->loginForOrders($lang),
                'actions' => [
                    $this->actions->link(['en' => 'Log in', 'fr' => 'Se connecter', 'ar' => 'ادخل'][$lang], '/auth/login?redirect=/orders'),
                    $this->kb->quickReply('order_statuses', $lang),
                    $this->kb->quickReply('complaint_how', $lang),
                ],
                // Depends on who is asking → never served from the shared response cache.
                'personal' => true,
            ];
        }

        $orders = $this->orders->recentFor($user, $message, $lang);

        if (!$orders) {
            return [
                'reply'    => $this->composer->noOrders($lang),
                'actions'  => [$this->kb->quickReply('find_product', $lang), $this->kb->quickReply('order_how', $lang)],
                'personal' => true,
            ];
        }

        return [
            'reply'    => $this->composer->ordersIntro($lang, count($orders)),
            'steps'    => array_map(fn ($o) => ['title' => $o['title'], 'description' => $o['description']], $orders),
            'actions'  => [
                $this->actions->link(['en' => 'My orders', 'fr' => 'Mes commandes', 'ar' => 'طلباتي'][$lang], '/orders'),
                $this->kb->quickReply('order_statuses', $lang),
                $this->kb->quickReply('complaint_how', $lang),
            ],
            'personal' => true,
        ];
    }

    private function categories(string $lang): array
    {
        $categories = Cache::remember('chat:categories', 3600, fn () => DB::table('categories')
            ->where('is_active', true)->orderBy('order')->get(['name', 'name_ar', 'slug'])->all());

        $actions = [];
        foreach ($categories as $c) {
            $label     = $lang === 'ar' && $c->name_ar ? $c->name_ar : $c->name;
            $actions[] = $this->actions->link($label, '/category/' . $c->slug);
        }

        return ['reply' => $this->composer->categoriesIntro($lang), 'actions' => $actions];
    }

    // ── Product search ────────────────────────────────────────────────────

    private function search(string $message, array $intent, array $memory): array
    {
        $shown   = $memory['shown'] ?? [];
        $filters = $this->filtersFrom($intent, $memory);
        $exclude = $this->applyRefine($filters, $intent['refine'] ?? null, $shown);

        $result  = $this->retriever->search($filters, $exclude);
        $nearest = null;

        if (!$result['products']) {
            $nearest = $this->retriever->nearest($filters);

            // The rules-only fast path matched nothing at any price: its keywords
            // were probably wrong (darija, typo). Let Groq read the message once.
            if ($nearest['count'] === 0 && $intent['source'] === 'rules') {
                $llmIntent = $this->extractor->extract($message, $memory, true);
                if ($llmIntent['source'] === 'llm' && in_array($llmIntent['intent'], ['search', 'product_question', 'other'], true)) {
                    $intent  = $llmIntent;
                    $filters = $this->filtersFrom($intent, $memory);
                    $exclude = $this->applyRefine($filters, $intent['refine'] ?? null, $shown);
                    $result  = $this->retriever->search($filters, $exclude);
                    $nearest = $result['products'] ? null : $this->retriever->nearest($filters);
                }
            }
        }

        $lang = $intent['language'];

        if (!$result['products']) {
            $refine = $shown ? ($intent['refine'] ?? null) : null;
            $label  = $lang === 'ar' ? ($nearest['category_ar'] ?? $nearest['category']) : $nearest['category'];

            return [
                'reply'    => $this->composer->noResults($lang, $filters, $nearest, $refine),
                'language' => $lang,
                'intent'   => 'search',
                'filters'  => $filters,
                'actions'  => [
                    $nearest['category_slug'] ? $this->actions->link((string) $label, '/category/' . $nearest['category_slug']) : null,
                    $this->kb->quickReply('other_categories', $lang),
                    $this->actions->link(['en' => 'Browse the shop', 'fr' => 'Voir la boutique', 'ar' => 'تصفّح المتجر'][$lang], '/shop'),
                ],
            ];
        }

        $products = $result['products'];
        $category = $products[0]['category'] ?? null;
        $catLabel = $category ? ($lang === 'ar' && $category['name_ar'] ? $category['name_ar'] : $category['name']) : null;

        return [
            'reply'    => $this->composer->products($lang, $message, $products, $filters, $memory['turns'] ?? []),
            'language' => $lang,
            'intent'   => $intent['intent'] === 'product_question' ? 'product_question' : 'search',
            'products' => $products,
            'filters'  => $filters,
            'actions'  => [
                $this->kb->quickReply('show_cheaper', $lang),
                $category ? $this->actions->link(
                    ['en' => "More in {$catLabel}", 'fr' => "Plus dans {$catLabel}", 'ar' => "المزيد في {$catLabel}"][$lang],
                    '/category/' . $category['slug']
                ) : $this->kb->quickReply('other_categories', $lang),
                $this->kb->quickReply('order_payment', $lang),
            ],
        ];
    }

    private function hasSearchSignal(array $intent): bool
    {
        return $intent['keywords'] || $intent['category'] || $intent['min_price'] || $intent['max_price'] || $intent['refine'];
    }

    private function filtersFrom(array $intent, array $memory): array
    {
        $filters = [
            'keywords'    => $intent['keywords'],
            'keywords_en' => $intent['keywords_en'],
            'category'    => $intent['category'],
            'min_price'   => $intent['min_price'],
            'max_price'   => $intent['max_price'],
            'sort'        => $intent['sort'],
        ];

        // "cheaper ones" / "others" with no product words → reuse the last search.
        $previous = $memory['filters'] ?? null;
        if ($previous && $intent['refine'] && !$filters['keywords'] && !$filters['category']) {
            $filters['keywords']    = $previous['keywords'] ?? [];
            $filters['keywords_en'] = $previous['keywords_en'] ?? '';
            $filters['category']    = $previous['category'] ?? null;
        }

        return $filters;
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
                $filters['sort']      = 'price_desc'; // closest cheaper options first
                break;
            case 'pricier':
                $filters['min_price'] = (float) max($prices);
                $filters['max_price'] = null;
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
            'intent'   => $out['intent'],
            'products' => $out['products'] ?? [],
            'steps'    => $out['steps'] ?? [],
            'actions'  => array_values(array_filter($out['actions'] ?? [])),
        ]);
    }
}
