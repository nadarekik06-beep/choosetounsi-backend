<?php

namespace App\Services\Ads;

use App\Models\Order;
use App\Models\OrderAdAttribution;
use App\Models\Sponsorship;
use App\Models\SponsorshipEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orders credited to ads — only when the buyer actually clicked the ad.
 *
 *   order created   → each line whose product the buyer clicked (last click, within
 *                     ads.attribution_days, billable — or any click for legacy prepaid
 *                     campaigns) gets a pending attribution
 *   delivered / completed → converted once: one conversion event per campaign and order,
 *                     campaign counters and daily stats updated
 *   cancelled / refunded  → reversed: counters and stats taken back
 *
 * Every step is idempotent (unique order_item_id and (campaign, order, event)).
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
                    ->where(fn ($q) => $q->where('sponsorship_events.billable', true)->orWhere('s.pricing_model', Sponsorship::PRICING_LEGACY))
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

    /** React to an order status change (OrderObserver). */
    public function onStatusChange(Order $order, string $status): void
    {
        try {
            if (in_array($status, ['delivered', 'completed'], true)) {
                $this->convert($order);
            } elseif (in_array($status, ['cancelled', 'refunded'], true)) {
                $this->reverse($order);
            }
        } catch (\Throwable $e) {
            Log::warning('[AttributionService] status ' . $status . ' #' . $order->id . ': ' . $e->getMessage());
        }
    }

    public function convert(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $pending = OrderAdAttribution::where('order_id', $order->id)
                ->where('status', OrderAdAttribution::STATUS_PENDING)->lockForUpdate()->get();

            foreach ($pending->groupBy('sponsorship_id') as $campaignId => $rows) {
                $revenue = round((float) $rows->sum('revenue'), 3);
                $click   = SponsorshipEvent::find($rows->first()->click_event_id);

                $inserted = DB::table('sponsorship_events')->insertOrIgnore([
                    'sponsorship_id' => $campaignId,
                    'event'          => SponsorshipEvent::CONVERSION,
                    'placement'      => $click->placement ?? 'unknown',
                    'user_id'        => $order->user_id,
                    'session_id'     => $click->session_id ?? null,
                    'request_id'     => $click->request_id ?? '00000000-0000-0000-0000-000000000000',
                    'billable'       => false,
                    'order_id'       => $order->id,
                    'revenue'        => $revenue,
                    'click_event_id' => $click->id ?? null,
                    'created_at'     => now(),
                ]);

                if ($inserted) {
                    Sponsorship::whereKey($campaignId)->update([
                        'conversions'        => DB::raw('conversions + 1'),
                        'attributed_orders'  => DB::raw('attributed_orders + 1'),
                        'attributed_revenue' => DB::raw('attributed_revenue + ' . $revenue),
                    ]);
                    AdStats::add((int) $campaignId, $click->placement ?? 'unknown', ['orders' => 1, 'revenue' => $revenue]);
                }
            }

            OrderAdAttribution::whereIn('id', $pending->pluck('id'))
                ->update(['status' => OrderAdAttribution::STATUS_CONVERTED, 'converted_at' => now()]);
        });
    }

    public function reverse(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $rows = OrderAdAttribution::where('order_id', $order->id)
                ->whereIn('status', [OrderAdAttribution::STATUS_PENDING, OrderAdAttribution::STATUS_CONVERTED])
                ->lockForUpdate()->get();

            foreach ($rows->where('status', OrderAdAttribution::STATUS_CONVERTED)->groupBy('sponsorship_id') as $campaignId => $group) {
                $conversion = SponsorshipEvent::where('sponsorship_id', $campaignId)->where('order_id', $order->id)
                    ->where('event', SponsorshipEvent::CONVERSION)->first();
                if (!$conversion) {
                    continue;
                }

                $inserted = DB::table('sponsorship_events')->insertOrIgnore([
                    'sponsorship_id' => $campaignId,
                    'event'          => SponsorshipEvent::CONVERSION_REVERSED,
                    'placement'      => $conversion->placement,
                    'user_id'        => $order->user_id,
                    'request_id'     => $conversion->request_id,
                    'billable'       => false,
                    'order_id'       => $order->id,
                    'revenue'        => $conversion->revenue,
                    'click_event_id' => $conversion->click_event_id,
                    'created_at'     => now(),
                ]);

                if ($inserted) {
                    $revenue = (float) $conversion->revenue;
                    Sponsorship::whereKey($campaignId)->update([
                        'conversions'        => DB::raw('GREATEST(CAST(conversions AS SIGNED) - 1, 0)'),
                        'attributed_orders'  => DB::raw('GREATEST(CAST(attributed_orders AS SIGNED) - 1, 0)'),
                        'attributed_revenue' => DB::raw('GREATEST(attributed_revenue - ' . $revenue . ', 0)'),
                    ]);
                    // Taken back from the day the conversion was counted.
                    AdStats::add((int) $campaignId, $conversion->placement, ['orders' => -1, 'revenue' => -$revenue],
                        AdClock::dateOf($conversion->created_at));
                }
            }

            OrderAdAttribution::whereIn('id', $rows->pluck('id'))
                ->update(['status' => OrderAdAttribution::STATUS_REVERSED, 'reversed_at' => now()]);
        });
    }
}
