<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Seller\Ads\AdCampaignController;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Ads\AdSettings;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only audit: recomputes every seller-facing ad number straight from the raw
 * tables (sponsorship_events, order_ad_attributions + seller_orders, the wallet
 * ledger) — independently of the app's own metric code — and compares it with
 * what the seller ads API returns. Nothing is written (guarded + rolled back).
 *
 *   php artisan ads:reconcile            every campaign + every advertiser's overview
 *   php artisan ads:reconcile 7 --all    one campaign, matching rows too
 */
class ReconcileAds extends Command
{
    protected $signature   = 'ads:reconcile {campaign_id? : Only this campaign} {--all : Also print the rows that match}';
    protected $description = 'Compare seller ad dashboard numbers with the raw events, orders and wallet ledger (read-only)';

    private array $rows = [];
    private int $mismatches = 0;

    public function handle(AdSettings $settings): int
    {
        DB::beginTransaction();
        DB::listen(function ($q) {
            if (!preg_match('/^\s*(select|show|set\s+session|savepoint|release)/i', $q->sql)) {
                DB::rollBack();
                $this->error('Aborted: the audit tried to write: ' . $q->sql);
                exit(2);
            }
        });

        try {
            $campaigns = Sponsorship::query()
                ->when($this->argument('campaign_id'), fn ($q, $id) => $q->whereKey($id))
                ->orderBy('id')->get();
            $dedupeHours = max(1, $settings->int('click_dedupe_hours'));
            $raw = [];

            foreach ($campaigns as $c) {
                $raw[$c->id] = $r = $this->raw($c, $dedupeHours);
                $api = $this->api($c);
                $label = "#{$c->id} {$c->pricing_model}";

                $this->check($label, 'clicks: raw all / charged / valid', "{$r['clicks_all']} / {$r['clicks_charged']} / {$r['clicks_valid']}", null);
                $this->check($label, 'clicks (detail KPI)', $r['clicks_valid'], $api['detail']['summary']['clicks']);
                $this->check($label, 'clicks (campaign list)', $r['clicks_valid'], $api['list']['stats']['clicks'] ?? null);
                $this->check($label, 'clicks (sum of daily chart)', $r['clicks_valid'], array_sum(array_column($api['detail']['daily'], 'clicks')));
                $this->check($label, 'clicks (sum of placements)', $r['clicks_valid'], array_sum(array_column($api['detail']['placement_stats'], 'clicks')));
                $this->check($label, 'impressions (detail KPI)', $r['impressions'], $api['detail']['summary']['impressions']);
                $this->check($label, 'orders (detail KPI)', $r['orders'], $api['detail']['summary']['orders']);
                $this->check($label, 'orders (campaign list)', $r['orders'], $api['list']['stats']['orders'] ?? null);
                $this->check($label, 'orders (sum of daily chart)', $r['orders'], array_sum(array_column($api['detail']['daily'], 'orders')));
                $this->check($label, 'sales (detail KPI)', $r['sales'], $api['detail']['summary']['revenue']);
                $this->check($label, 'sales (sum of daily chart)', $r['sales'], round(array_sum(array_column($api['detail']['daily'], 'revenue')), 3));
                $this->check($label, 'spend: wallet debits vs click costs', $r['spend'], $r['event_cost']);
                $this->check($label, 'spend (detail KPI)', $r['spend'], $api['detail']['summary']['spend']);
                $this->check($label, 'spend (sum of daily chart)', $r['spend'], round(array_sum(array_column($api['detail']['daily'], 'cost')), 3));
                $this->check($label, 'spend from free credit', $r['credit_spend'], $api['detail']['summary']['credit_spend']);
                $this->check($label, 'ROAS', $this->ratio($r['sales'], $r['spend'], 2), $api['detail']['summary']['roas']);
                $this->check($label, 'cost per order', $this->ratio($r['spend'], $r['orders'], 3), $api['detail']['summary']['cost_per_order']);
            }

            // Each advertiser's ads home (last 30 days): an order touching two campaigns is one order.
            if (!$this->argument('campaign_id')) {
                foreach ($campaigns->groupBy('seller_id') as $sellerId => $list) {
                    $t = $this->overview((int) $sellerId)['totals'];
                    $label = "seller {$sellerId}";
                    $since = \App\Services\Ads\AdClock::now()->subDays(29)->startOfDay();
                    $ids = $list->pluck('id')->all();
                    $sum = fn ($k) => array_sum(array_map(fn ($id) => $raw[$id]['recent'][$k], $ids));

                    $this->check($label, 'overview clicks', $sum('clicks'), $t['clicks']);
                    $this->check($label, 'overview impressions', $sum('impressions'), $t['impressions']);
                    $this->check($label, 'overview orders', $this->distinctOrders($ids, $since), $t['orders']);
                    $this->check($label, 'overview sales', round($sum('sales'), 3), $t['revenue']);
                    $this->check($label, 'overview spend', round($sum('spend'), 3), $t['spend']);
                }
            }
        } finally {
            DB::rollBack();
        }

        $shown = $this->option('all') ? $this->rows : array_values(array_filter($this->rows, fn ($r) => $r[4] !== 'ok'));
        if ($shown) {
            $this->table(['scope', 'metric', 'raw data', 'dashboard API', ''], $shown);
        }
        $this->line($this->mismatches === 0
            ? '<info>0 mismatches</info> (' . count($this->rows) . ' checks)'
            : "<error>{$this->mismatches} mismatches</error> (" . count($this->rows) . ' checks)');

        return $this->mismatches === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Every number recomputed from raw rows only. */
    private function raw(Sponsorship $c, int $dedupeHours): array
    {
        $clicks = DB::table('sponsorship_events')->where('sponsorship_id', $c->id)->where('event', 'click')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'user_id', 'session_id', 'ip_hash', 'billable', 'countable', 'cost', 'credit_cost', 'created_at']);

