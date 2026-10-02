<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Live data for the /shop landing sections that the product endpoints don't
 * already cover: catalogue numbers, category rail, pepper sellers, deals.
 * Everything is read from the database; nothing here is marketing copy.
 * Public endpoint, so each block is cached briefly.
 */
class ShopOverview
{
    const CACHE_MINUTES = 10;
    const DEALS_SECONDS = 60;
    const MAX_DEALS     = 12;

    public function __construct(private PromotionService $promotions) {}

    /** @return array{products: int, sellers: int, categories: int, average_rating: ?float, reviews: int, delivery_fee: float, free_delivery_products: int, complaint_window_hours: int} */
    public function stats(): array
    {
        return Cache::remember('shop:overview:stats', now()->addMinutes(self::CACHE_MINUTES), function () {
            $avg = Review::approved()->avg('rating');

            return [
                'products'       => Product::available()->count(),
                'sellers'        => User::approvedSellers()->where('is_active', true)
                    ->whereIn('id', Product::available()->select('seller_id'))->count(),
                'categories'     => Category::active()->whereHas('activeProducts')->count(),
                'average_rating' => $avg !== null ? round((float) $avg, 1) : null,
                'reviews'        => Review::approved()->count(),
                // Trust strip: what checkout really charges and allows
                'delivery_fee'           => Product::DEFAULT_DELIVERY_FEE,
                'free_delivery_products' => Product::available()->where('delivery_fee', 0)->count(),
                'complaint_window_hours' => (int) \App\Models\Complaint::COMPLAINT_WINDOW_HOURS,
            ];
        });
    }

    /**
     * Active categories that have something to sell, with the cover of their
     * most viewed product (categories rarely have an image of their own).
     */
    public function categories(): array
    {
        return Cache::remember('shop:overview:categories', now()->addMinutes(self::CACHE_MINUTES), function () {
            $categories = Category::active()->ordered()
                ->withCount('activeProducts as products_count')
                ->get(['id', 'name', 'name_fr', 'name_ar', 'slug', 'icon', 'image'])
                ->filter(fn ($c) => $c->products_count > 0);

            return $categories->map(function (Category $c) {
                $cover = $c->image ? Storage::url($c->image) : null;
                if (!$cover) {
                    $top = Product::available()->where('category_id', $c->id)
                        ->whereHas('primaryImage')->with('primaryImage')
                        ->orderByDesc('views')->first(['id']);
                    $cover = $top?->primaryImage ? Storage::url($top->primaryImage->image_path) : null;
                }
                return [
                    'id'             => $c->id,
                    'slug'           => $c->slug,
                    'name'           => $c->name,
                    'name_fr'        => $c->name_fr,
                    'name_ar'        => $c->name_ar,
                    'icon'           => $c->icon,
                    'cover'          => $cover,
                    'products_count' => (int) $c->products_count,
                ];
            })->values()->all();
        });
    }

