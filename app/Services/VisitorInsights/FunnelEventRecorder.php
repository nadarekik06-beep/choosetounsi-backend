<?php

namespace App\Services\VisitorInsights;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Impressions and checkout starts (product_funnel_events). Kept out of
 * user_interactions: they are the high-volume steps and personalization does
 * not learn from them.
 *
 *  - impression: once per session, product and listing context (the storefront
 *    also dedupes for the whole browser session; this is the server-side guard).
 *  - checkout_start: once per session, product and checkout attempt (`ref`, a
 *    UUID the checkout page keeps until the order is placed) — DB unique key.
 */
class FunnelEventRecorder
{
    /**
     * @param array<int, array{type: string, product_id: int, source_section?: ?string, ref?: ?string}> $events
     * @return int recorded rows
     */
    public function record(array $events, ?int $userId, ?string $sessionId, ?string $device): int
    {
        if (!$sessionId || !$events) return 0;

        try {
            $ids = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $events)));
            $sellers = DB::table('products')->whereIn('id', $ids)->pluck('seller_id', 'id');

            $rows = [];
            foreach ($events as $e) {
                $pid = (int) $e['product_id'];
                $sellerId = $sellers[$pid] ?? null;
                if (!$sellerId) continue;

                $source = TrafficSource::fromSection($e['source_section'] ?? null);
                if ($e['type'] === 'impression') {
                    $ctx = substr((string) ($e['source_section'] ?? ''), 0, 40);
                    $key = "funnel:imp:$sessionId:$pid:$ctx";
                    if (!Cache::add($key, 1, (int) config('funnel.impression_dedupe_seconds'))) continue;
                    $ref = null;
                } else {
                    $ref = $this->uuid($e['ref'] ?? null);
                    if (!$ref) continue;
                    $source = $this->lastViewSource($pid, $userId, $sessionId);
                }

                $rows[] = [
                    'product_id'     => $pid,
                    'seller_id'      => (int) $sellerId,
                    'event'          => $e['type'],
                    'traffic_source' => $source,
                    'device'         => $device,
                    'user_id'        => $userId,
                    'session_id'     => $sessionId,
                    'ref'            => $ref,
                    'excluded'       => FunnelExclusions::isExcluded($userId, $sessionId, (int) $sellerId),
                    'created_at'     => now(),
                ];
            }
            if (!$rows) return 0;
            // insertOrIgnore: a repeated checkout start for the same attempt is dropped by uq_pfe_checkout
            return DB::table('product_funnel_events')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            Log::warning('[FunnelEventRecorder] ' . $e->getMessage());
            return 0;
        }
    }

    /** A checkout start inherits the source of the buyer's latest view of the product. */
    private function lastViewSource(int $productId, ?int $userId, string $sessionId): string
    {
        return DB::table('user_interactions')
            ->where('product_id', $productId)->where('event_type', 'view')
            ->where('created_at', '>=', now()->subDays((int) config('funnel.order_attribution_days')))
            ->where(fn ($q) => $q->where('session_id', $sessionId)->when($userId, fn ($q) => $q->orWhere('user_id', $userId)))
            ->orderByDesc('id')->value('traffic_source') ?: 'direct';
    }

    private function uuid(?string $v): ?string
    {
        $v = strtolower(trim((string) $v));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) ? $v : null;
    }
}
