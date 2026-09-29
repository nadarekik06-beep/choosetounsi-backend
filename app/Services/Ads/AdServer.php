<?php

namespace App\Services\Ads;

use App\Models\Sponsorship;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\PlanGate;
use App\Services\Recommendation\CandidatePools;
use App\Services\Recommendation\InterestProfileService;
use App\Services\Recommendation\ProductCardPresenter;
use App\Services\Recommendation\SimilarProductsFinder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Picks the ads for one slot on one page, for one viewer.
 *
 *   1. eligible   active campaign, budget left today, wallet ≥ floor CPC, product listed and in
 *                 stock, placement allowed (cached 60 s, flushed on campaign changes)
 *   2. filtered   not the viewer's own product, not already bought, not already on the page,
 *                 frequency cap, explicit seller targeting
 *   3. relevance  personal interest (InterestProfileService) blended with page context
 *                 (similar product, cart, search query, category) — per-placement weights;
 *                 below ads.min_relevance[placement] the ad is dropped whatever the bid
 *   4. rank       max_cpc × pCTR × relevance × quality; one ad per seller; top n
 *   5. price      generalised second price: just enough to keep the slot, floored at the
 *                 category minimum, then the seller's plan discount — sealed in the ad token
 *
 * Legacy prepaid rows (pricing_model=legacy_daily) compete with a bid of the floor CPC and are
 * never charged per click.
 */
class AdServer
{
    const ELIGIBLE_KEY = 'ads:eligible:v1';

    private ?array $pool = null;

    public function __construct(
        private AdSettings $settings,
        private AdPricing $pricing,
        private AdTokenService $tokens,
        private CandidatePools $pools,
        private InterestProfileService $profiles,
        private SimilarProductsFinder $similar,
        private ProductCardPresenter $presenter,
        private PlanGate $gate,
    ) {}

    /** Called after any campaign change so the next request sees it. */
    public static function flushEligible(): void
    {
        Cache::forget(self::ELIGIBLE_KEY);
    }

    /**
     * @return array{request_id: string, placement: string, ads: array<int, array>, explain?: array}
     *         ads: storefront product cards + is_sponsored, placement, ad_token, sponsor_data
     */
    public function serve(AdRequest $req): array
    {
        $requestId = (string) Str::uuid();
        $max       = min(max(0, $req->limit), (int) $this->settings->get("max_ads.{$req->placement}", 0));
        $result    = ['request_id' => $requestId, 'placement' => $req->placement, 'ads' => []];
        if ($max === 0 || !in_array($req->placement, Sponsorship::PLACEMENTS, true)) {
            return $result;
        }

        try {
            $ranked = $this->auction($req);
        } catch (\Throwable $e) {
            Log::warning('[AdServer] auction failed: ' . $e->getMessage(), ['placement' => $req->placement]);
            return $result;
        }

        $winners = array_slice($ranked, 0, $max);
        if (!$winners) {
            return $result + ($req->explain ? ['explain' => []] : []);
        }

        $cards = $this->presenter->cardsFor(array_column($winners, 'product_id'));
        foreach ($winners as $ad) {
            $card = $cards[$ad['product_id']] ?? null;
            if (!$card) {
                continue;
            }
            $payload = [
                'c' => $ad['id'], 'p' => $ad['product_id'], 'pl' => $req->placement,
                'u' => $req->userId, 's' => $req->sessionId, 'r' => $requestId, 'cpc' => $ad['charge'],
            ];
            $token = $req->forEmail ? $this->tokens->issueForEmail($payload) : $this->tokens->issue($payload);

            $result['ads'][] = array_merge($card, [
                'is_sponsored' => true,
                'placement'    => 'sponsored',
                'ad_placement' => $req->placement,
                'ad_token'     => $token,
                'sponsor_data' => ['id' => $ad['id'], 'ai_ad_copy' => $ad['ad_copy'], 'token' => $token],
            ]);
        }

        if ($req->explain) {
            $result['explain'] = array_map(fn ($a) => array_diff_key($a, ['ad_copy' => 1, 'row' => 1]), $ranked);
        }
        return $result;
    }

