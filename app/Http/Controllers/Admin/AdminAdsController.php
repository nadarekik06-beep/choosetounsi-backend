<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ads\AdCampaignResource;
use App\Http\Resources\Ads\AdTopUpResource;
use App\Http\Resources\Ads\AdWalletResource;
use App\Http\Resources\Ads\AdWalletTransactionResource;
use App\Models\AdTopUp;
use App\Models\AdWallet;
use App\Models\AdWalletTransaction;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdMetrics;
use App\Services\Ads\AdSettings;
use App\Services\Ads\SponsorshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin sponsoring section.
 *
 *   GET  /api/admin/ads/overview?days=30         revenue (paid vs credit), spend per day, CTR by placement,
 *                                                active campaigns, top advertisers, fraud flags
 *   GET  /api/admin/ads/campaigns                ?status=&pricing_model=&search=&seller_id=
 *   GET  /api/admin/ads/campaigns/{id}           + summary, daily, placement_stats, seller wallet
 *   POST /api/admin/ads/campaigns/{id}/reject    {reason} → ends it, refunds its charges, tells the seller
 *   POST /api/admin/ads/campaigns/{id}/pause     paused_reason=admin (seller can't resume)
 *   POST /api/admin/ads/campaigns/{id}/resume
 *   GET  /api/admin/ads/settings                 {values, defaults}
 *   PUT  /api/admin/ads/settings                 {key: value, …} (known keys only)
 *   GET  /api/admin/ads/wallets                  ?search=
 *   GET  /api/admin/ads/wallets/{seller}         wallet, ledger, top-ups
 *   (wallet adjust & top-up confirm/reject: AdminAdWalletController)
 */
class AdminAdsController extends Controller
{
    public function __construct(private SponsorshipService $campaigns, private AdSettings $settings) {}

    // ── Overview ────────────────────────────────────────────────────────────

    public function overview(Request $request, AdMetrics $metrics): JsonResponse
    {
        $days = min(365, max(1, (int) $request->query('days', 30)));
        $to   = AdClock::today();
        $from = AdClock::now()->subDays($days - 1)->toDateString();

        $totals = $metrics->summary(null, $from, $to);
        $byPlacement = array_map(fn ($r) => [
            'placement' => $r['placement'], 'impressions' => $r['impressions'], 'clicks' => $r['clicks'],
            'ctr' => $r['ctr'], 'orders' => $r['orders'], 'revenue' => $r['revenue'],
        ], $metrics->byPlacement(null, $from, $to));

        $topSellers = DB::table('ad_wallet_transactions as t')->join('users as u', 'u.id', '=', 't.seller_id')
            ->where('t.type', AdWalletTransaction::TYPE_CLICK_CHARGE)->whereBetween('t.rollup_date', [$from, $to])
            ->groupBy('t.seller_id', 'u.name', 'u.email')
            ->orderByRaw('SUM(t.amount) ASC')->limit(10)
            ->get(['t.seller_id', 'u.name', 'u.email', DB::raw('-SUM(t.amount) AS spend'), DB::raw('-SUM(t.amount - t.credit_amount) AS paid')])
            ->map(fn ($r) => ['seller_id' => (int) $r->seller_id, 'name' => $r->name, 'email' => $r->email,
                'spend' => round((float) $r->spend, 3), 'paid' => round((float) $r->paid, 3)]);

        return response()->json(['success' => true, 'data' => [
            'days'      => $days,
            'revenue'   => $metrics->platformRevenue($from, $to),
            'all_time'  => $metrics->platformRevenue(null, null),
            'daily'     => $metrics->platformRevenueDaily($from, $to),
            'totals'    => [
                'impressions' => $totals['impressions'],
                'clicks'      => $totals['clicks'],
                'orders'      => $totals['orders'],
                'sales'       => $totals['revenue'],
            ],
            'by_placement'    => $byPlacement,
            'campaigns'       => Sponsorship::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'top_advertisers' => $topSellers,
            'flags'           => $this->fraudFlags($metrics),
            'pending_top_ups' => AdTopUp::where('status', AdTopUp::STATUS_PENDING)->count(),
        ]]);
    }

    /**
     * Things worth a look: IPs / sessions producing many unbilled (duplicate/bot/burst) clicks,
     * and campaigns whose CTR is far above the placement's usual rate.
     */
    private function fraudFlags(AdMetrics $metrics): array
    {
        $since = now()->subDays(7);

        $ips = DB::table('sponsorship_events')
            ->where('event', 'click')->where('created_at', '>=', $since)->whereNotNull('ip_hash')
            ->groupBy('ip_hash')->havingRaw('SUM(countable = 0) >= 10')
            ->orderByRaw('SUM(countable = 0) DESC')->limit(10)
            ->get([DB::raw('LEFT(ip_hash, 12) AS ip'), DB::raw('COUNT(*) AS clicks'), DB::raw('SUM(countable = 0) AS rejected'),
                   DB::raw('COUNT(DISTINCT sponsorship_id) AS campaigns')]);

        $priors = (array) $this->settings->get('pctr_prior', []);
        $sellers = DB::table('sponsorships as s')->join('users as u', 'u.id', '=', 's.seller_id')->pluck('u.name', 's.id');
        $ctr = collect($metrics->byPlacement(null, AdClock::now()->subDays(6)->toDateString(), AdClock::today(), perCampaign: true))
            ->filter(fn ($r) => $r['clicks'] >= 30 && $r['impressions'] > 0
                && $r['clicks'] / $r['impressions'] > 3 * (float) ($priors[$r['placement']] ?? 0.02))
            ->map(fn ($r) => ['campaign_id' => $r['campaign_id'], 'seller' => $sellers[$r['campaign_id']] ?? null, 'placement' => $r['placement'],
                'ctr' => $r['ctr'], 'expected' => (float) ($priors[$r['placement']] ?? 0.02)])
            ->values();

        return ['suspicious_ips' => $ips, 'high_ctr' => $ctr];
    }

    // ── Campaigns ───────────────────────────────────────────────────────────

    public function campaigns(Request $request): JsonResponse
    {
        $page = Sponsorship::with(['product:id,name,slug,price', 'product.primaryImage', 'seller:id,name,email'])
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('pricing_model'), fn ($q, $m) => $q->where('pricing_model', $m))
            ->when($request->query('seller_id'), fn ($q, $id) => $q->where('seller_id', $id))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($w) => $w
                ->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                ->orWhereHas('seller', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->orderByDesc('id')
            ->paginate(min(100, (int) $request->query('per_page', 25)));
        $metrics = app(AdMetrics::class)->perCampaign($page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(fn (Sponsorship $c) => $c->metrics = $metrics[$c->id]);

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($c) => (new AdCampaignResource($c))->resolve() + [
                'seller' => $c->seller ? ['id' => $c->seller->id, 'name' => $c->seller->name, 'email' => $c->seller->email] : null,
            ]),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function campaign(int $id): JsonResponse
    {
        $c = Sponsorship::with(['product', 'product.primaryImage', 'seller:id,name,email'])->findOrFail($id);

        $metrics = app(AdMetrics::class);
        $daily = $metrics->daily([$c->id], AdClock::now()->subDays(29)->toDateString(), AdClock::today());
        $placements = $metrics->byPlacement([$c->id]);
        $wallet = AdWallet::where('seller_id', $c->seller_id)->first();

        return response()->json(['success' => true, 'data' => (new AdCampaignResource($c))->resolve() + [
            'seller'          => $c->seller ? ['id' => $c->seller->id, 'name' => $c->seller->name, 'email' => $c->seller->email] : null,
            'summary'         => $this->campaigns->summary($c),
            'daily'           => $daily,
            'placement_stats' => $placements,
            'wallet'          => $wallet ? (new AdWalletResource($wallet))->resolve() : null,
            'ad_tags'         => $c->ai_tags,
        ]]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $c = $this->campaigns->reject(Sponsorship::findOrFail($id), $data['reason'], $request->user());
        return $this->campaign($c->id);
    }

    public function pause(int $id): JsonResponse
    {
        $this->campaigns->pause(Sponsorship::findOrFail($id), Sponsorship::PAUSE_ADMIN);
        return $this->campaign($id);
    }

    public function resume(int $id): JsonResponse
    {
        $this->campaigns->resume(Sponsorship::findOrFail($id), byAdmin: true);
        return $this->campaign($id);
    }

    // ── Settings ────────────────────────────────────────────────────────────

    public function settings(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'values'   => $this->settings->all(),
            'defaults' => $this->settings->defaults(),
            'placements' => Sponsorship::PLACEMENTS,
        ]]);
    }

    /** Values must keep the shape of their default (number, bool, text, list or map of numbers). */
    public function updateSettings(Request $request): JsonResponse
    {
        $defaults = $this->settings->defaults();
        $input    = $request->except(['_method']);
        $clean    = $errors = [];

        foreach ($input as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                $errors[$key] = 'Unknown setting.';
                continue;
            }
            $default = $defaults[$key];
            if (is_bool($default)) {
                $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($default) || is_float($default)) {
                if (!is_numeric($value) || $value < 0) {
                    $errors[$key] = 'Must be a number ≥ 0.';
                    continue;
                }
                $clean[$key] = is_int($default) ? (int) $value : round((float) $value, 3);
            } elseif (is_array($default)) {
                if (!is_array($value)) {
                    $errors[$key] = 'Must be a list or map.';
                    continue;
                }
                foreach ($value as $v) {
                    if (!is_numeric($v) || $v < 0) {
                        $errors[$key] = 'Every entry must be a number ≥ 0.';
                        continue 2;
                    }
                }
                $clean[$key] = array_map(fn ($v) => $v + 0, $value);
            } elseif ($key === 'digest_day') {
                if (!in_array($value, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)) {
                    $errors[$key] = 'Must be a weekday name.';
                    continue;
                }
                $clean[$key] = $value;
            } elseif ($key === 'digest_time') {
                if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    $errors[$key] = 'Must be HH:MM.';
                    continue;
                }
                $clean[$key] = $value;
            } else {
                $clean[$key] = (string) $value;
            }
        }

        foreach (['tier_click_discount'] as $key) {
            foreach ((array) ($clean[$key] ?? []) as $tier => $v) {
                if ($v > 1) {
                    $errors[$key] = 'Discounts are fractions between 0 and 1.';
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $this->settings->set($clean, $request->user()->id);
        \App\Services\Ads\AdServer::flushEligible();
        return $this->settings();
    }

    // ── Wallets ─────────────────────────────────────────────────────────────

    public function wallets(Request $request): JsonResponse
    {
        $page = User::query()->where('role', 'seller')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->leftJoin('ad_wallets as w', 'w.seller_id', '=', 'users.id')
            ->orderByRaw('w.id IS NULL')->orderByDesc('w.balance')
            ->select('users.id', 'users.name', 'users.email', 'w.balance', 'w.credit_balance', 'w.credit_expires_at')
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($r) => [
                'seller' => ['id' => $r->id, 'name' => $r->name, 'email' => $r->email],
                'balance' => round((float) $r->balance, 3), 'credit_balance' => round((float) $r->credit_balance, 3),
                'credit_expires_at' => $r->credit_expires_at,
            ]),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function wallet(int $seller): JsonResponse
    {
        $user = User::where('role', 'seller')->findOrFail($seller);
        $wallet = AdWallet::firstOrNew(['seller_id' => $user->id]);

        return response()->json(['success' => true, 'data' => [
            'seller'       => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'wallet'       => (new AdWalletResource($wallet))->resolve(),
            'transactions' => AdWalletTransactionResource::collection(
                AdWalletTransaction::where('seller_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')->limit(100)->get()
            ),
            'top_ups'      => AdTopUpResource::collection(AdTopUp::where('seller_id', $user->id)->orderByDesc('id')->limit(50)->get()),
        ]]);
    }
}