        // A valid click: not flagged as bot/burst, not the seller's own, and not a repeat by the
        // same viewer (user or browser session) within the dedupe window of their last valid click.
        // Recomputed here on purpose, instead of trusting the stored countable flag.
        $valid = [];
        foreach ($clicks as $e) {
            $self = $e->user_id && (int) $e->user_id === (int) $c->seller_id;
            $dupe = false;
            foreach ($valid as $v) {
                $sameViewer = ($e->user_id && $v->user_id == $e->user_id) || ($e->session_id && $v->session_id === $e->session_id)
                    || (!$e->user_id && !$e->session_id && $e->ip_hash && $v->ip_hash === $e->ip_hash);
                if ($sameViewer && strtotime($e->created_at) - strtotime($v->created_at) < $dedupeHours * 3600) {
                    $dupe = true;
                    break;
                }
            }
            // A charged click always stands (money was taken for it).
            if ($e->billable || (!$self && !$dupe && $e->countable)) {
                $valid[] = $e;
            }
        }

        $orders = $this->liveAttributions([$c->id]);
        $ledger = DB::table('ad_wallet_transactions')->where('sponsorship_id', $c->id)->whereIn('type', ['click_charge', 'refund'])
            ->selectRaw('COALESCE(SUM(amount), 0) t, COALESCE(SUM(credit_amount), 0) cr')->first();
        $refundedCost = DB::table('ad_wallet_transactions')->where('sponsorship_id', $c->id)->where('type', 'refund')->sum('amount');

        $since = \App\Services\Ads\AdClock::now()->subDays(29)->startOfDay()->setTimezone('UTC');
        $recentLedger = DB::table('ad_wallet_transactions')->where('sponsorship_id', $c->id)->whereIn('type', ['click_charge', 'refund'])
            ->where(fn ($q) => $q->where('rollup_date', '>=', \App\Services\Ads\AdClock::now()->subDays(29)->toDateString())
                ->orWhere(fn ($q) => $q->whereNull('rollup_date')->where('created_at', '>=', $since)))
            ->sum('amount');