    /**
     * Every candidate that passed eligibility, filters and the relevance floor, ranked and priced.
     *
     * @return array<int, array{id: int, product_id: int, seller_id: int, relevance: float, pctr: float,
     *                          quality: float, bid: float, rank: float, charge: float, ad_copy: ?string}>
     */
    public function auction(AdRequest $req): array
    {
        $eligible = $this->eligible();
        if (!$eligible['campaigns']) {
            return [];
        }

        $viewer    = $req->userId ? User::find($req->userId) : null;
        $prefs     = $req->userId ? UserPreference::where('user_id', $req->userId)->first() : null;
        $purchased = array_flip($this->profiles->purchasedExclusions($req->userId));
        // Never advertise what's already on the page: listed items, the viewed product, the cart.
        $exclude   = array_flip(array_merge($req->excludeProductIds, $req->cartProductIds, array_filter([$req->contextProductId])));
        $actor     = $req->actorKey();
        $cap       = $this->settings->int('frequency_cap_per_day');
        $today     = AdClock::today();

        $candidates = [];
        foreach ($eligible['campaigns'] as $row) {
            if (($row->placements && !in_array($req->placement, $row->placements, true))
                || ($req->userId && $row->seller_id === $req->userId)
                || isset($purchased[$row->product_id]) || isset($exclude[$row->product_id])) {
                continue;
            }
            if ($actor && $cap > 0 && (int) Cache::get("ads:freq:{$actor}:{$row->id}:{$today}", 0) >= $cap) {
                continue;
            }
            if ($viewer && !$row->model->matchesUser($viewer, $prefs)) {
                continue;
            }
            $candidates[] = $row;
        }
        if (!$candidates) {
            return [];
        }

        $relevance = $this->relevance($req, $candidates);
        $floorRel  = $req->placement === 'entry_popup'
            ? $this->settings->float('popup_min_relevance')
            : (float) $this->settings->get("min_relevance.{$req->placement}", 0);

        $scored = [];
        foreach ($candidates as $row) {
            $rel = $relevance[$row->id] ?? 0.0;
            if ($rel < $floorRel || $rel <= 0) {
                continue;
            }
            $pctr    = $this->pctr($row, $req->placement, $eligible['stats']);
            $quality = $this->quality($row);
            $bid     = $row->pricing_model === Sponsorship::PRICING_CPC ? (float) $row->max_cpc : $this->pricing->floorCpc($row->category_id);
            $scored[] = [
                'id' => $row->id, 'product_id' => $row->product_id, 'seller_id' => $row->seller_id,
                'pricing_model' => $row->pricing_model, 'tier' => $row->tier, 'category_id' => $row->category_id,
                'relevance' => round($rel, 4), 'pctr' => round($pctr, 5), 'quality' => round($quality, 3),
                'bid' => $bid, 'rank' => $bid * $pctr * $rel * $quality, 'ad_copy' => $row->ad_copy,
            ];
        }

        usort($scored, fn ($a, $b) => $b['rank'] <=> $a['rank'] ?: $a['id'] <=> $b['id']);

        // One ad per seller per response.
        $ranked = $seen = [];
        foreach ($scored as $ad) {
            if (isset($seen[$ad['seller_id']])) {
                continue;
            }
            $seen[$ad['seller_id']] = true;
            $ranked[] = $ad;
        }

        foreach ($ranked as $i => &$ad) {
            $ad['charge'] = $this->price($ad, $ranked[$i + 1] ?? null);
            $ad['rank']   = round($ad['rank'], 8);
        }
        unset($ad);

        return $ranked;
    }

