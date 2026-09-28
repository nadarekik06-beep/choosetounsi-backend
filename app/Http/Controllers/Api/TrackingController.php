<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\InteractionTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function __construct(private InteractionTracker $tracker) {}

    /**
     * POST /api/track   (public — guests identified by X-Session-Id)
     *
     * Body: { "events": [ { "type": "view"|"click", "product_id": 12, "source_section": "trending" } ] }
     *
     * Always answers 202 so a tracking hiccup can never surface in the UI.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events'                  => 'required|array|min:1|max:20',
            'events.*.type'           => 'required|string|in:' . implode(',', config('recommendations.client_events')),
            'events.*.product_id'     => 'required|integer|min:1',
            'events.*.source_section' => 'nullable|string|max:40|regex:/^[a-z0-9_:-]+$/',
        ]);

        [$userId, $sessionId] = InteractionTracker::actorFromRequest($request);

        $recorded = 0;
        foreach ($data['events'] as $event) {
            $recorded += (int) $this->tracker->record(
                $event['type'], $userId, $sessionId, (int) $event['product_id'],
                ['source_section' => $event['source_section'] ?? null]
            );
        }

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