    /**
     * Approved, active shops with at least one product on sale, grouped by
     * pepper tier on the client. Black first, then by rating and audience.
     */
    public function sellers(): array
    {
        return Cache::remember('shop:overview:sellers', now()->addMinutes(self::CACHE_MINUTES), function () {
            $products = Product::available()->groupBy('seller_id')
                ->selectRaw('seller_id, COUNT(*) as n')->pluck('n', 'seller_id');
            if ($products->isEmpty()) return [];

            $sellers = User::approvedSellers()->where('is_active', true)
                ->whereIn('id', $products->keys())
                ->get(['id', 'name', 'avatar']);

            $apps = DB::table('seller_applications')->whereIn('user_id', $sellers->pluck('id'))
                ->where('status', 'approved')->orderBy('id')
                ->get(['user_id', 'business_name', 'profile_picture', 'cover_photo', 'wilaya', 'plan'])
                ->keyBy('user_id');   // latest approved application wins
            $followers = DB::table('seller_follows')->whereIn('seller_id', $sellers->pluck('id'))
                ->groupBy('seller_id')->selectRaw('seller_id, COUNT(*) as n')->pluck('n', 'seller_id');
            $ratings = Review::approved()->whereIn('seller_id', $sellers->pluck('id'))
                ->groupBy('seller_id')
                ->get(['seller_id', DB::raw('AVG(rating) as avg'), DB::raw('COUNT(*) as n')])
                ->keyBy('seller_id');

            $tierRank = array_flip(SubscriptionPlan::TIER_KEYS);

            return $sellers->map(function (User $u) use ($apps, $products, $followers, $ratings) {
                $a = $apps[$u->id] ?? null;
                $r = $ratings[$u->id] ?? null;
                return [
                    'id'             => $u->id,
                    'business_name'  => $a->business_name ?? $u->name,
                    'avatar'         => !empty($a?->profile_picture) ? Storage::url($a->profile_picture) : $u->avatar,
                    'cover'          => !empty($a?->cover_photo) ? Storage::url($a->cover_photo) : null,
                    'wilaya'         => $a->wilaya ?? null,
                    'plan'           => SubscriptionPlan::forSlug($a->plan ?? null)->tierKey(),
                    'products_count' => (int) ($products[$u->id] ?? 0),
                    'followers'      => (int) ($followers[$u->id] ?? 0),
                    'rating'         => $r ? round((float) $r->avg, 1) : null,
                    'reviews'        => $r ? (int) $r->n : 0,
                ];
            })
            ->sort(fn ($x, $y) => [$tierRank[$y['plan']] ?? 0, $y['rating'] ?? 0, $y['followers'], $y['products_count']]
                              <=> [$tierRank[$x['plan']] ?? 0, $x['rating'] ?? 0, $x['followers'], $x['products_count']])
            ->values()->all();
        });
    }

    /**
     * Products under an active promotion, flash sales first, each with the
     * end date and (flash sales) the stock left so the page can show a timer
     * and a "sold" bar. One product appears once, under the promotion that prices it.
     */
    public function deals(): array
    {
        $key = 'shop:overview:deals:' . app()->getLocale();
        return Cache::remember($key, self::DEALS_SECONDS, function () {
            $promos = $this->promotions->getActivePromotionsFormatted()
                ->sortBy(fn ($p) => [$p['type'] === 'flash_sale' ? 0 : 1, $p['ends_at']])
                ->values();

            $out = [];
            foreach ($promos as $promo) {
                foreach ($promo['products'] as $product) {
                    if (isset($out[$product['id']])) continue;
                    $out[$product['id']] = $product + ['deal' => [
                        'promotion_id'    => $promo['id'],
                        'is_flash_sale'   => $promo['type'] === 'flash_sale',
                        'ends_at'         => $promo['ends_at'],
                        'flash_stock'     => $promo['flash_stock'],
                        'flash_remaining' => $promo['flash_stock_remaining'],
                    ]];
                    if (count($out) >= self::MAX_DEALS) break 2;
                }
            }

            $shops = self::shopsFor(array_filter(array_map(fn ($p) => $p['seller']['id'] ?? null, $out)));
            foreach ($out as &$p) {
                if (isset($p['seller']['id'], $shops[$p['seller']['id']])) {
                    $p['seller'] += $shops[$p['seller']['id']];
                }
            }
            unset($p);
            return array_values($out);
        });
    }

    /**
     * seller_id => [business_name, plan tier key] from the approved applications, one query.
     * Cards show the shop's name and pepper tier, never the account's personal name.
     */
    public static function shopsFor(array $sellerIds): array
    {
        if (!$sellerIds) return [];
        return DB::table('seller_applications')
            ->whereIn('user_id', array_values(array_unique($sellerIds)))
            ->where('status', 'approved')
            ->orderBy('id')
            ->get(['user_id', 'business_name', 'plan'])
            ->mapWithKeys(fn ($a) => [(int) $a->user_id => [
                'business_name' => $a->business_name,
                'plan'          => SubscriptionPlan::forSlug($a->plan)->tierKey(),
            ]])
            ->all();
    }
}