    /**
     * Generalised second price: the least this ad could have paid to stay above the next one,
     * + a small step, never above its bid, never below the category floor; then the plan discount.
     * Legacy prepaid campaigns are never charged per click.
     */
    public function price(array $ad, ?array $next): float
    {
        if ($ad['pricing_model'] !== Sponsorship::PRICING_CPC) {
            return 0.0;
        }
        $floor = $this->pricing->floorCpc($ad['category_id']);
        $own   = $ad['pctr'] * $ad['relevance'] * $ad['quality'];

        $price = $next && $own > 0
            ? min($ad['bid'], $next['rank'] / $own + $this->settings->float('gsp_increment'))
            : $floor;
        $price = max($floor, $price);

        return round($price * (1 - $this->pricing->tierDiscount($ad['tier'])), 3);
    }

    // ── Eligibility (cached, same for every viewer) ─────────────────────────

    /** @return array{campaigns: array<int, object>, stats: array} */
    public function eligible(): array
    {
        $data = Cache::remember(self::ELIGIBLE_KEY, (int) config('ads.eligible_cache_seconds', 60), fn () => $this->loadEligible());

        // Rebuild model instances for targeting checks (not cached as objects).
        foreach ($data['campaigns'] as $row) {
            $row->model = (new Sponsorship)->setRawAttributes((array) $row->raw, true);
        }
        return $data;
    }

