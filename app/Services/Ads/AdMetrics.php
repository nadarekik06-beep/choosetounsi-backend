<?php

namespace App\Services\Ads;

use App\Models\AdWalletTransaction as Tx;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of every ad number. The seller dashboard, the admin sponsoring
 * pages, finance ad revenue, the optimizer, campaign e-mails and the nightly roll-up
 * all read through this class, always live from the raw tables:
 *
 *   impression  a sponsorship_events 'impression' row. Written at most once per viewer,
 *               campaign and placement per ads.impression_dedupe_minutes; bots never.
 *   click       a sponsorship_events 'click' row with countable = 1: a real buyer's
 *               click — not a bot, an IP burst, the seller themselves, or a repeat by
 *               the same viewer within ads.click_dedupe_hours. Counted even when it
 *               wasn't charged (budget spent, legacy prepaid campaign…).
 *   spend       what the seller paid for clicks: the wallet ledger's click charges
 *               minus refunds for the campaign. Split into free plan credit and paid
 *               money. A charge's day is its roll-up day, a refund's the day it was made.
 *   order       a distinct order with a line credited to the campaign (last valid click
 *               on that product by the same buyer within ads.attribution_days, see
 *               AttributionService). Counted when placed, on the order's day; it drops
 *               out as soon as the order, or that seller's part of it, is cancelled or
 *               refunded. One order is one order, however many lines or campaigns.
 *   sales       the net total of those credited lines (the promoted product only).
 *   ROAS        sales / spend (null without spend). Cost per order: spend / orders.
 *
 * Days are Africa/Tunis days (AdClock); $from / $to are inclusive Y-m-d dates.
 */
class AdMetrics
{
    /** Order / seller-order states that no longer count as a sale. */
    public const DEAD_STATUSES = ['cancelled', 'refunded'];

    /** Totals for these campaigns (null = every campaign). */
    public function summary(?array $campaignIds, ?string $from = null, ?string $to = null): array
    {
        return $this->collect($campaignIds, $from, $to, [])[''] ?? $this->finish([]);
    }

    /** @return array<int, array> totals keyed by campaign id (every id present) */
    public function perCampaign(array $campaignIds, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->collect($campaignIds, $from, $to, ['campaign']);
        $out = [];
        foreach ($campaignIds as $id) {
            $out[(int) $id] = $rows[(string) $id] ?? $this->finish([]);
        }
        return $out;
    }