        return [
            'clicks_all'     => $clicks->count(),
            'clicks_charged' => $clicks->where('billable', true)->count(),
            'clicks_valid'   => count($valid),
            'impressions'    => DB::table('sponsorship_events')->where('sponsorship_id', $c->id)->where('event', 'impression')->count(),
            'orders'         => $orders->pluck('order_id')->unique()->count(),
            'sales'          => round((float) $orders->sum('revenue'), 3),
            'spend'          => round(-(float) $ledger->t, 3),
            'credit_spend'   => round(-(float) $ledger->cr, 3),
            'event_cost'     => round((float) $clicks->sum('cost') - (float) $refundedCost, 3),
            'recent'         => [
                'clicks'      => count(array_filter($valid, fn ($e) => strtotime($e->created_at) >= $since->getTimestamp())),
                'impressions' => DB::table('sponsorship_events')->where('sponsorship_id', $c->id)->where('event', 'impression')->where('created_at', '>=', $since)->count(),
                'sales'       => (float) $orders->where('ordered_at', '>=', $since->toDateTimeString())->sum('revenue'),
                'spend'       => -(float) $recentLedger,
            ],
        ];
    }

    /** Attributed order lines whose seller order is still standing (not cancelled or refunded). */
    private function liveAttributions(array $campaignIds)
    {
        return DB::table('order_ad_attributions as a')
            ->join('order_items as oi', 'oi.id', '=', 'a.order_item_id')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->leftJoin('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->whereIn('a.sponsorship_id', $campaignIds)
            ->whereNotIn('o.status', ['cancelled', 'refused', 'returned_to_seller', 'refunded'])
            ->where(fn ($q) => $q->whereNull('so.id')->orWhere(fn ($q) => $q->whereNotIn('so.status', ['cancelled', 'refused', 'returned_to_seller', 'refunded'])
                ->where('so.payment_status', '!=', 'refunded')))
            ->get(['a.order_id', 'a.revenue', 'o.created_at as ordered_at']);
    }

    private function distinctOrders(array $campaignIds, $since): int
    {
        return $this->liveAttributions($campaignIds)->where('ordered_at', '>=', $since->copy()->setTimezone('UTC')->toDateTimeString())
            ->pluck('order_id')->unique()->count();
    }

    /** What the seller ads API returns for this campaign (detail page + list row). */
    private function api(Sponsorship $c): array
    {
        $ctl = app(AdCampaignController::class);
        $req = $this->sellerRequest((int) $c->seller_id);
        $detail = app()->call([$ctl, 'show'], ['request' => $req, 'id' => $c->id])->getData(true)['data'];

        $list = collect($ctl->index($this->sellerRequest((int) $c->seller_id, ['per_page' => 50]))->getData(true)['data'])
            ->firstWhere('id', $c->id) ?? [];

        return ['detail' => $detail, 'list' => $list];
    }

    private function overview(int $sellerId): array
    {
        return app()->call([app(AdCampaignController::class), 'overview'], ['request' => $this->sellerRequest($sellerId, ['days' => 30])])->getData(true)['data'];
    }

    private function sellerRequest(int $sellerId, array $query = []): Request
    {
        $req = Request::create('/', 'GET', $query);
        $seller = User::find($sellerId);
        $req->setUserResolver(fn () => $seller);
        return $req;
    }

    private function ratio(float $a, float $b, int $precision): ?float
    {
        return $b > 0 ? round($a / $b, $precision) : null;
    }

    private function check(string $scope, string $metric, $raw, $api): void
    {
        if ($api === null && !is_numeric($raw)) {       // informational row
            $this->rows[] = [$scope, $metric, $raw, '', 'ok'];
            return;
        }
        $same = is_numeric($raw) && is_numeric($api) ? abs((float) $raw - (float) $api) < 0.0005 : $raw === $api;
        if (!$same) {
            $this->mismatches++;
        }
        $fmt = fn ($v) => $v === null ? '—' : (is_float($v) ? number_format($v, 3, '.', '') : (string) $v);
        $this->rows[] = [$scope, $metric, $fmt($raw), $fmt($api), $same ? 'ok' : 'MISMATCH'];
    }
}
