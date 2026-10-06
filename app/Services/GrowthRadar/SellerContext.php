<?php

namespace App\Services\GrowthRadar;

use App\Services\Forecast\SalesSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything the detectors know about one seller, loaded once per run.
 *
 * Sales, views, carts and favourites come from the forecast's daily series
 * (forecast_daily_sales: real sales only, net of discounts and returns),
 * rebuilt here so every plan gets fresh numbers.
 */
class SellerContext
{
    /** @var array<int, array> live products keyed by id (see load()) */
    public array $products = [];
    /** Product ids already covered by a card (one product card per product). */
    public array $claimed = [];

    public function __construct(
        public readonly int $sellerId,
        public readonly CarbonImmutable $today,
        public readonly string $tier,
        public readonly Learning $learning,
    ) {}

    public static function load(int $sellerId, CarbonImmutable $today, string $tier, Learning $learning): self
    {
        $ctx = new self($sellerId, $today, $tier, $learning);
        $series = app(SalesSeries::class);
        $series->rebuild($sellerId, $today);
        $all = $series->load($sellerId);

        $ids = array_keys($all);
        $meta = $ids ? DB::table('products')->whereIn('id', $ids)
            ->select('id', 'subcategory_id', 'is_pack', 'description', 'slug')
            ->get()->keyBy('id') : collect();
        $images = $ids ? DB::table('product_images')->whereIn('product_id', $ids)
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) as n')->pluck('n', 'product_id') : collect();
        $activePromo = $ids ? DB::table('promotion_products as pp')->join('promotions as pr', 'pr.id', '=', 'pp.promotion_id')
            ->whereIn('pp.product_id', $ids)->whereIn('pr.status', ['active', 'scheduled'])
            ->where('pr.ends_at', '>', now())->pluck('pp.product_id')->flip() : collect();
        $activeBoost = $ids ? DB::table('sponsorships')->whereIn('product_id', $ids)
            ->where('status', 'active')->pluck('product_id')->flip() : collect();

        $w = (int) config('growth.window_days');
        foreach ($all as $pid => $p) {
            if (!$p['live']) continue;
            $m = $meta[$pid] ?? null;
            if ($m && $m->is_pack) continue;
            $cur  = self::sum($p['daily'], $today->subDays($w), $today);
            $prev = self::sum($p['daily'], $today->subDays(2 * $w), $today->subDays($w));
            $lastSale = null;
            foreach ($p['daily'] as $day => $r) {
                if ($r['units'] > 0) $lastSale = $day;   // ordered by day
            }
            $ctx->products[$pid] = $p + [
                'subcategory_id' => $m?->subcategory_id ? (int) $m->subcategory_id : null,
                'slug'           => $m?->slug,
                'description_len'=> mb_strlen(trim(strip_tags((string) $m?->description))),
                'images'         => (int) ($images[$pid] ?? 0),
                'cur'            => $cur,
                'prev'           => $prev,
                'last_sale'      => $lastSale,
                'listed_days'    => (int) CarbonImmutable::parse($p['listed_since'], $today->tz)->diffInDays($today),
                'promo_running'  => isset($activePromo[$pid]),
                'boost_running'  => isset($activeBoost[$pid]),
            ];
        }
        return $ctx;
    }

    /** Totals of the daily series in [from, to). */
    public static function sum(array $daily, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = ['views' => 0, 'cart_adds' => 0, 'favorites' => 0, 'units' => 0, 'orders' => 0, 'net_revenue' => 0.0, 'promo_days' => 0, 'days' => 0];
        $f = $from->toDateString();
        $t = $to->toDateString();
        foreach ($daily as $day => $r) {
            if ($day < $f || $day >= $t) continue;
            foreach (['views', 'cart_adds', 'favorites', 'units', 'orders', 'net_revenue'] as $k) $out[$k] += $r[$k];
            if ($r['promo_day']) $out['promo_days']++;
        }
        $out['days'] = (int) $from->diffInDays($to);
        return $out;
    }

    /** Daily views / orders for a sparkline over the last $days days (oldest first). */
    public function sparkline(int $productId, int $days = 30): array
    {
        $daily = $this->products[$productId]['daily'] ?? [];
        $views = $orders = [];
        for ($i = $days; $i >= 1; $i--) {
            $d = $this->today->subDays($i)->toDateString();
            $views[]  = (int) ($daily[$d]['views'] ?? 0);
            $orders[] = (int) ($daily[$d]['orders'] ?? 0);
        }
        return ['views' => $views, 'orders' => $orders];
    }

    public function categoryIds(): array
    {
        return array_values(array_unique(array_column($this->products, 'category_id')));
    }

    public function totals(string $window = 'cur'): array
    {
        $t = ['views' => 0, 'cart_adds' => 0, 'favorites' => 0, 'units' => 0, 'orders' => 0, 'net_revenue' => 0.0];
        foreach ($this->products as $p) {
            foreach ($t as $k => $_) $t[$k] += $p[$window][$k];
        }
        return $t;
    }

    /** Average price the seller actually sells at (window revenue / units), else list price. */
    public function unitRevenue(array $p): float
    {
        $units = $p['cur']['units'] + $p['prev']['units'];
        return $units > 0 ? ($p['cur']['net_revenue'] + $p['prev']['net_revenue']) / $units : (float) $p['price'];
    }

    public function claim(int $productId): void
    {
        $this->claimed[$productId] = true;
    }

    public function isClaimed(int $productId): bool
    {
        return isset($this->claimed[$productId]);
    }
}
