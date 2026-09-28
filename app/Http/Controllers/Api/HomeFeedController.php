<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\HomeFeedBuilder;
use App\Services\Recommendation\InteractionTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomeFeedController extends Controller
{
    /** Short response cache; a new signal from the viewer changes the key immediately. */
    const CACHE_SECONDS = 120;

    public function __construct(private HomeFeedBuilder $builder) {}

    /**
     * GET /api/home/feed   (public — Bearer token optional, guests send X-Session-Id)
     *
     * { success, personalized, profile_state: warm|cold, generated_at,
     *   sections: [ { key, type, meta, products: [card…] } ] }
     */
    public function index(Request $request): JsonResponse
    {
        [$userId, $sessionId] = InteractionTracker::actorFromRequest($request);
        $sessionForProfile = $userId ? null : $sessionId;

        $actor  = $userId ? "u{$userId}" : ($sessionId ? "s{$sessionId}" : 'guest');
        $dirty  = (int) Cache::get(InteractionTracker::dirtyKey($userId, $sessionForProfile), 0);
        $bucket = intdiv(time(), HomeFeedBuilder::ROTATION_SECONDS);
        $key    = "reco:feed:v1:{$actor}:{$bucket}:{$dirty}:" . app()->getLocale();

        $feed = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->builder->build($userId, $sessionForProfile) + [
            'generated_at' => now()->toIso8601String(),
        ]);

        return response()->json(['success' => true] + $feed);
    }
}