    private function loadEligible(): array
    {
        $today = AdClock::today();
        $rows = DB::table('sponsorships as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.status', Sponsorship::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('s.start_at')->orWhere('s.start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('s.end_at')->orWhere('s.end_at', '>', now()))
            ->where('p.is_approved', true)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->where(fn ($s) => $s->where('p.stock', '>', 0)->whereNotExists(fn ($v) => $v->select(DB::raw(1))
                    ->from('product_variants as v')->whereColumn('v.product_id', 'p.id')->where('v.is_active', true)))
                ->orWhereExists(fn ($v) => $v->select(DB::raw(1))->from('product_variants as v')
                    ->whereColumn('v.product_id', 'p.id')->where('v.is_active', true)->where('v.stock', '>', 0)))
            ->select('s.id', 's.seller_id', 's.product_id', 's.pricing_model', 's.max_cpc', 's.daily_budget', 's.spent_today',
                's.spent_today_date', 's.total_budget', 's.spent_total', 's.placements', 's.readiness_score', 's.ai_ad_copy',
                's.target_gender', 's.target_wilaya_ids', 's.target_category_ids', 's.target_price_min', 's.target_price_max',
                'p.category_id', 'p.subcategory_id', 'p.price')
            ->get();

        $sellerIds = $rows->pluck('seller_id')->unique()->all();
        $wallets = DB::table('ad_wallets')->whereIn('seller_id', $sellerIds)->get()->keyBy('seller_id');
        $tiers = [];
        foreach ($sellerIds as $sid) {
            $tiers[$sid] = $this->gate->tierFor((int) $sid);
        }

        $campaigns = [];
        foreach ($rows as $r) {
            $floor = $this->pricing->floorCpc($r->category_id ? (int) $r->category_id : null);
            if ($r->pricing_model === Sponsorship::PRICING_CPC) {
                $spentToday = $r->spent_today_date === $today ? (float) $r->spent_today : 0.0;
                if ((float) $r->daily_budget - $spentToday + 0.0005 < $floor) {
                    continue;   // today's budget is spent
                }
                if ($r->total_budget !== null && (float) $r->total_budget - (float) $r->spent_total + 0.0005 < $floor) {
                    continue;
                }
                $w = $wallets[$r->seller_id] ?? null;
                $credit = $w && (!$w->credit_expires_at || strtotime($w->credit_expires_at . ' UTC') > time()) ? (float) $w->credit_balance : 0.0;
                if (!$w || (float) $w->balance + $credit + 0.0005 < $floor) {
                    continue;   // wallet can't pay for a click
                }
            }

            $campaigns[] = (object) [
                'id'             => (int) $r->id,
                'seller_id'      => (int) $r->seller_id,
                'product_id'     => (int) $r->product_id,
                'category_id'    => $r->category_id ? (int) $r->category_id : null,
                'subcategory_id' => $r->subcategory_id ? (int) $r->subcategory_id : null,
                'price'          => (float) $r->price,
                'pricing_model'  => $r->pricing_model,
                'max_cpc'        => (float) $r->max_cpc,
                'placements'     => $r->placements ? json_decode($r->placements, true) : null,
                'readiness'      => $r->readiness_score !== null ? (int) $r->readiness_score : null,
                'ad_copy'        => $r->ai_ad_copy,
                'tier'           => $tiers[$r->seller_id] ?? 'free',
                'raw'            => [
                    'target_gender' => $r->target_gender, 'target_wilaya_ids' => $r->target_wilaya_ids,
                    'target_category_ids' => $r->target_category_ids, 'target_price_min' => $r->target_price_min,
                    'target_price_max' => $r->target_price_max,
                ],
            ];
        }

        // Clicks / impressions per campaign and placement (for pCTR).
        $stats = [];
        if ($campaigns) {
            DB::table('sponsorship_daily_stats')->whereIn('sponsorship_id', array_column($campaigns, 'id'))
                ->groupBy('sponsorship_id', 'placement')
                ->get(['sponsorship_id', 'placement', DB::raw('SUM(impressions) AS i'), DB::raw('SUM(clicks) AS c')])
                ->each(function ($s) use (&$stats) {
                    $stats[(int) $s->sponsorship_id][$s->placement] = [(int) $s->i, (int) $s->c];
                });
        }

        return ['campaigns' => $campaigns, 'stats' => $stats];
    }

    // ── Scoring ─────────────────────────────────────────────────────────────

    /** Bayesian-smoothed CTR of this campaign in this placement: (clicks + k·prior) / (impressions + k). */
    private function pctr(object $row, string $placement, array $stats): float
    {
        [$i, $c] = $stats[$row->id][$placement] ?? [0, 0];
        $k     = max(1, $this->settings->int('pctr_prior_strength'));
        $prior = (float) $this->settings->get("pctr_prior.{$placement}", 0.02);
        return ($c + $k * $prior) / ($i + $k);
    }

    /** 0.5..1.2 from the readiness score at launch and the product's rating. */
    private function quality(object $row): float
    {
        $min = $this->settings->float('quality_min');
        $max = $this->settings->float('quality_max');
        $readiness = ($row->readiness ?? 70) / 100;
        $r = $this->pool()['rating'][$row->product_id] ?? null;
        $rating = $r ? max(0.0, min(1.0, ($r['bayes'] - 3) / 2)) : 0.5;

        return $min + ($max - $min) * (0.6 * $readiness + 0.4 * $rating);
    }

    /**
     * Relevance 0..1 per campaign: personal × w + context × (1 − w), w per placement.
     * Personal = interest-profile affinity; cold viewers get product popularity instead.
     *
     * @param object[] $candidates
     * @return array<int, float> campaign id => relevance
     */
    private function relevance(AdRequest $req, array $candidates): array
    {
        $w       = (float) $this->settings->get("relevance_personal_weight.{$req->placement}", 1.0);
        $pool    = $this->pool();
        $profile = $this->profiles->forActor($req->userId, $req->sessionId);
        $cold    = (bool) ($profile['is_cold'] ?? true);
        $context = $w < 1.0 ? $this->contextScores($req, $candidates) : [];

        $out = [];
        foreach ($candidates as $row) {
            if ($cold) {
                $personal = 0.6 * (float) ($pool['popularity'][$row->product_id] ?? 0);
            } else {
                $product  = $pool['products'][$row->product_id] ?? $row;
                $personal = (float) $this->profiles->scoreProduct($profile, $product, $pool['brands'][$row->product_id] ?? null)['score'];
            }
            $ctx = (float) ($context[$row->product_id] ?? 0);
            $out[$row->id] = max(0.0, min(1.0, $w * $personal + (1 - $w) * $ctx));
        }
        return $out;
    }

    /** @return array<int, float> product id => how well it fits the page (0..1) */
    private function contextScores(AdRequest $req, array $candidates): array
    {
        $ids = array_map(fn ($r) => $r->product_id, $candidates);

        switch ($req->placement) {
            case 'category_top':
                return $req->contextCategoryId
                    ? collect($candidates)->mapWithKeys(fn ($r) => [$r->product_id => $r->category_id === $req->contextCategoryId ? 1.0 : 0.0])->all()
                    : [];

            case 'search_top':
                return $req->query ? $this->searchScores($req->query, $ids) : [];

            case 'product_similar':
            case 'cart_cross_sell':
                $seeds = $req->placement === 'product_similar'
                    ? ($req->contextProductId ? [$req->contextProductId] : [])
                    : $req->cartProductIds;
                return $seeds ? $this->similarScores($seeds, $candidates) : [];

            default:
                return [];
        }
    }

    /** Similarity to the viewed product / cart items, with same subcategory/category as a floor. */
    private function similarScores(array $seeds, array $candidates): array
    {
        $scores = [];
        try {
            $scores = $this->similar->similarTo(array_fill_keys($seeds, 1.0), 300)['scores'] ?? [];
        } catch (\Throwable $e) {
            // best effort — structural match below still applies
        }

        $seedRows = DB::table('products')->whereIn('id', $seeds)->get(['category_id', 'subcategory_id']);
        $cats     = $seedRows->pluck('category_id')->filter()->all();
        $subs     = $seedRows->pluck('subcategory_id')->filter()->all();

        $out = [];
        foreach ($candidates as $row) {
            $structural = in_array($row->subcategory_id, $subs, true) && $row->subcategory_id ? 0.6
                : (in_array($row->category_id, $cats, true) ? 0.4 : 0.0);
            $out[$row->product_id] = max((float) ($scores[$row->product_id] ?? 0), $structural);
        }
        return $out;
    }

    /** Semantic search score of each candidate for the query (AI service), falling back to a name match. */
    private function searchScores(string $query, array $productIds): array
    {
        $query = mb_substr(trim($query), 0, 100);
        $ai = Cache::remember('ads:search:' . md5(mb_strtolower($query)), 300, function () use ($query) {
            try {
                $res = Http::ai()->timeout(2)->post(rtrim((string) config('services.ai.url'), '/') . '/search/text', ['query' => $query, 'limit' => 200]);
                if (!$res->successful()) {
                    return null;
                }
                $rows = collect($res->json('results', []));
                $max  = (float) ($rows->max('score') ?: 1);
                return $rows->mapWithKeys(fn ($r) => [(int) $r['product_id'] => round(max(0, (float) $r['score']) / $max, 4)])->all();
            } catch (\Throwable $e) {
                return null;
            }
        });

        $out = [];
        if (is_array($ai)) {
            foreach ($productIds as $id) {
                $out[$id] = (float) ($ai[$id] ?? 0);
            }
        }

        // Plain name/description match counts too (and covers products added after the last index rebuild).
        $terms = array_filter(preg_split('/\s+/u', mb_strtolower($query)), fn ($t) => mb_strlen($t) >= 3);
        if ($terms) {
            $matches = DB::table('products')->whereIn('id', $productIds)
                ->where(function ($q) use ($terms) {
                    foreach ($terms as $t) {
                        $q->orWhere('name', 'like', '%' . addcslashes($t, '%_') . '%');
                    }
                })->pluck('id');
            foreach ($matches as $id) {
                $out[(int) $id] = max($out[(int) $id] ?? 0, 0.7);
            }
        }
        return $out;
    }

    private function pool(): array
    {
        return $this->pool ??= $this->pools->get();
    }
}
