<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily sales series per product and per variant, from real sales only.
 *
 *  - seller_orders in config('forecast.sale_statuses') — pending, cancelled and
 *    refunded sub-orders never count;
 *  - items of approved return/refund complaints are taken out (partial refunds);
 *  - revenue is net of discounts (order_items.net_total);
 *  - promo_units = units sold through one of our promotions (flash sale /
 *    discount), promo_day = a promotion or a sponsored boost was running;
 *  - views / add-to-cart / favourites from user_interactions.
 *
 * Days are local (Africa/Tunis). Stored in forecast_daily_sales.
 */
class SalesSeries
{
    /** Rebuild the stored series of a seller's products. Returns the number of rows written. */
    public function rebuild(int $sellerId, ?CarbonImmutable $today = null): int
    {
        $tz    = config('forecast.timezone');
        $today = $today ?? CarbonImmutable::now($tz)->startOfDay();
        $since = $today->subDays(config('forecast.history_days'));

        $products = DB::table('products')->where('seller_id', $sellerId)->whereNull('deleted_at')->pluck('id')->all();
        DB::table('forecast_daily_sales')->where('seller_id', $sellerId)->delete();
        if (!$products) return 0;

        $rows = [];   // "product|variant|day" => row
        $touch = function (int $pid, int $vid, string $day) use ($sellerId) {
            return [
                'seller_id' => $sellerId, 'product_id' => $pid, 'variant_id' => $vid, 'day' => $day,
                'units' => 0, 'net_revenue' => 0.0, 'orders' => [], 'promo_units' => 0, 'promo_day' => false,
                'views' => 0, 'cart_adds' => 0, 'favorites' => 0,
            ];
        };

        // ── Sales ───────────────────────────────────────────────────────────
        $returned = $this->returnedItemIds($sellerId);
        $items = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->where('so.seller_id', $sellerId)
            ->whereIn('so.status', config('forecast.sale_statuses'))
            ->where('so.created_at', '>=', $since->subDay()->utc())
            ->whereIn('oi.product_id', $products)
            ->select('oi.id', 'oi.product_id', 'oi.variant_id', 'oi.quantity', 'oi.promotion_id', 'so.id as so_id', 'so.created_at')
            ->selectRaw('COALESCE(oi.net_total, oi.total, oi.unit_price * oi.quantity, 0) as net')
            ->orderBy('oi.id')
            ->get();

        foreach ($items as $it) {
            if (isset($returned[$it->id])) continue;
            $day = CarbonImmutable::parse($it->created_at, 'UTC')->tz($tz)->toDateString();
            if ($day < $since->toDateString() || $day >= $today->toDateString()) continue;
            $targets = [0];
            if ($it->variant_id) $targets[] = (int) $it->variant_id;
            foreach ($targets as $vid) {
                $k = "{$it->product_id}|$vid|$day";
                $rows[$k] ??= $touch((int) $it->product_id, $vid, $day);
                $rows[$k]['units']       += (int) $it->quantity;
                $rows[$k]['net_revenue'] += (float) $it->net;
                $rows[$k]['orders'][$it->so_id] = true;
                if ($it->promotion_id) $rows[$k]['promo_units'] += (int) $it->quantity;
            }
        }

        // ── Promotion and boost days ────────────────────────────────────────
        foreach ($this->promoWindows($sellerId, $products) as [$pid, $start, $end]) {
            $s = CarbonImmutable::parse($start, 'UTC')->tz($tz)->startOfDay()->max($since);
            $e = CarbonImmutable::parse($end, 'UTC')->tz($tz)->startOfDay()->min($today->subDay());
            for ($d = $s; $d <= $e; $d = $d->addDay()) {
                $k = "$pid|0|{$d->toDateString()}";
                $rows[$k] ??= $touch($pid, 0, $d->toDateString());
                $rows[$k]['promo_day'] = true;
            }
        }

        // ── Leading signals ─────────────────────────────────────────────────
        $offset = CarbonImmutable::now($tz)->utcOffset();   // minutes; Tunisia has no DST
        $signals = DB::table('user_interactions')
            ->whereIn('product_id', $products)
            ->whereIn('event_type', ['view', 'cart_add', 'favorite_add'])
            ->where('created_at', '>=', $since->utc())
            ->where('created_at', '<', $today->utc())
            ->selectRaw("product_id, event_type, DATE(DATE_ADD(created_at, INTERVAL {$offset} MINUTE)) as d, COUNT(*) as n")
            ->groupBy('product_id', 'event_type', 'd')
            ->get();
        $col = ['view' => 'views', 'cart_add' => 'cart_adds', 'favorite_add' => 'favorites'];
        foreach ($signals as $s) {
            $k = "{$s->product_id}|0|{$s->d}";
            $rows[$k] ??= $touch((int) $s->product_id, 0, $s->d);
            $rows[$k][$col[$s->event_type]] += (int) $s->n;
        }

        // ── Write ───────────────────────────────────────────────────────────
        $insert = array_map(function ($r) {
            $r['orders']      = count($r['orders']);
            $r['net_revenue'] = round($r['net_revenue'], 3);
            return $r;
        }, array_values($rows));
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('forecast_daily_sales')->insert($chunk);
        }
        return count($insert);
    }

    /**
     * Everything the forecaster needs about a seller's live products.
     * @return array<int, array> keyed by product id
     */
    public function load(int $sellerId): array
    {
        $products = DB::table('products')
            ->where('seller_id', $sellerId)->whereNull('deleted_at')
            ->select('id', 'name', 'price', 'stock', 'category_id', 'is_active', 'is_approved', 'created_at')
            ->orderBy('id')->get()->keyBy('id');
        if ($products->isEmpty()) return [];

        $tz  = config('forecast.timezone');
        $out = [];
        foreach ($products as $p) {
            $out[$p->id] = [
                'id'           => (int) $p->id,
                'name'         => $p->name,
                'price'        => (float) $p->price,
                'stock'        => (int) $p->stock,
                'category_id'  => (int) $p->category_id,
                'live'         => (bool) $p->is_active && (bool) $p->is_approved,
                'listed_since' => CarbonImmutable::parse($p->created_at, 'UTC')->tz($tz)->toDateString(),
                'daily'        => [],
                'variants'     => [],
            ];
        }

        foreach (DB::table('forecast_daily_sales')->where('seller_id', $sellerId)->orderBy('day')->get() as $r) {
            if (!isset($out[$r->product_id])) continue;
            $day = (string) $r->day;
            if ((int) $r->variant_id === 0) {
                $out[$r->product_id]['daily'][$day] = [
                    'units' => (int) $r->units, 'net_revenue' => (float) $r->net_revenue, 'orders' => (int) $r->orders,
                    'promo_units' => (int) $r->promo_units, 'promo_day' => (bool) $r->promo_day,
                    'views' => (int) $r->views, 'cart_adds' => (int) $r->cart_adds, 'favorites' => (int) $r->favorites,
                ];
            } else {
                $out[$r->product_id]['variant_daily'][(int) $r->variant_id][$day] = (int) $r->units;
            }
        }

        // Active variants: stock, price, labels in the three languages
        $variants = DB::table('product_variants')
            ->whereIn('product_id', array_keys($out))->where('is_active', true)
            ->select('id', 'product_id', 'stock', 'price_override')->orderBy('id')->get();
        $labels = $this->variantLabels($variants->pluck('id')->all());
        foreach ($variants as $v) {
            $p = &$out[$v->product_id];
            $p['variants'][(int) $v->id] = [
                'labels' => $labels[$v->id] ?? ['fr' => "#{$v->id}", 'en' => "#{$v->id}", 'ar' => "#{$v->id}"],
                'stock'  => (int) $v->stock,
                'price'  => $v->price_override !== null ? (float) $v->price_override : null,
                'daily'  => $p['variant_daily'][(int) $v->id] ?? [],
            ];
            unset($p);
        }
        foreach ($out as &$p) {
            if ($p['variants']) $p['stock'] = array_sum(array_column($p['variants'], 'stock'));
            unset($p['variant_daily']);
        }
        unset($p);

        return $out;
    }

    /**
     * order_item ids to leave out of the series: lines of legacy refunded
     * returns (before 2026-10 the line itself was not reduced). New returns
     * reduce the line's quantity when refunded, so they need no exclusion.
     */
    private function returnedItemIds(int $sellerId): array
    {
        $ids = [];
        $rows = DB::table('complaints')
            ->where('seller_id', $sellerId)
            ->where('resolution_type', 'return_refund')
            ->where('status', 'refunded')
            ->whereNotExists(fn($q) => $q->from('complaint_items as ci')->join('order_items as oi', 'oi.id', '=', 'ci.order_item_id')
                ->whereColumn('ci.complaint_id', 'complaints.id')->where('oi.returned_quantity', '>', 0))
            ->pluck('order_item_ids');
        foreach ($rows as $json) {
            foreach ((array) json_decode((string) $json, true) as $id) {
                if (is_numeric($id)) $ids[(int) $id] = true;
            }
        }
        return $ids;
    }

    /** [product_id, start, end] of flash sales / discounts and sponsored boosts. */
    private function promoWindows(int $sellerId, array $productIds): array
    {
        $out = [];
        $promos = DB::table('promotions as pr')
            ->join('promotion_products as pp', 'pp.promotion_id', '=', 'pr.id')
            ->where('pr.seller_id', $sellerId)
            ->whereIn('pp.product_id', $productIds)
            ->select('pp.product_id', 'pr.starts_at', 'pr.ends_at')->get();
        foreach ($promos as $p) {
            if ($p->starts_at) $out[] = [(int) $p->product_id, $p->starts_at, $p->ends_at ?? now()];
        }

        $boosts = DB::table('sponsorships')
            ->where('seller_id', $sellerId)
            ->whereIn('product_id', $productIds)
            ->whereIn('status', ['active', 'paused', 'completed', 'expired'])
            ->whereNotNull('start_at')
            ->select('product_id', 'start_at', 'end_at', 'ended_at')->get();
        foreach ($boosts as $b) {
            $out[] = [(int) $b->product_id, $b->start_at, $b->ended_at ?? $b->end_at ?? now()];
        }
        return $out;
    }

    /** variant id => ['fr' => 'Rouge / M', 'en' => 'Red / M', 'ar' => …] */
    private function variantLabels(array $variantIds): array
    {
        if (!$variantIds) return [];
        $rows = DB::table('variant_attribute_values as vav')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->join('attributes as a', 'a.id', '=', 'ao.attribute_id')
            ->whereIn('vav.variant_id', $variantIds)
            ->orderBy('a.order')->orderBy('a.id')
            ->select('vav.variant_id', 'ao.value', 'ao.value_fr', 'ao.value_ar')->get();
        $out = [];
        foreach ($rows->groupBy('variant_id') as $vid => $opts) {
            $out[$vid] = [
                'fr' => $opts->map(fn($o) => $o->value_fr ?: $o->value)->join(' / '),
                'en' => $opts->pluck('value')->join(' / '),
                'ar' => $opts->map(fn($o) => $o->value_ar ?: $o->value)->join(' / '),
            ];
        }
        return $out;
    }
}
