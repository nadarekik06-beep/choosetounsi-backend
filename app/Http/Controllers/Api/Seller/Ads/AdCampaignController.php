<?php

namespace App\Http\Controllers\Api\Seller\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ads\StoreAdCampaignRequest;
use App\Http\Requests\Ads\UpdateAdCampaignRequest;
use App\Http\Resources\Ads\AdCampaignResource;
use App\Models\Sponsorship;
use App\Services\Ads\AdClock;
use App\Services\Ads\AdMetrics;
use App\Services\Ads\SponsorshipService;
use App\Services\GrowthRadar\GrowthActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller campaigns (CPC). Business rules live in SponsorshipService; this only
 * scopes to the seller's own campaigns and shapes responses.
 *
 *   GET    /api/seller/ads/overview                ?days=30 — totals + daily series (every day) for the ads home
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
        $this->preloadMetrics($page->getCollection());

        return response()->json([
            'success' => true,
            'data'    => AdCampaignResource::collection($page->getCollection()),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function overview(Request $request, AdMetrics $metrics): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $to   = AdClock::today();
        $from = AdClock::now()->subDays($days - 1)->toDateString();
        $ids  = Sponsorship::forSeller($request->user()->id)->pluck('id')->all();

        return response()->json(['success' => true, 'data' => [
            'days'           => $days,
            'totals'         => $metrics->summary($ids, $from, $to),
            'daily'          => $metrics->daily($ids, $from, $to),
            'open_campaigns' => Sponsorship::forSeller($request->user()->id)->open()->count(),
        ]]);
    }

    public function store(StoreAdCampaignRequest $request, GrowthActions $actions): JsonResponse
    {
        $campaign = $this->campaigns->create($request->user(), $request->validated());

        // Opened from a Growth Radar card: measure the first week (or the whole run if shorter)
        if ($card = $actions->cardFromRequest($request)) {
            $start = $campaign->start_at ?? now();
            $end = $campaign->end_at && $campaign->end_at < $start->copy()->addDays(7) ? $campaign->end_at : $start->copy()->addDays(7);
            $actions->record($request->user()->id, $card, 'boost', $campaign->id, $campaign->product_id, $start, $end);
        }
        // Opened from Analyse des visiteurs: remember it for the before / after funnel
        if ($campaign->product_id) {
            app(\App\Services\VisitorInsights\InsightActions::class)->recordFromRequest(
                $request, 'boost', $campaign->id, [(int) $campaign->product_id], $campaign->start_at ?? now(), $campaign->end_at ?? now());
        }

        return $this->campaignResponse($campaign, 201);
    }

    public function show(Request $request, int $id, AdMetrics $metrics): JsonResponse
    {
        $campaign = $this->find($request, $id);

        return response()->json([
            'success' => true,
            'data'    => (new AdCampaignResource($campaign))->resolve() + [
                'summary'         => $this->campaigns->summary($campaign),
                'daily'           => $metrics->daily([$campaign->id], AdClock::now()->subDays(29)->toDateString(), AdClock::today()),
                'placement_stats' => $metrics->byPlacement([$campaign->id]),
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

    private function preloadMetrics($campaigns): void
    {
        $all = app(AdMetrics::class)->perCampaign($campaigns->pluck('id')->all());
        $campaigns->each(fn (Sponsorship $c) => $c->metrics = $all[$c->id]);
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
