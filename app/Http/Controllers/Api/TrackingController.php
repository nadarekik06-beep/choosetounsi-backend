<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\InteractionTracker;
use App\Services\VisitorInsights\FunnelEventRecorder;
use App\Services\VisitorInsights\TrafficSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function __construct(private InteractionTracker $tracker, private FunnelEventRecorder $funnel) {}

    /**
     * POST /api/track   (public — guests identified by X-Session-Id)
     *
     * Body: { "events": [ { "type": "view"|"click"|"impression"|"checkout_start", "product_id": 12,
     *                       "source_section": "trending", "ref": "<checkout attempt uuid>" } ] }
     *
     * view / click feed personalization and the funnel (user_interactions);
     * impression / checkout_start only the funnel (product_funnel_events).
     * A view's source_section is the section of the click that led to it, or
     * "external" / "direct". Bots are dropped. Always answers 202 so a tracking
     * hiccup can never surface in the UI.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events'                  => 'required|array|min:1|max:50',
            'events.*.type'           => 'required|string|in:' . implode(',', config('recommendations.client_events')),
            'events.*.product_id'     => 'required|integer|min:1',
            'events.*.source_section' => 'nullable|string|max:40|regex:/^[a-z0-9_:-]+$/',
            'events.*.ref'            => 'nullable|string|max:36',
        ]);

        $client = TrafficSource::fromRequest($request);
        if ($client['bot']) {
            return response()->json(['success' => true, 'recorded' => 0], 202);
        }

        [$userId, $sessionId] = InteractionTracker::actorFromRequest($request);

        $recorded = 0;
        $funnelOnly = [];
        foreach ($data['events'] as $event) {
            if (in_array($event['type'], ['impression', 'checkout_start'], true)) {
                $funnelOnly[] = $event;
                continue;
            }
            $recorded += (int) $this->tracker->record(
                $event['type'], $userId, $sessionId, (int) $event['product_id'],
                ['source_section' => $event['source_section'] ?? null, 'device' => $client['device']]
            );
        }
        $recorded += $this->funnel->record($funnelOnly, $userId, $sessionId, $client['device']);

        return response()->json(['success' => true, 'recorded' => $recorded], 202);
    }

    /**
     * POST /api/track/merge   (auth:sanctum)
     *
     * Called by the storefront right after login/register: moves this
     * browser's guest history onto the account.
     */
    public function merge(Request $request): JsonResponse
    {
        $sessionId = InteractionTracker::sessionIdFrom($request);
        $moved = $sessionId ? $this->tracker->mergeGuestHistory($request->user()->id, $sessionId) : 0;

        return response()->json(['success' => true, 'merged' => $moved]);
    }
}
