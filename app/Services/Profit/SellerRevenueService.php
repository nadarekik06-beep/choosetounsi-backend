<?php

namespace App\Services\Profit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for a seller's money (Centre de profit, goal
 * alerts, CSV export). Built on seller_orders — the per-seller financial
 * snapshot frozen at checkout — never on orders.status or products.seller_id.
 *
 * Definitions (all amounts in DT, months and days in Africa/Tunis):
 *
 *   sales       Σ (subtotal − discount_amount) of sub-orders in profit.sale_statuses
 *               (confirmed → delivered). What the buyers pay for the seller's items,
 *               after the seller's coupon. Returns are already out: a full return
 *               cancels the sub-order, a partial one lowers its subtotal.
 *   delivered   the part of sales already delivered (money secured)
 *   refunds     value of returned items on that month's orders (completed returns)
 *   gross       sales + refunds — what was sold before returns
 *   commission  Σ commission_amount (already reversed on returned items)
 *   shipping    Σ seller_shipping_charge (free-shipping orders the seller pays for)
 *   ads         Pubs & boost clicks paid from the wallet (cash). Free plan credit is
 *               reported apart (ads_credit) and not deducted.
 *   net         sales − commission − shipping − ads  =  what the seller keeps
 *
 * An order belongs to the month it was placed in (seller_orders.created_at).
 */
