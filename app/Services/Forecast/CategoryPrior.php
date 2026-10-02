<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What similar products sell across the marketplace — the starting point for
 * sellers with no or little history.
 *
 * Similar = same category, price within ×0.5–×2 (falls back to the whole
 * category), live, other shops only, listed ≥ 14 days. Products without any
 * sale count too (with rate 0), otherwise the estimate would be inflated.
 *
 * Anonymity / honesty floors (config forecast.prior): at least N products from
 * M different shops with K orders in total, else null — no estimate at all.
 * Only aggregated rates leave this class, never a product or shop.
 */
class CategoryPrior
{
    private array $memo = [];

    public function for(int $categoryId, float $price, int $excludeSellerId, ?CarbonImmutable $today = null): ?array
    {
        if (!$categoryId) return null;
        $cfg   = config('forecast.prior');
        $today = $today ?? CarbonImmutable::now(config('forecast.timezone'))->startOfDay();

        [$lo, $hi] = $cfg['price_band'];
        $bandKey = $price > 0 ? (int) floor(log($price, 1.25)) : 0;   // memo on ~25 % price steps
        $key = "$categoryId|$bandKey|$excludeSellerId|{$today->toDateString()}";
        if (array_key_exists($key, $this->memo)) return $this->memo[$key];

        $prior = $price > 0 ? $this->compute($categoryId, $price * $lo, $price * $hi, $excludeSellerId, $today, 'price_band') : null;
        $prior ??= $this->compute($categoryId, null, null, $excludeSellerId, $today, 'category');

        return $this->memo[$key] = $prior;
    }

    private function compute(int $categoryId, ?float $min, ?float $max, int $excludeSellerId, CarbonImmutable $today, string $scope): ?array
    {
        $cfg    = config('forecast.prior');
        $window = $cfg['window_days'];
        $from   = $today->subDays($window);

        $peers = DB::table('products')
            ->where('category_id', $categoryId)
            ->where('seller_id', '!=', $excludeSellerId)
            ->where('is_active', true)->where('is_approved', true)->whereNull('deleted_at')
            ->where('created_at', '<=', $today->subDays(14)->utc())
            ->when($min !== null, fn($q) => $q->whereBetween('price', [$min, $max]))
            ->select('id', 'seller_id', 'created_at')->get();

        if ($peers->count() < $cfg['min_products'] || $peers->pluck('seller_id')->unique()->count() < $cfg['min_sellers']) {
            return null;
        }

        $sales = DB::table('order_items as oi')
            ->join('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->whereIn('so.status', config('forecast.sale_statuses'))
            ->whereIn('oi.product_id', $peers->pluck('id'))
            ->where('so.created_at', '>=', $from->utc())
            ->where('so.created_at', '<', $today->utc())
            ->selectRaw('oi.product_id, SUM(oi.quantity) as units, COUNT(DISTINCT so.id) as orders')
            ->groupBy('oi.product_id')->get()->keyBy('product_id');

        $orders = (int) $sales->sum('orders');
        if ($orders < $cfg['min_orders']) return null;

        $rates = [];
        foreach ($peers as $p) {
            $listed = min($window, max(1, (int) CarbonImmutable::parse($p->created_at, 'UTC')->diffInDays($today)));
            $rates[] = (float) ($sales[$p->id]->units ?? 0) / $listed;
        }

        return [
            'rates'     => $rates,
            'products'  => $peers->count(),
            'sellers'   => $peers->pluck('seller_id')->unique()->count(),
            'orders'    => $orders,
            'scope'     => $scope,
            'cart_rate' => $this->cartRate($peers->pluck('id')->all(), $today),
        ];
    }

    /** Add-to-cart per view over 30 days for the peer group (null under 200 views). */
    private function cartRate(array $productIds, CarbonImmutable $today): ?float
    {
        $row = DB::table('user_interactions')
            ->whereIn('product_id', $productIds)
            ->whereIn('event_type', ['view', 'cart_add'])
            ->where('created_at', '>=', $today->subDays(30)->utc())
            ->selectRaw("SUM(event_type = 'view') as views, SUM(event_type = 'cart_add') as carts")
            ->first();
        $views = (int) ($row->views ?? 0);
        return $views >= 200 ? round((int) $row->carts / $views, 4) : null;
    }
}
