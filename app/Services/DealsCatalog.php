<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Pack;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Everything on offer right now, for the /deals page and the /shop deals row:
 *   - products under an active promotion (flash sales first, then the soonest ending),
 *     each with its end date and, for flash sales, the stock left;
 *   - products covered by an active coupon (coupon codes are never exposed: the
 *     card only says a coupon exists, its value and its minimum order);
 *   - active, approved packs.
 * Every number comes from the database; the price block is PromotionService's,
 * the one the cart and checkout charge. Public data, cached briefly.
 */
class DealsCatalog
{
    const CACHE_SECONDS = 60;
    const MAX_PACKS     = 100;

    public function __construct(
        private PromotionService $promotions,
        private UserPreferenceService $preferences,
    ) {}

    /**
     * Product cards (same payload as the catalogue cards) with a `deal` block for
     * promotions and a `coupon` block for coupons. One product appears once.
     * $limit keeps only the first promotion products (no coupons) — the /shop row.
     */
    public function products(?int $limit = null): array
    {
        $key = 'deals:products:' . app()->getLocale() . ':' . ($limit ?? 'all');
        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->buildProducts($limit));
    }

    /** Pack cards: price, savings and percent, items (name + image), the shop. */
    public function packs(): array
    {
        return Cache::remember('deals:packs:' . app()->getLocale(), self::CACHE_SECONDS, function () {
            $packs = Pack::available()
                ->with(['seller:id,name', 'items' => fn ($q) => $q->orderBy('order')->with([
                    'product:id,name,slug,price,stock,category_id', 'product.primaryImage',
                ])])
                ->orderByDesc('created_at')
                ->limit(self::MAX_PACKS)
                ->get();

            $shops = ShopOverview::shopsFor($packs->pluck('seller_id')->filter()->all());

            return $packs->map(function (Pack $pack) use ($shops) {
                $original = (float) $pack->original_price;
                $savings  = (float) $pack->savings;
                // The pack's category: the one most of its items share
                $category = $pack->items->pluck('product.category_id')->filter()->countBy()->sortDesc()->keys()->first();
                return [
                    'id'                => $pack->id,
                    'name'              => $pack->name,
                    'slug'              => $pack->slug,
                    'short_description' => $pack->short_description,
                    'image_url'         => $pack->image_url,
                    'pack_price'        => (float) $pack->pack_price,
                    'original_price'    => $original,
                    'savings'           => $savings,
                    'discount_percent'  => $original > 0 && $savings > 0 ? (int) round($savings / $original * 100) : 0,
                    'items_count'       => $pack->items->count(),
                    'available_stock'   => $pack->available_stock,
                    'category_id'       => $category ? (int) $category : null,
                    'created_at'        => $pack->created_at?->toISOString(),
                    'seller'            => $pack->seller ? ['id' => $pack->seller->id, 'name' => $pack->seller->name]
                                                           + ($shops[$pack->seller->id] ?? []) : null,
                    'items'             => $pack->items->map(fn ($item) => [
                        'id'       => $item->id,
                        'quantity' => $item->quantity,
                        'product'  => $item->product ? [
                            'name'              => $item->product->name,
                            'primary_image_url' => $item->product->primary_image_url,
                        ] : null,
                    ])->values()->all(),
                ];
            })->values()->all();
        });
    }

    /**
     * Categories the shopper has shown interest in (onboarding choices, then views,
     * favourites, cart adds and purchases, most weighted first). Empty for guests
     * and for shoppers with no history.
     */
    public function interestCategoryIds(?User $user): array
    {
        if (!$user) return [];
        $prefs = $this->preferences->getCombinedPreferences($user->id);
        return array_values(array_map('intval', (array) ($prefs?->category_ids ?? [])));
    }

    // ── Builders ──────────────────────────────────────────────────────────

    private function buildProducts(?int $limit): array
    {
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
                if ($limit && count($out) >= $limit) break 2;
            }
        }

        if (!$limit) {
            $coupons = $this->bestCouponByProduct();
            $missing = array_diff(array_keys($coupons), array_keys($out));
            foreach ($this->plainCards($missing) as $id => $card) {
                $out[$id] = $card + ['deal' => null];
            }
            foreach ($out as $id => &$p) {
                $p['coupon'] = $coupons[$id] ?? null;
            }
            unset($p);
        }

        return $this->withCardExtras($out);
    }

    /**
     * product_id => the coupon worth the most on it: active, uses left. The code
     * stays private — the shopper gets it from the shop, the page only says it exists.
     */
    private function bestCouponByProduct(): array
    {
        $coupons = Coupon::active()
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'))
            ->with('products:id')
            ->get(['id', 'discount_type', 'discount_value', 'min_order_amount']);

        $best = [];
        foreach ($coupons as $c) {
            foreach ($c->products as $product) {
                $value = (float) $c->discount_value;
                $prev  = $best[$product->id] ?? null;
                // Percentages beat fixed amounts only on their own scale; keep the larger of the same type
                if ($prev && $prev['discount_type'] === $c->discount_type && $prev['discount_value'] >= $value) continue;
                if ($prev && $prev['discount_type'] === 'percentage' && $c->discount_type === 'fixed') continue;
                $best[$product->id] = [
                    'discount_type'    => $c->discount_type,
                    'discount_value'   => $value,
                    'min_order_amount' => $c->min_order_amount !== null ? (float) $c->min_order_amount : null,
                ];
            }
        }
        return $best;
    }

    /** Cards for in-stock, available products, in the getActivePromotionsFormatted() shape. */
    private function plainCards(array $ids): array
    {
        if (!$ids) return [];
        $products = Product::available()->whereIn('id', $ids)->where('stock', '>', 0)
            ->with(['primaryImage', 'seller:id,name'])
            ->orderByDesc('views')
            ->get();
        $pricing = $this->promotions->priceMany($products);
        $images  = ProductImage::whereIn('product_id', $products->pluck('id'))->whereNotNull('color_option_id')
            ->get(['product_id', 'image_path'])->groupBy('product_id');

        $out = [];
        foreach ($products as $product) {
            $variantImages = [];
            foreach ($images->get($product->id, collect()) as $img) {
                $url = Storage::url($img->image_path);
                if (!in_array($url, $variantImages, true)) $variantImages[] = $url;
            }
            $out[$product->id] = [
                'id'                => $product->id,
                'name'              => $product->name,
                'slug'              => $product->slug,
                'price'             => (float) $product->price,
                'primary_image_url' => $product->primary_image_url,
                'variant_images'    => $variantImages,
                'stock'             => $product->stock,
                'seller'            => $product->seller ? ['id' => $product->seller->id, 'name' => $product->seller->name] : null,
            ] + $pricing[$product->id];
        }
        return $out;
    }

    /** Same card extras as the catalogue: shop name + tier, rating, active variants (add to cart), category. */
    private function withCardExtras(array $out): array
    {
        if (!$out) return [];
        $ids      = array_keys($out);
        $shops    = ShopOverview::shopsFor(array_filter(array_map(fn ($p) => $p['seller']['id'] ?? null, $out)));
        $ratings  = DB::table('reviews')->whereIn('product_id', $ids)->where('status', 'approved')
            ->groupBy('product_id')->selectRaw('product_id, AVG(rating) as avg, COUNT(*) as n')->get()->keyBy('product_id');
        $variants = DB::table('product_variants')->whereIn('product_id', $ids)->where('is_active', true)
            ->orderBy('id')->get(['id', 'product_id', 'stock'])->groupBy('product_id');
        $rows     = DB::table('products')->whereIn('id', $ids)->get(['id', 'category_id', 'views', 'created_at'])->keyBy('id');
        $cats     = Category::whereIn('id', $rows->pluck('category_id')->filter()->unique())
            ->get(['id', 'name', 'name_fr', 'name_ar', 'slug'])->keyBy('id');

        foreach ($out as $id => &$p) {
            if (isset($p['seller']['id'], $shops[$p['seller']['id']])) {
                $p['seller'] += $shops[$p['seller']['id']];
            }
            $r   = $ratings[$id] ?? null;
            $row = $rows[$id] ?? null;
            $cat = $row?->category_id ? ($cats[$row->category_id] ?? null) : null;
            $p['avg_rating']    = $r ? round((float) $r->avg, 1) : null;
            $p['reviews_count'] = $r ? (int) $r->n : 0;
            $p['variants']      = ($variants[$id] ?? collect())->map(fn ($v) => ['id' => $v->id, 'stock' => (int) $v->stock])->values()->all();
            $p['category_id']   = $cat?->id;
            $p['category']      = $cat ? $cat->only(['id', 'name', 'name_fr', 'name_ar', 'slug']) : null;
            $p['views']         = (int) ($row->views ?? 0);
            $p['created_at']    = $row?->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->toISOString() : null;
        }
        unset($p);
        return array_values($out);
    }
}