class SellerRevenueService
{
    public function tz(): string
    {
        return config('profit.timezone', 'Africa/Tunis');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tz());
    }

    public function monthStart(string $ym): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $ym, $this->tz());
    }

    /** Local wall-clock expression for a UTC timestamp column (numeric offset: no tz tables needed). */
    private function local(string $column): string
    {
        $offset = $this->now()->format('P');
        return "CONVERT_TZ({$column}, '+00:00', '{$offset}')";
    }

    private function utc(CarbonImmutable $local): string
    {
        return $local->utc()->format('Y-m-d H:i:s');
    }

    private function statuses(): array
    {
        return config('profit.sale_statuses');
    }

    // ── Totals ──────────────────────────────────────────────────────────────

    /** Sales and order count between two local instants ([from, to)). */
    public function salesBetween(int $sellerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->whereIn('status', $this->statuses())
            ->where('created_at', '>=', $this->utc($from))
            ->where('created_at', '<', $this->utc($to))
            ->selectRaw('COALESCE(SUM(subtotal - discount_amount), 0) as sales, COUNT(*) as orders')
            ->first();

        return ['sales' => round((float) $row->sales, 3), 'orders' => (int) $row->orders];
    }

    /**
     * Full money picture per month, for months in [$from, $to) (local month starts).
     * @return array<string, array{month:string, sales:float, delivered:float, in_progress:float, orders:int,
     *               commission:float, shipping:float, refunds:float, ads:float, ads_credit:float, gross:float, net:float}>
     */
    public function monthly(int $sellerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $delivered = "'" . implode("','", config('profit.delivered_statuses')) . "'";
        $rows = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->whereIn('status', $this->statuses())
            ->where('created_at', '>=', $this->utc($from))
            ->where('created_at', '<', $this->utc($to))
            ->selectRaw("DATE_FORMAT({$this->local('created_at')}, '%Y-%m') as ym,
                COALESCE(SUM(subtotal - discount_amount), 0) as sales,
                COALESCE(SUM(CASE WHEN status IN ({$delivered}) THEN subtotal - discount_amount ELSE 0 END), 0) as delivered,
                COUNT(*) as orders,
                COALESCE(SUM(commission_amount), 0) as commission,
                COALESCE(SUM(seller_shipping_charge), 0) as shipping")
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $refunds = $this->refunds($sellerId, $from, $to)['by_month'];
        $ads     = $this->adSpend($sellerId, $from, $to);

        $out = [];
        for ($m = $from; $m < $to; $m = $m->addMonthNoOverflow()) {
            $ym  = $m->format('Y-m');
            $r   = $rows[$ym] ?? null;
            $out[$ym] = $this->totals($ym, $r ? (array) $r : [], $refunds[$ym] ?? 0.0, $ads[$ym] ?? ['cash' => 0.0, 'credit' => 0.0]);
        }
        return $out;
    }

    private function totals(string $ym, array $r, float $refunds, array $ads): array
    {
        $sales      = round((float) ($r['sales'] ?? 0), 3);
        $delivered  = round((float) ($r['delivered'] ?? 0), 3);
        $commission = round((float) ($r['commission'] ?? 0), 3);
        $shipping   = round((float) ($r['shipping'] ?? 0), 3);
        $refunds    = round($refunds, 3);
        return [
            'month'       => $ym,
            'sales'       => $sales,
            'delivered'   => $delivered,
            'in_progress' => round($sales - $delivered, 3),
            'orders'      => (int) ($r['orders'] ?? 0),
            'commission'  => $commission,
            'shipping'    => $shipping,
            'refunds'     => $refunds,
            'ads'         => round($ads['cash'], 3),
            'ads_credit'  => round($ads['credit'], 3),
            'gross'       => round($sales + $refunds, 3),
            'net'         => round($sales - $commission - $shipping - $ads['cash'], 3),
        ];
    }

    /** Cumulative sales per local day of a month, up to $until (inclusive day). */
    public function dailyCumulative(int $sellerId, CarbonImmutable $monthStart, CarbonImmutable $until): array
    {
        $rows = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->whereIn('status', $this->statuses())
            ->where('created_at', '>=', $this->utc($monthStart))
            ->where('created_at', '<', $this->utc($monthStart->addMonthNoOverflow()))
            ->selectRaw("DAY({$this->local('created_at')}) as d, SUM(subtotal - discount_amount) as sales")
            ->groupBy('d')
            ->pluck('sales', 'd');

        $out = [];
        $cum = 0.0;
        for ($d = 1; $d <= (int) $until->format('j'); $d++) {
            $cum += (float) ($rows[$d] ?? 0);
            $out[] = ['day' => $d, 'sales' => round($cum, 3)];
        }
        return $out;
    }

    /** First local instant the seller ever made a counted sale, or null. */
    public function firstSaleAt(int $sellerId): ?CarbonImmutable
    {
        $at = DB::table('seller_orders')->where('seller_id', $sellerId)
            ->whereIn('status', $this->statuses())->min('created_at');
        return $at ? CarbonImmutable::parse($at, 'UTC')->setTimezone($this->tz()) : null;
    }

    // ── Returns ─────────────────────────────────────────────────────────────

    /**
     * Completed returns (return_refund) on orders placed in [$from, $to).
     * @return array{by_month: array<string,float>, item_ids: int[]}
     */
    public function refunds(int $sellerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $complaints = DB::table('complaints as c')
            ->join('seller_orders as so', function ($j) {
                $j->on('so.order_id', '=', 'c.order_id')->on('so.seller_id', '=', 'c.seller_id');
            })
            ->where('c.seller_id', $sellerId)
            ->where('c.refund_status', 'completed')
            ->where(fn($q) => $q->where('c.resolution_type', 'return_refund')->orWhereNull('c.resolution_type'))
            ->where('so.created_at', '>=', $this->utc($from))
            ->where('so.created_at', '<', $this->utc($to))
            ->get(['c.order_item_ids', 'so.id as so_id', 'so.created_at']);

        if ($complaints->isEmpty()) return ['by_month' => [], 'item_ids' => []];

        $items = DB::table('order_items')
            ->whereIn('seller_order_id', $complaints->pluck('so_id')->unique())
            ->get(['id', 'seller_order_id', 'total', 'discount_amount'])
            ->groupBy('seller_order_id');

        $byMonth = [];
        $ids     = [];
        foreach ($complaints as $c) {
            $chosen = json_decode((string) $c->order_item_ids, true);
            $lines  = collect($items[$c->so_id] ?? []);
            if (is_array($chosen) && $chosen) $lines = $lines->whereIn('id', $chosen);
            $lines  = $lines->reject(fn($l) => in_array($l->id, $ids, true)); // one return per line
            if ($lines->isEmpty()) continue;

            $ym = CarbonImmutable::parse($c->created_at, 'UTC')->setTimezone($this->tz())->format('Y-m');
            $byMonth[$ym] = ($byMonth[$ym] ?? 0) + $lines->sum(fn($l) => (float) $l->total - (float) ($l->discount_amount ?? 0));
            array_push($ids, ...$lines->pluck('id')->map(fn($v) => (int) $v)->all());
        }
        return ['by_month' => $byMonth, 'item_ids' => $ids];
    }

    // ── Ads (Pubs & boost) ──────────────────────────────────────────────────

    /** @return array<string, array{cash: float, credit: float}> click spend per local month */
    public function adSpend(int $sellerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $day = "COALESCE(rollup_date, DATE({$this->local('created_at')}))";
        return DB::table('ad_wallet_transactions')
            ->where('seller_id', $sellerId)
            ->where('type', 'click_charge')
            ->whereRaw("{$day} >= ? AND {$day} < ?", [$from->toDateString(), $to->toDateString()])
            ->selectRaw("DATE_FORMAT({$day}, '%Y-%m') as ym, ABS(COALESCE(SUM(amount), 0)) as cash, ABS(COALESCE(SUM(credit_amount), 0)) as credit")
            ->groupBy('ym')
            ->get()
            ->mapWithKeys(fn($r) => [$r->ym => ['cash' => (float) $r->cash, 'credit' => (float) $r->credit]])
            ->all();
    }

    /** Ad spend vs revenue attributed to the seller's campaigns in a month, or null without any ad activity. */
    public function adReturn(int $sellerId, CarbonImmutable $monthStart): ?array
    {
        $end   = $monthStart->addMonthNoOverflow();
        $spend = $this->adSpend($sellerId, $monthStart, $end)[$monthStart->format('Y-m')] ?? ['cash' => 0.0, 'credit' => 0.0];

        $attr = DB::table('order_ad_attributions as a')
            ->join('sponsorships as s', 's.id', '=', 'a.sponsorship_id')
            ->where('s.seller_id', $sellerId)
            ->where('a.status', 'converted')
            ->where('a.created_at', '>=', $this->utc($monthStart))
            ->where('a.created_at', '<', $this->utc($end))
            ->selectRaw('COALESCE(SUM(a.revenue), 0) as revenue, COUNT(DISTINCT a.order_id) as orders')
            ->first();

        $live = DB::table('sponsorships')->where('seller_id', $sellerId)->where('status', 'active')->count();
        $total = $spend['cash'] + $spend['credit'];
        if ($total <= 0 && (float) $attr->revenue <= 0 && $live === 0) return null;

        return [
            'spend'     => round($spend['cash'], 3),
            'credit'    => round($spend['credit'], 3),
            'revenue'   => round((float) $attr->revenue, 3),
            'orders'    => (int) $attr->orders,
            'roas'      => $total > 0 ? round((float) $attr->revenue / $total, 2) : null,
            'campaigns' => $live,
        ];
    }

    // ── Payouts & pending work ──────────────────────────────────────────────

    public function payouts(int $sellerId, CarbonImmutable $monthStart): array
    {
        $row = DB::table('seller_orders')
            ->where('seller_id', $sellerId)
            ->where('status', '!=', 'cancelled')
            ->selectRaw("COALESCE(SUM(CASE WHEN payout_status = 'pending' AND status IN ('delivered') THEN seller_net_amount ELSE 0 END), 0) as awaiting,
                COALESCE(SUM(CASE WHEN payout_status = 'ready' THEN seller_net_amount ELSE 0 END), 0) as ready")
            ->first();

        $paid = DB::table('settlement_batches')
            ->where('seller_id', $sellerId)->where('status', 'paid')
            ->where('paid_at', '>=', $this->utc($monthStart))
            ->where('paid_at', '<', $this->utc($monthStart->addMonthNoOverflow()))
            ->sum('total_seller_payout');

        $last = DB::table('settlement_batches')->where('seller_id', $sellerId)->where('status', 'paid')->max('paid_at');

        return [
            'awaiting_cash_in' => round((float) $row->awaiting, 3),   // delivered, the courier still holds the cash
            'ready'            => round((float) $row->ready, 3),      // cashed in, next settlement
            'paid_this_month'  => round((float) $paid, 3),
            'last_paid_at'     => $last ? CarbonImmutable::parse($last, 'UTC')->toIso8601String() : null,
        ];
    }

    /** Orders the seller still has to confirm (not counted as sales yet). */
    public function awaitingConfirmation(int $sellerId): array
    {
        $row = DB::table('seller_orders')->where('seller_id', $sellerId)->where('status', 'pending')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(subtotal - discount_amount), 0) as amount, MIN(created_at) as oldest')
            ->first();
        return [
            'count'       => (int) $row->n,
            'amount'      => round((float) $row->amount, 3),
            'oldest_days' => $row->oldest ? (int) CarbonImmutable::parse($row->oldest, 'UTC')->diffInDays(now()) : null,
        ];
    }

    // ── Products ────────────────────────────────────────────────────────────

    /**
     * Sales per product in [$from, $to): revenue (after coupon), units and what the
     * seller keeps after commission. Returned lines are left out.
     * @return array<int, array{id:int, name:string, revenue:float, kept:float, units:int}>
     */
    public function productSales(int $sellerId, CarbonImmutable $from, CarbonImmutable $to, array $excludeItemIds = []): array
    {
        $q = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('so.seller_id', $sellerId)
            ->whereIn('so.status', $this->statuses())
            ->where('so.created_at', '>=', $this->utc($from))
            ->where('so.created_at', '<', $this->utc($to));
        if ($excludeItemIds) $q->whereNotIn('oi.id', $excludeItemIds);

        return $q->selectRaw('oi.product_id as id, MAX(p.name) as name, MAX(p.deleted_at) as deleted_at,
                COALESCE(SUM(COALESCE(oi.net_total, oi.total - COALESCE(oi.discount_amount, 0))), 0) as revenue,
                COALESCE(SUM(COALESCE(oi.net_total, oi.total - COALESCE(oi.discount_amount, 0)) - COALESCE(oi.commission_amount, 0)), 0) as kept,
                COALESCE(SUM(oi.quantity), 0) as units')
            ->groupBy('oi.product_id')
            ->get()
            ->mapWithKeys(fn($r) => [(int) $r->id => [
                'id'      => (int) $r->id,
                'name'    => (string) $r->name,
                'deleted' => $r->deleted_at !== null,
                'revenue' => round((float) $r->revenue, 3),
                'kept'    => round((float) $r->kept, 3),
                'units'   => (int) $r->units,
            ]])
            ->all();
    }

    // ── Export ──────────────────────────────────────────────────────────────

    /** Every sub-order of the month (all statuses), for the CSV export. */
    public function orderLines(int $sellerId, CarbonImmutable $monthStart): array
    {
        return DB::table('seller_orders as so')
            ->join('orders as o', 'o.id', '=', 'so.order_id')
            ->where('so.seller_id', $sellerId)
            ->where('so.created_at', '>=', $this->utc($monthStart))
            ->where('so.created_at', '<', $this->utc($monthStart->addMonthNoOverflow()))
            ->orderBy('so.created_at')
            ->get(['o.order_number', 'so.created_at', 'so.status', 'so.subtotal', 'so.discount_amount',
                   'so.commission_amount', 'so.seller_shipping_charge', 'so.seller_net_amount', 'so.payout_status'])
            ->map(function ($r) {
                $amount = (float) $r->subtotal - (float) $r->discount_amount;
                $net    = $r->status === 'cancelled' ? 0.0
                    : $amount - (float) $r->commission_amount - (float) $r->seller_shipping_charge;
                return [
                    'order'      => $r->order_number,
                    'date'       => CarbonImmutable::parse($r->created_at, 'UTC')->setTimezone($this->tz())->format('Y-m-d H:i'),
                    'status'     => $r->status,
                    'amount'     => round($amount, 3),
                    'commission' => round((float) $r->commission_amount, 3),
                    'shipping'   => round((float) $r->seller_shipping_charge, 3),
                    'net'        => round($net, 3),
                    'payout'     => $r->payout_status,
                ];
            })
            ->all();
    }
}
