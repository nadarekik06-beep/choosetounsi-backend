<?php

namespace App\Services\Ads;

use App\Models\Order;
use App\Models\OrderAdAttribution;
use App\Models\SponsorshipEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orders credited to ads — only when the buyer actually clicked the ad.
 *
 * At checkout, each order line whose product the buyer (account or browser session)
 * clicked as an ad gets one attribution row to that campaign: the last valid
 * (countable) click on that product within ads.attribution_days before the order.
 * Idempotent (unique order_item_id).
 *
 * Nothing else happens here: whether the order still counts (not cancelled or
 * refunded) is read live from the order by AdMetrics, so no status hook is needed.
 */
class AttributionService
{
    public function __construct(private AdSettings $settings) {}

    /** Call once the order and its items exist. $sessionId = the buyer's storefront guest id. */
    public function recordOrder(Order $order, ?string $sessionId = null): int
    {
        try {
            $items = DB::table('order_items')->where('order_id', $order->id)->whereNotNull('product_id')
                ->get(['id', 'product_id', 'net_total', 'total', 'price', 'quantity']);
            if ($items->isEmpty() || (!$order->user_id && !$sessionId)) {
                return 0;
            }

            $orderedAt = $order->created_at ?? now();
            $since     = $orderedAt->copy()->subDays(max(1, $this->settings->int('attribution_days')));
            $created   = 0;

            foreach ($items as $item) {
                $click = SponsorshipEvent::query()
                    ->join('sponsorships as s', 's.id', '=', 'sponsorship_events.sponsorship_id')
                    ->where('s.product_id', $item->product_id)
                    ->where('sponsorship_events.event', SponsorshipEvent::CLICK)
                    ->where('sponsorship_events.countable', true)
                    ->whereBetween('sponsorship_events.created_at', [$since, $orderedAt])
                    ->where(function ($q) use ($order, $sessionId) {
                        if ($order->user_id) {
                            $q->orWhere('sponsorship_events.user_id', $order->user_id);
                        }
                        if ($sessionId) {
                            $q->orWhere('sponsorship_events.session_id', $sessionId);
                        }
                    })
                    ->orderByDesc('sponsorship_events.id')
                    ->first(['sponsorship_events.id', 'sponsorship_events.sponsorship_id']);

                if (!$click) {
                    continue;
                }

                $revenue = (float) ($item->net_total ?? $item->total ?? ((float) $item->price * (int) $item->quantity));
                $created += DB::table('order_ad_attributions')->insertOrIgnore([
                    'order_id'       => $order->id,
                    'order_item_id'  => $item->id,
                    'sponsorship_id' => $click->sponsorship_id,
                    'click_event_id' => $click->id,
                    'revenue'        => round($revenue, 3),
                    'status'         => OrderAdAttribution::STATUS_PENDING,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
            return $created;
        } catch (\Throwable $e) {
            // Attribution must never break checkout.
            Log::warning('[AttributionService] recordOrder #' . $order->id . ': ' . $e->getMessage());
            return 0;
        }
    }
}
