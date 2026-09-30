<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Models\SponsorshipEvent;
use App\Services\Ads\AdEventService;
use App\Services\Ads\AdRequest;
use App\Services\Ads\AdServer;
use App\Services\Ads\AdSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Buyer-side ads (public; a Bearer token and X-Session-Id identify the viewer).
 *
 *   GET  /api/ads?placement=search_top&q=…&context_product_id=…&category_slug=…&cart=1&exclude[]=…&limit=
 *   POST /api/ads/events     {events: [{token, event: impression|click}]}   (max 20)
 *   GET  /api/ads/popup      0 or 1 entry-popup ad, within the per-viewer caps
 */
class AdsController extends Controller
{
    public function __construct(private AdServer $server, private AdSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'placement'           => ['required', Rule::in(array_diff(Sponsorship::PLACEMENTS, ['entry_popup', 'email_digest']))],
            'limit'               => ['nullable', 'integer', 'min:1', 'max:20'],
            'context_product_id'  => ['nullable', 'integer'],
            'context_category_id' => ['nullable', 'integer'],
            'category_slug'       => ['nullable', 'string', 'max:191'],
            'q'                   => ['nullable', 'string', 'max:200'],
            'cart'                => ['nullable', 'boolean'],
            'cart_product_ids'    => ['nullable', 'array', 'max:50'],
            'cart_product_ids.*'  => ['integer'],
            'exclude'             => ['nullable', 'array', 'max:200'],
            'exclude.*'           => ['integer'],
        ]);

        $categoryId = $data['context_category_id'] ?? null;
        if (!$categoryId && !empty($data['category_slug'])) {
            $categoryId = DB::table('categories')->where('slug', $data['category_slug'])->value('id');
        }

        $req = AdRequest::fromHttp($request, $data['placement'], [
            'limit'             => (int) ($data['limit'] ?? $this->settings->get("max_ads.{$data['placement']}", 1)),
            'contextProductId'  => $data['context_product_id'] ?? null,
            'contextCategoryId' => $categoryId ? (int) $categoryId : null,
            'query'             => $data['q'] ?? null,
            'excludeProductIds' => array_map('intval', $data['exclude'] ?? []),
        ]);
        $req->cartProductIds = $this->cartProductIds($request, $req, $data);

        return response()->json(['success' => true] + $this->server->serve($req));
    }

    public function events(Request $request, AdEventService $events): JsonResponse
    {
        $data = $request->validate([
            'events'         => ['required', 'array', 'min:1', 'max:20'],
            'events.*.token' => ['required', 'string', 'max:2048'],
            'events.*.event' => ['required', Rule::in([SponsorshipEvent::IMPRESSION, SponsorshipEvent::CLICK])],
        ]);

        $viewer = AdRequest::fromHttp($request, 'home_row');
        $ctx = [
            'user_id'    => $viewer->userId,
            'session_id' => $viewer->sessionId,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        $results = [];
        foreach ($data['events'] as $e) {
            $results[] = $events->record($e['token'], $e['event'], $ctx);
        }

        // Always 202: tracking must never surface in the UI.
        return response()->json(['success' => true, 'results' => $results], 202);
    }

    public function popup(Request $request): JsonResponse
    {
        $req = AdRequest::fromHttp($request, 'entry_popup', ['limit' => 1]);
        $actor = $req->actorKey();

        // Needs a known viewer (the relevance rule is personal) and at most one popup a day.
        if (!$this->settings->get('popup_enabled') || !$actor || Cache::has("ads:popup:{$actor}")) {
            return response()->json(['success' => true, 'ad' => null]);
        }

        $served = $this->server->serve($req);
        return response()->json(['success' => true, 'ad' => $served['ads'][0] ?? null, 'request_id' => $served['request_id']]);
    }

    /**
     * GET /api/ads/r/{token} — ad links in e-mails: count the click (billed like any
     * other, with the same dedupe/bot rules), then send the shopper to the product.
     */
    public function redirect(Request $request, string $token, AdEventService $events)
    {
        $payload = app(\App\Services\Ads\AdTokenService::class)->verify($token);
        $front   = rtrim((string) config('app.frontend_url'), '/');
        if (!$payload) {
            return redirect()->away($front);
        }

        $viewer = AdRequest::fromHttp($request, 'email_digest');
        $events->record($token, SponsorshipEvent::CLICK, [
            'user_id'    => $viewer->userId,
            'session_id' => $viewer->sessionId,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $slug = DB::table('products')->where('id', (int) ($payload['p'] ?? 0))->value('slug');
        return redirect()->away($slug ? "{$front}/products/{$slug}?utm_source=email&utm_medium=ad" : $front);
    }

    /** Cart items: explicit ids (guest cart lives in the browser) or the signed-in buyer's cart. */
    private function cartProductIds(Request $request, AdRequest $req, array $data): array
    {
        $ids = array_map('intval', $data['cart_product_ids'] ?? []);
        if (!empty($data['cart']) && $req->userId) {
            $ids = array_merge($ids, DB::table('carts')->where('user_id', $req->userId)->pluck('product_id')->map(fn ($i) => (int) $i)->all());
        }
        return array_values(array_unique(array_filter($ids)));
    }
}