    /** One row per day from $from to $to, days without activity included (zeros). */
    public function daily(?array $campaignIds, string $from, string $to): array
    {
        $rows = $this->collect($campaignIds, $from, $to, ['date']);
        $out = [];
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $date = $d->toDateString();
            $out[] = ['date' => $date] + $this->withCost($rows[$date] ?? $this->finish([]));
        }
        return $out;
    }

    /** One row per placement (and campaign, when asked) that had any activity. */
    public function byPlacement(?array $campaignIds, ?string $from = null, ?string $to = null, bool $perCampaign = false): array
    {
        $dims = $perCampaign ? ['campaign', 'placement'] : ['placement'];
        $out = [];
        foreach ($this->collect($campaignIds, $from, $to, $dims) as $key => $m) {
            $parts = explode('|', (string) $key);
            $row = $perCampaign ? ['campaign_id' => (int) $parts[0], 'placement' => $parts[1]] : ['placement' => $parts[0]];
            $out[] = $row + $this->withCost($m);
        }
        usort($out, fn ($a, $b) => [$a['campaign_id'] ?? 0, $a['placement']] <=> [$b['campaign_id'] ?? 0, $b['placement']]);
        return $out;
    }

    /** Every (campaign, day, placement) cell — what the nightly roll-up stores. */
    public function cells(?array $campaignIds, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        foreach ($this->collect($campaignIds, $from, $to, ['campaign', 'date', 'placement']) as $key => $m) {
            [$campaign, $date, $placement] = explode('|', (string) $key);
            $out[] = ['campaign_id' => (int) $campaign, 'date' => $date, 'placement' => $placement] + $m;
        }
        return $out;
    }

    /**
     * Platform ad revenue: the paid part of all click spend (plan credit isn't money,
     * it is reported apart).
     *
     * @return array{paid: float, credit: float, total: float}
     */
    public function platformRevenue(?string $from, ?string $to): array
    {
        $s = $this->summary(null, $from, $to);
        return ['paid' => $s['paid_spend'], 'credit' => $s['credit_spend'], 'total' => $s['spend']];
    }

    /** @return array<int, array{date: string, paid: float, credit: float}> days with spend only */
    public function platformRevenueDaily(string $from, string $to): array
    {
        $out = [];
        foreach ($this->collect(null, $from, $to, ['date']) as $date => $m) {
            if ($m['spend'] != 0.0) {
                $out[] = ['date' => (string) $date, 'paid' => $m['paid_spend'], 'credit' => $m['credit_spend']];
            }
        }
        usort($out, fn ($a, $b) => $a['date'] <=> $b['date']);
        return $out;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Raw sums grouped by $dims ⊂ {campaign, date, placement}, keyed "a|b".
     *
     * @return array<string, array>
     */
    private function collect(?array $ids, ?string $from, ?string $to, array $dims): array
    {
        if ($ids !== null && !$ids) {
            return [];
        }
        $acc = [];
        $byPlacement = in_array('placement', $dims, true);
        // Spend per placement comes from the clicks' costs, scaled per campaign to its net
        // ledger spend (so refunds show there too) — hence the campaign is always needed.
        $eventDims = $byPlacement ? array_values(array_unique(array_merge(['campaign'], $dims))) : $dims;

        // Impressions and valid clicks.
        $q = DB::table('sponsorship_events as e')->whereIn('e.event', ['impression', 'click']);
        $this->scope($q, 'e.sponsorship_id', $ids);
        $this->between($q, 'e.created_at', $from, $to);
        $events = $this->grouped($q, $eventDims, ['campaign' => 'e.sponsorship_id', 'date' => AdClock::sqlDate('e.created_at'), 'placement' => 'e.placement'],
            "SUM(e.event = 'impression') AS imp, SUM(e.event = 'click' AND e.countable = 1) AS clk,
             SUM(CASE WHEN e.event = 'click' THEN e.cost ELSE 0 END) AS cost,
             SUM(CASE WHEN e.event = 'click' THEN e.credit_cost ELSE 0 END) AS credit");
        $factor = $byPlacement ? $this->netFactors(array_unique(array_map(fn ($r) => (int) $r->campaign, $events))) : [];

        foreach ($events as $r) {
            $k = $this->key($r, $dims);
            $this->add($acc, $k, 'impressions', (int) $r->imp);
            $this->add($acc, $k, 'clicks', (int) $r->clk);
            if ($byPlacement) {
                $f = $factor[(int) $r->campaign] ?? 0.0;
                $this->add($acc, $k, 'spend', (float) $r->cost * $f);
                $this->add($acc, $k, 'credit_spend', (float) $r->credit * $f);
            }
        }

        // Spend from the wallet ledger.
        if (!$byPlacement) {
            $day = 'COALESCE(t.rollup_date, ' . AdClock::sqlDate('t.created_at') . ')';
            $q = DB::table('ad_wallet_transactions as t')->whereIn('t.type', [Tx::TYPE_CLICK_CHARGE, Tx::TYPE_REFUND])
                ->whereNotNull('t.sponsorship_id');
            $this->scope($q, 't.sponsorship_id', $ids);
            if ($from) {
                $q->whereRaw("{$day} >= ?", [$from]);
            }
            if ($to) {
                $q->whereRaw("{$day} <= ?", [$to]);
            }
            foreach ($this->grouped($q, $dims, ['campaign' => 't.sponsorship_id', 'date' => $day], '-SUM(t.amount) AS spend, -SUM(t.credit_amount) AS credit') as $r) {
                $k = $this->key($r, $dims);
                $this->add($acc, $k, 'spend', (float) $r->spend);
                $this->add($acc, $k, 'credit_spend', (float) $r->credit);
            }
        }

        // Orders and sales: standing orders only, one per order id.
        $q = DB::table('order_ad_attributions as a')
            ->join('order_items as oi', 'oi.id', '=', 'a.order_item_id')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->leftJoin('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->whereNotIn('o.status', self::DEAD_STATUSES)
            ->where(fn ($w) => $w->whereNull('so.id')->orWhere(fn ($w) => $w
                ->whereNotIn('so.status', self::DEAD_STATUSES)->where('so.payment_status', '!=', 'refunded')));
        if ($byPlacement) {
            $q->leftJoin('sponsorship_events as ce', 'ce.id', '=', 'a.click_event_id');
        }
        $this->scope($q, 'a.sponsorship_id', $ids);
        $this->between($q, 'o.created_at', $from, $to);
        $orderRows = $this->grouped($q, array_merge($dims, ['order']), [
            'campaign' => 'a.sponsorship_id', 'date' => AdClock::sqlDate('o.created_at'),
            'placement' => "COALESCE(ce.placement, 'unknown')", 'order' => 'a.order_id',
        ], 'SUM(a.revenue) AS revenue');

        foreach ($orderRows as $r) {
            $k = $this->key($r, $dims);
            $acc[$k]['order_ids'][(int) $r->order] = true;
            $this->add($acc, $k, 'revenue', (float) $r->revenue);
        }

        return array_map(fn ($m) => $this->finish($m), $acc);
    }

    /** net ledger spend ÷ gross click cost, per campaign (1 when nothing was refunded). */
    private function netFactors(array $campaignIds): array
    {
        if (!$campaignIds) {
            return [];
        }
        $gross = DB::table('sponsorship_events')->whereIn('sponsorship_id', $campaignIds)->where('event', 'click')
            ->groupBy('sponsorship_id')->pluck(DB::raw('SUM(cost)'), 'sponsorship_id');
        $net = DB::table('ad_wallet_transactions')->whereIn('sponsorship_id', $campaignIds)
            ->whereIn('type', [Tx::TYPE_CLICK_CHARGE, Tx::TYPE_REFUND])
            ->groupBy('sponsorship_id')->pluck(DB::raw('-SUM(amount)'), 'sponsorship_id');

        $out = [];
        foreach ($campaignIds as $id) {
            $g = (float) ($gross[$id] ?? 0);
            $out[$id] = $g > 0 ? (float) ($net[$id] ?? 0) / $g : 0.0;
        }
        return $out;
    }

    private function grouped(Builder $q, array $dims, array $columns, string $aggregates): array
    {
        $select = [];
        foreach ($dims as $d) {
            $select[] = "{$columns[$d]} AS `{$d}`";
        }
        $q->selectRaw(implode(', ', array_merge($select, [$aggregates])));
        foreach ($dims as $d) {
            $q->groupBy(DB::raw("`{$d}`"));
        }
        return $q->get()->all();
    }

    private function scope(Builder $q, string $column, ?array $ids): void
    {
        if ($ids !== null) {
            $q->whereIn($column, array_map('intval', $ids));
        }
    }

    private function between(Builder $q, string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $q->where($column, '>=', AdClock::dayStart($from)->toDateTimeString());
        }
        if ($to) {
            $q->where($column, '<=', AdClock::dayEnd($to)->toDateTimeString());
        }
    }

    private function key(object $row, array $dims): string
    {
        return implode('|', array_map(fn ($d) => (string) $row->{$d}, $dims));
    }

    private function add(array &$acc, string $key, string $field, float|int $value): void
    {
        $acc[$key][$field] = ($acc[$key][$field] ?? 0) + $value;
    }

    private function finish(array $m): array
    {
        $imp     = (int) ($m['impressions'] ?? 0);
        $clicks  = (int) ($m['clicks'] ?? 0);
        $spend   = round((float) ($m['spend'] ?? 0), 3);
        $credit  = round((float) ($m['credit_spend'] ?? 0), 3);
        $orders  = count($m['order_ids'] ?? []);
        $revenue = round((float) ($m['revenue'] ?? 0), 3);

        return [
            'impressions'    => $imp,
            'clicks'         => $clicks,
            'ctr'            => $imp > 0 ? round($clicks / $imp, 4) : null,
            'spend'          => $spend,
            'paid_spend'     => round($spend - $credit, 3),
            'credit_spend'   => $credit,
            'orders'         => $orders,
            'revenue'        => $revenue,
            'roas'           => $spend > 0 ? round($revenue / $spend, 2) : null,
            'cost_per_order' => $orders > 0 ? round($spend / $orders, 3) : null,
        ];
    }

    /** Chart and placement rows have always called spend "cost". */
    private function withCost(array $m): array
    {
        return $m + ['cost' => $m['spend']];
    }
}
