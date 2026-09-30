<?php

namespace App\Http\Controllers\Api\Seller\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\StoreAdCampaignRequest;
use App\Http\Requests\Ads\UpdateAdCampaignRequest;
use App\Http\Resources\Ads\AdCampaignResource;
use App\Models\Sponsorship;
use App\Services\Ads\AdClock;
use App\Services\Ads\SponsorshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Seller campaigns (CPC). Business rules live in SponsorshipService; this only
 * scopes to the seller's own campaigns and shapes responses.
 *
 *   GET    /api/seller/ads/overview                ?days=30 — totals + daily series for the ads home
 *   GET    /api/seller/ads/campaigns               ?status=active|paused|…
 *   POST   /api/seller/ads/campaigns
 *   GET    /api/seller/ads/campaigns/{id}          + summary, daily (30 days), placement_stats
 *   PATCH  /api/seller/ads/campaigns/{id}          budget, max CPC, end date, placements, targeting
 *   POST   /api/seller/ads/campaigns/{id}/pause|resume|cancel
 */
class AdCampaignController extends Controller
{
    public function __construct(private SponsorshipService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $page = Sponsorship::forSeller($request->user()->id)
            ->with(['product:id,name,slug,price', 'product.primaryImage'])
            ->when($request->query('status'), fn ($q, $status) => $q->whereIn('status', explode(',', $status)))
            ->orderByRaw("FIELD(status, 'active', 'paused', 'draft') = 0")   // open ones first
            ->orderByDesc('created_at')
            ->paginate(min(50, (int) $request->query('per_page', 20)));

        return response()->json([
            'success' => true,
            'data'    => AdCampaignResource::collection($page->getCollection()),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $days  = min(90, max(1, (int) $request->query('days', 30)));
        $since = AdClock::now()->subDays($days - 1)->toDateString();
        $base  = DB::table('sponsorship_daily_stats as d')
            ->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')
            ->where('s.seller_id', $request->user()->id)->where('d.date', '>=', $since);

        $daily = (clone $base)->groupBy('d.date')->orderBy('d.date')
            ->selectRaw('d.date, SUM(d.impressions) AS impressions, SUM(d.clicks) AS clicks, SUM(d.cost) AS cost, SUM(d.orders) AS orders, SUM(d.revenue) AS revenue')
            ->get()->map(fn ($r) => [
                'date' => (string) $r->date, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks,
                'cost' => round((float) $r->cost, 3), 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 3),
            ]);

        $spend   = round((float) $daily->sum('cost'), 3);
        $revenue = round((float) $daily->sum('revenue'), 3);
        $clicks  = (int) $daily->sum('clicks');
        $imp     = (int) $daily->sum('impressions');
        $orders  = (int) $daily->sum('orders');

        return response()->json(['success' => true, 'data' => [
            'days'   => $days,
            'totals' => [
                'spend' => $spend, 'impressions' => $imp, 'clicks' => $clicks,
                'ctr' => $imp > 0 ? round($clicks / $imp, 4) : null,
                'orders' => $orders, 'revenue' => $revenue,
                'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
                'cost_per_order' => $orders > 0 ? round($spend / $orders, 3) : null,
            ],
            'daily'  => $daily,
            'open_campaigns' => Sponsorship::forSeller($request->user()->id)->open()->count(),
        ]]);
    }

    public function store(StoreAdCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaigns->create($request->user(), $request->validated());

        return $this->campaignResponse($campaign, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $campaign = $this->find($request, $id);
        $since    = AdClock::now()->subDays(29)->toDateString();

        $daily = DB::table('sponsorship_daily_stats')->where('sponsorship_id', $campaign->id)->where('date', '>=', $since)
            ->groupBy('date')->orderBy('date')
            ->selectRaw('date, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(cost) AS cost, SUM(orders) AS orders, SUM(revenue) AS revenue')
            ->get()->map(fn ($r) => [
                'date' => (string) $r->date, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks,
                'cost' => round((float) $r->cost, 3), 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 3),
            ]);

        $placements = DB::table('sponsorship_daily_stats')->where('sponsorship_id', $campaign->id)
            ->groupBy('placement')
            ->selectRaw('placement, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(cost) AS cost, SUM(orders) AS orders, SUM(revenue) AS revenue')
            ->get()->map(fn ($r) => [
                'placement' => $r->placement, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks,
                'ctr' => $r->impressions > 0 ? round($r->clicks / $r->impressions, 4) : null,
                'cost' => round((float) $r->cost, 3), 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 3),
            ]);

        return response()->json([
            'success' => true,
            'data'    => (new AdCampaignResource($campaign))->resolve() + [
                'summary'    => $this->campaigns->summary($campaign),
                'daily'           => $daily,
                'placement_stats' => $placements,
            ],
        ]);
    }

    public function update(UpdateAdCampaignRequest $request, int $id): JsonResponse
    {
        $campaign = $this->campaigns->update($this->find($request, $id), $request->validated());
        return $this->campaignResponse($campaign);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        return $this->campaignResponse($this->campaigns->pause($this->find($request, $id), Sponsorship::PAUSE_MANUAL));
    }

    public function resume(Request $request, int $id): JsonResponse
    {
        return $this->campaignResponse($this->campaigns->resume($this->find($request, $id)));
    }

    /** Nothing is reserved per campaign (clicks are charged as they happen), so the refund is always 0. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $campaign = $this->campaigns->cancel($this->find($request, $id));
        return response()->json([
            'success' => true,
            'data'    => (new AdCampaignResource($campaign->load(['product', 'product.primaryImage'])))->resolve() + ['refunded' => 0.0],
        ]);
    }

    private function find(Request $request, int $id): Sponsorship
    {
        $campaign = Sponsorship::forSeller($request->user()->id)->with(['product', 'product.primaryImage'])->find($id);
        if (!$campaign) {
            throw AdRuleViolation::make('not_found', [], [], 404);
        }
        return $campaign;
    }

    private function campaignResponse(Sponsorship $campaign, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new AdCampaignResource($campaign->fresh(['product', 'product.primaryImage'])),
        ], $status);
    }
}
