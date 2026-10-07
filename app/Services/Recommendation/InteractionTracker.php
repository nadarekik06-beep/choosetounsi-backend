<?php

namespace App\Services\Recommendation;

use App\Models\Product;
use App\Models\UserInteraction;
use App\Services\VisitorInsights\FunnelExclusions;
use App\Services\VisitorInsights\TrafficSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for recording recommendation signals.
 *
 * Tracking must never break the request that triggers it, so every public
 * method swallows its own errors. Repeats inside the configured window
 * (e.g. one view per product per 30 min) are dropped via an atomic Cache::add.
 */
class InteractionTracker
{
    /** Header the storefront sends with its guest id (a v4 UUID kept in localStorage). */
    const SESSION_HEADER = 'X-Session-Id';

    /**
     * Resolve who is acting. Public routes don't run auth:sanctum, so the
     * default guard can't see Bearer tokens — ask the sanctum guard directly.
     *
     * @return array{0: ?int, 1: ?string}  [userId, sessionId]
     */
    public static function actorFromRequest(Request $request): array
    {
        $user = $request->user() ?? $request->user('sanctum');
        return [$user?->id, self::sessionIdFrom($request)];
    }

    public static function sessionIdFrom(Request $request): ?string
    {
        return self::normalizeSessionId($request->header(self::SESSION_HEADER));
    }

    /** Only storefront guest UUIDs are accepted (not Laravel's 40-char session ids). */
    public static function normalizeSessionId(?string $sid): ?string
    {
        $sid = strtolower(trim((string) $sid));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sid) ? $sid : null;
    }

    /**
     * @param Product|int|null $product
     * @param array $extra  source_section, search_query, order_id, seller_id, category_id,
     *                      traffic_source, device (Visitor Insights funnel)
     */
    public function record(string $event, ?int $userId, ?string $sessionId, $product = null, array $extra = []): bool
    {
        try {
            $sessionId = self::normalizeSessionId($sessionId);
            if (!in_array($event, UserInteraction::EVENTS, true) || (!$userId && !$sessionId)) {
                return false;
            }

            $productId = $product instanceof Product ? $product->id : ($product ? (int) $product : null);
            $sellerId   = $extra['seller_id']   ?? null;
            $categoryId = $extra['category_id'] ?? null;

            if ($productId && (!$sellerId || !$categoryId)) {
                $p = $product instanceof Product
                    ? $product
                    : Product::withTrashed()->select('id', 'seller_id', 'category_id')->find($productId);
                if (!$p) {
                    return false;
                }
                $sellerId   ??= $p->seller_id;
                $categoryId ??= $p->category_id;
            }

            if (!$this->claimDedupeSlot($event, $userId, $sessionId, $productId, $extra)) {
                return false;
            }

            FunnelExclusions::rememberSessionOwner($userId, $sessionId);

            UserInteraction::create([
                'user_id'        => $userId,
                // Keep the guest id on logged-in events too: it lets a later merge
                // stitch pre-login and post-login activity from the same browser.
                'session_id'     => $sessionId,
                'product_id'     => $productId,
                'seller_id'      => $sellerId,
                'category_id'    => $categoryId,
                'event_type'     => $event,
                'source_section' => isset($extra['source_section']) ? mb_substr((string) $extra['source_section'], 0, 40) : null,
                'search_query'   => isset($extra['search_query']) ? mb_substr(trim((string) $extra['search_query']), 0, 191) : null,
                'order_id'       => $extra['order_id'] ?? null,
                'traffic_source' => $productId ? $this->trafficSource($event, $userId, $sessionId, $productId, $extra) : null,
                'device'         => $extra['device'] ?? null,
                'funnel_excluded'=> $productId && FunnelExclusions::isExcluded($userId, $sessionId, $sellerId),
            ]);

            $this->markDirty($userId, $sessionId);
            return true;
        } catch (\Throwable $e) {
            Log::warning('[InteractionTracker] record failed: ' . $e->getMessage(), compact('event', 'userId', 'sessionId'));
            return false;
        }
    }

    public function recordFromRequest(Request $request, string $event, $product = null, array $extra = []): bool
    {
        $client = TrafficSource::fromRequest($request);
        if ($client['bot']) {
            return false;
        }
        [$userId, $sessionId] = self::actorFromRequest($request);
        return $this->record($event, $userId, $sessionId, $product, $extra + ['device' => $client['device']]);
    }

    /**
     * Funnel source of an event. Clicks and views carry their section (a view sends the
     * section of the click that led to it, or "external" / "direct"); a cart or wishlist
     * add inherits the source of the actor's latest view of the product.
     */
    private function trafficSource(string $event, ?int $userId, ?string $sessionId, int $productId, array $extra): ?string
    {
        if (!empty($extra['traffic_source'])) {
            return in_array($extra['traffic_source'], TrafficSource::all(), true) ? $extra['traffic_source'] : null;
        }
        if (in_array($event, ['view', 'click'], true)) {
            return TrafficSource::fromSection($extra['source_section'] ?? null);
        }
        if (!in_array($event, ['cart_add', 'favorite_add', 'purchase'], true)) {
            return null;
        }
        $last = DB::table('user_interactions')
            ->where('product_id', $productId)->where('event_type', 'view')
            ->where('created_at', '>=', now()->subDays((int) config('funnel.order_attribution_days')))
            ->where(fn ($q) => $userId
                ? $q->where('user_id', $userId)->when($sessionId, fn ($q) => $q->orWhere('session_id', $sessionId))
                : $q->where('session_id', $sessionId))
            ->orderByDesc('id')->value('traffic_source');
        return $last ?: 'direct';
    }

    /**
     * Attach a browser's guest history to the account that just logged in.
     * Returns the number of rows moved.
     */
    public function mergeGuestHistory(int $userId, string $sessionId): int
    {
        try {
            $moved = DB::table('user_interactions')
                ->where('session_id', $sessionId)
                ->whereNull('user_id')
                ->update(['user_id' => $userId]);

            if ($moved > 0) {
                $this->markDirty($userId, $sessionId);
                // The guest profile's signals now live in the user's profile.
                app(InterestProfileService::class)->forget(null, $sessionId);
            }
            return $moved;
        } catch (\Throwable $e) {
            Log::warning('[InteractionTracker] merge failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** InterestProfileService recomputes an actor's profile once it sees this marker. */
    public function markDirty(?int $userId, ?string $sessionId): void
    {
        if ($userId) {
            Cache::put(self::dirtyKey($userId, null), now()->timestamp, now()->addDay());
        }
        if ($sessionId) {
            Cache::put(self::dirtyKey(null, $sessionId), now()->timestamp, now()->addDay());
        }
    }

    public static function dirtyKey(?int $userId, ?string $sessionId): string
    {
        return $userId ? "reco:dirty:u:{$userId}" : "reco:dirty:s:{$sessionId}";
    }

    private function claimDedupeSlot(string $event, ?int $userId, ?string $sessionId, ?int $productId, array $extra): bool
    {
        $window = (int) (config('recommendations.dedupe_seconds')[$event] ?? 0);
        if ($window <= 0) {
            return true;
        }

        $actor  = $userId ? "u{$userId}" : "s{$sessionId}";
        $target = $productId ?: md5(mb_strtolower((string) ($extra['search_query'] ?? '')));
        // Clicks from different sections are distinct signals; views are not.
        $section = $event === 'click' ? ':' . ($extra['source_section'] ?? '') : '';

        return Cache::add("reco:dd:{$event}:{$actor}:{$target}{$section}", 1, $window);
    }
}
