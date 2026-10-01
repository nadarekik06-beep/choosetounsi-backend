<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The one place product prices meet promotions. Every storefront payload, the
 * cart, checkout, the chatbot and the admin review price through here.
 *
 * Active promotion: status active|scheduled (dates decide, never the cron),
 * starts_at <= now < ends_at, flash quota not used up. Dates are stored and
 * compared in UTC, the same instant as Africa/Tunis wall-clock time.
 *
 * When several apply: flash sale first, then higher `priority`, then newest.
 *
 * Pricing block (see pricing()):
 *   original_price   crossed-out price: lowest price of the last 30 days when discounted
 *   final_price      what the customer pays (effective_price is the same number, kept for old callers)
 *   discount_amount, discount_percent
 *   promo_type       flash_sale | promotion | null
 *   promo_label      the promotion's name
 *   ends_at          ISO-8601, for countdowns
 *   promotion        full promotion object (formatPromotion) or null
 */
class PromotionService
{
    // ── Resolution ────────────────────────────────────────────────────────

    /**
     * Active promotion per product, in one query.
     *
     * @param  int[] $productIds
     * @return array<int, Promotion> product id => winning promotion (missing = none)
     */
    public function activePromotionsFor(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids) return [];

        $promotions = Promotion::active()
            ->join('promotion_products as pp', 'pp.promotion_id', '=', 'promotions.id')
            ->whereIn('pp.product_id', $ids)
            ->orderByRaw("CASE WHEN promotions.type = 'flash_sale' THEN 0 ELSE 1 END")
            ->orderByDesc('promotions.priority')
            ->orderByDesc('promotions.created_at')
            ->orderByDesc('promotions.id')
            ->get(['promotions.*', 'pp.product_id as priced_product_id']);

        $out = [];
        foreach ($promotions as $promo) {
            $out[(int) $promo->priced_product_id] ??= $promo;
        }
        return $out;
    }

    public function getActivePromotionForProduct(int $productId): ?Promotion
    {
        return $this->activePromotionsFor([$productId])[$productId] ?? null;
    }

    // ── Pricing ───────────────────────────────────────────────────────────

    /**
     * Pricing blocks for a list of products (base price, no variant): one promotion
     * query and two price-history queries whatever the list size.
     *
     * @param  iterable $products models or DB rows with `id` and `price`
     * @return array<int, array> product id => pricing block
     */
    public function priceMany(iterable $products): array
    {
        $prices = [];
        foreach ($products as $p) {
            $prices[(int) $p->id] = (float) $p->price;
        }
        if (!$prices) return [];

        $promos     = $this->activePromotionsFor(array_keys($prices));
        $references = PriceHistory::lowestMany(array_intersect_key($prices, $promos));

        $out = [];
        foreach ($prices as $id => $price) {
            $promo    = $promos[$id] ?? null;
            $out[$id] = $this->pricing($price, $promo ? $references[$id] : $price, $promo);
        }
        return $out;
    }

    /**
     * Pricing block for one product, optionally one variant's price
     * (cart, checkout, detail page variants).
     */
    public function getEffectivePrice(Product $product, ?float $basePrice = null, ?int $variantId = null): array
    {
        return $this->priceWith($product, $basePrice, $variantId, $this->getActivePromotionForProduct($product->id));
    }

    /** Pricing block for a product line: the variant's own price when it has one. */
    public function priceLine(Product $product, ?ProductVariant $variant = null): array
    {
        return $this->getEffectivePrice($product, self::basePrice($product, $variant), $variant?->id);
    }

    /**
     * getEffectivePrice() with the promotion already resolved (null = none),
     * for lists that batch activePromotionsFor(). $product: model or DB row with id + price.
     */
    public function priceWith(object $product, ?float $basePrice, ?int $variantId, ?Promotion $promotion): array
    {
        $currentPrice = $basePrice ?? (float) $product->price;

        // Anti fake-discount: the crossed-out price is the lowest price of the
        // last 30 days, so raising the price before a promotion gains nothing.
        $reference = $promotion ? $this->referencePrice($product, $currentPrice, $variantId) : $currentPrice;

        return $this->pricing($currentPrice, $reference, $promotion);
    }

    /** Pre-promotion unit price: the variant's override, else the product price. */
    public static function basePrice(Product $product, ?ProductVariant $variant = null): float
    {
        return (float) ($variant?->price_override ?? $product->price);
    }

    /**
     * Copy a pricing block onto a product model or row, for list payloads.
     * `price` itself is left untouched (filters and sorting read it).
     */
    public function attach(object $product, array $pricing): object
    {
        foreach ($pricing as $key => $value) {
            $product->{$key} = $value;
        }
        return $product;
    }

    /** priceMany() + attach() on every item. */
    public function attachMany(iterable $products): void
    {
        $pricing = $this->priceMany($products);
        foreach ($products as $p) {
            $this->attach($p, $pricing[(int) $p->id]);
        }
    }

    /**
     * Price a discount is computed from and shown crossed out: the lowest price
     * of the last 30 days for this product (or this variant), capped at today's.
     */
    public function referencePrice(object $product, float $currentPrice, ?int $variantId = null): float
    {
        // A variant price passed without its id can't be matched to a timeline
        if ($variantId === null && abs($currentPrice - (float) $product->price) > 0.0005) {
            return $currentPrice;
        }
        return PriceHistory::lowest($product->id, $variantId, $currentPrice);
    }

    private function pricing(float $currentPrice, float $reference, ?Promotion $promo): array
    {
        if (!$promo) {
            return [
                'original_price'   => round($currentPrice, 3),
                'final_price'      => round($currentPrice, 3),
                'effective_price'  => round($currentPrice, 3),
                'discount_amount'  => 0.0,
                'discount_percent' => 0,
                'promo_type'       => null,
                'promo_label'      => null,
                'ends_at'          => null,
                'promotion'        => null,
            ];
        }

        $final  = round(max(0, $this->applyDiscount($reference, $promo)), 3);
        $amount = round($reference - $final, 3);

        return [
            'original_price'   => round($reference, 3),
            'final_price'      => $final,
            'effective_price'  => $final,
            'discount_amount'  => $amount,
            'discount_percent' => $reference > 0 ? (int) round($amount / $reference * 100) : 0,
            'promo_type'       => $promo->type === 'flash_sale' ? 'flash_sale' : 'promotion',
            'promo_label'      => $promo->name,
            'ends_at'          => $this->endsAt($promo)->toISOString(),
            'promotion'        => $this->formatPromotion($promo),
        ];
    }

    private function applyDiscount(float $price, Promotion $promo): float
    {
        if ($promo->discount_type === 'percentage') {
            return $price * (1 - ((float) $promo->discount_value / 100));
        }
        return $price - (float) $promo->discount_value;
    }

    private function endsAt(Promotion $promo): Carbon
    {
        return $promo->ends_at instanceof Carbon ? $promo->ends_at : Carbon::parse($promo->ends_at);
    }

    /**
     * Format promotion for API response — frontend-ready.
     */
    public function formatPromotion(Promotion $promo): array
    {
        return [
            'id'                    => $promo->id,
            'type'                  => $promo->type,
            'name'                  => $promo->name,
            'discount_type'         => $promo->discount_type,
            'discount_value'        => (float) $promo->discount_value,
            'discount_label'        => $promo->discount_type === 'percentage'
                                          ? __('messages.discount.percent_off', ['value' => (int) $promo->discount_value])
                                          : __('messages.discount.amount_off', ['value' => number_format($promo->discount_value, 3)]),
            'ends_at'               => $this->endsAt($promo)->toISOString(),
            'flash_stock_remaining' => $promo->flashStockRemaining(),
            'is_flash_sale'         => $promo->type === 'flash_sale',
        ];
    }

    // ── Flash-sale quota ──────────────────────────────────────────────────

    /**
     * Take `$qty` units from a flash sale's quota. Atomic: fails when fewer than
     * `$qty` units are left. Promotions without a quota always succeed.
     */
    public function reserveFlashStock(int $promotionId, int $qty): bool
    {
        return DB::table('promotions')
            ->where('id', $promotionId)
            ->where(fn ($q) => $q->whereNull('flash_stock')
                ->orWhereRaw('flash_stock_used + ? <= flash_stock', [$qty]))
            ->update(['flash_stock_used' => DB::raw('flash_stock_used + ' . (int) $qty)]) === 1;
    }

    /** Give units back to a flash sale's quota (order cancelled, payment failed). */
    public function releaseFlashStock(int $promotionId, int $qty): void
    {
        DB::table('promotions')
            ->where('id', $promotionId)
            ->whereNotNull('flash_stock')
            ->update(['flash_stock_used' => DB::raw('GREATEST(0, flash_stock_used - ' . (int) $qty . ')')]);
    }

    /**
     * Hold quota for an order line priced with $pricing (priceLine/getEffectivePrice).
     * Returns the units held (0 when the line's promotion has no quota), or null
     * when the flash sale is sold out — the caller must not sell at that price.
     */
    public function reserveForLine(array $pricing, int $qty): ?int
    {
        $promo = $pricing['promotion'] ?? null;
        if (!$promo || !$promo['is_flash_sale'] || $promo['flash_stock_remaining'] === null) {
            return 0;
        }
        return $this->reserveFlashStock($promo['id'], $qty) ? $qty : null;
    }

    /** Give back the quota held by an order's lines (card payment failed). */
    public function releaseForOrder(int $orderId): int
    {
        return $this->releaseOrderItems(fn ($q) => $q->where('order_id', $orderId));
    }

    /** Give back the quota held by these seller orders' lines (cancelled). */
    public function releaseForSellerOrders(array $sellerOrderIds): int
    {
        return $sellerOrderIds
            ? $this->releaseOrderItems(fn ($q) => $q->whereIn('seller_order_id', $sellerOrderIds))
            : 0;
    }

    /** Safety net (promotions:sync): every cancelled seller order still holding quota. */
    public function releaseCancelled(): int
    {
        return $this->releaseOrderItems(fn ($q) => $q->whereIn('seller_order_id',
            DB::table('seller_orders')->where('status', 'cancelled')->select('id')));
    }

    /**
     * A card payment that failed (quota released) later succeeded: the customer
     * pays the flash price, so the units count against the quota again.
     */
    public function reclaimForOrder(int $orderId): void
    {
        $lines = DB::table('order_items as oi')
            ->join('promotions as pr', 'pr.id', '=', 'oi.promotion_id')
            ->where('oi.order_id', $orderId)
            ->where('oi.flash_reserved', 0)
            ->where('pr.type', 'flash_sale')
            ->whereNotNull('pr.flash_stock')
            ->get(['oi.id', 'oi.promotion_id', 'oi.quantity']);

        foreach ($lines as $line) {
            DB::table('promotions')->where('id', $line->promotion_id)
                ->update(['flash_stock_used' => DB::raw('flash_stock_used + ' . (int) $line->quantity)]);
            DB::table('order_items')->where('id', $line->id)->update(['flash_reserved' => $line->quantity]);
        }
    }

    /** Release each line once: flash_reserved goes back to 0 in the same transaction. */
    private function releaseOrderItems(\Closure $scope): int
    {
        return DB::transaction(function () use ($scope) {
            $lines = DB::table('order_items')
                ->where('flash_reserved', '>', 0)
                ->whereNotNull('promotion_id')
                ->where($scope)
                ->lockForUpdate()
                ->get(['id', 'promotion_id', 'flash_reserved']);

            $released = 0;
            foreach ($lines as $line) {
                DB::table('order_items')->where('id', $line->id)->update(['flash_reserved' => 0]);
                $this->releaseFlashStock($line->promotion_id, $line->flash_reserved);
                $released += $line->flash_reserved;
            }
            return $released;
        });
    }

    // ── Public promotion listings (/deals, seller storefront) ─────────────

    /**
     * Active promotions (flash_sale + discount) with their products, formatted
     * for public API responses. Shared by /flash-sales, /discounts, and the
     * seller storefront endpoint.
     *
     * A product is listed under a promotion only when that promotion is the one
     * it is actually priced with, so /deals never shows a price the cart won't charge.
     *
     * @param \Closure|null $filter Optional extra constraint on the Promotion query,
     *                              e.g. fn($q) => $q->where('type', 'flash_sale')
     *                              or   fn($q) => $q->where('seller_id', $id)
     */
    public function getActivePromotionsFormatted(?\Closure $filter = null): Collection
    {
        $query = Promotion::active()
            ->with([
                'products' => fn ($q) => $q
                    ->where('is_approved', true)
                    ->where('is_active', true)
                    ->with(['primaryImage', 'seller:id,name']),
            ]);

        if ($filter) {
            $filter($query);
        }

        $promotions = $query
            ->orderByDesc('priority')
            ->orderBy('ends_at')
            ->get();

        $allProducts = $promotions->flatMap(fn ($promo) => $promo->products)->unique('id')->values();
        $pricing     = $this->priceMany($allProducts);
        $winners     = $this->activePromotionsFor($allProducts->pluck('id')->all());

        $allColorImages = ProductImage::whereIn('product_id', $allProducts->pluck('id'))
            ->whereNotNull('color_option_id')
            ->select('product_id', 'image_path')
            ->get()
            ->groupBy('product_id');

        return $promotions->map(function ($promo) use ($allColorImages, $pricing, $winners) {
            $products = $promo->products
                ->filter(fn ($product) => $product->stock > 0
                    && ($winners[$product->id]->id ?? null) === $promo->id)
                ->map(function ($product) use ($allColorImages, $pricing) {
                    $variantImages = [];
                    foreach ($allColorImages->get($product->id, collect()) as $img) {
                        $url = Storage::url($img->image_path);
                        if (!in_array($url, $variantImages, true)) {
                            $variantImages[] = $url;
                        }
                    }

                    return [
                        'id'                => $product->id,
                        'name'              => $product->name,
                        'slug'              => $product->slug,
                        'price'             => (float) $product->price,
                        'primary_image_url' => $product->primary_image_url,
                        'variant_images'    => $variantImages,
                        'stock'             => $product->stock,
                        'seller'            => $product->seller
                            ? ['name' => $product->seller->name]
                            : null,
                    ] + $pricing[$product->id];
                })->values();

            if ($products->isEmpty()) return null;

            return [
                'id'                    => $promo->id,
                'name'                  => $promo->name,
                'type'                  => $promo->type,
                'discount_type'         => $promo->discount_type,
                'discount_value'        => (float) $promo->discount_value,
                'discount_label'        => $promo->discount_type === 'percentage'
                                             ? (int) $promo->discount_value . '% OFF'
                                             : number_format($promo->discount_value, 3) . ' DT OFF',
                'ends_at'               => $promo->ends_at->toISOString(),
                'flash_stock'           => $promo->flash_stock,
                'flash_stock_remaining' => $promo->flashStockRemaining(),
                'products'              => $products,
            ];
        })
        ->filter()
        ->values();
    }

    // ── Validation ────────────────────────────────────────────────────────

    /**
     * Validate a promotion payload against business rules.
     * Returns array of error messages (empty = valid).
     */
    public function validate(array $data, string $type): array
    {
        $errors = [];
        $starts = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $ends   = isset($data['ends_at'])   ? Carbon::parse($data['ends_at'])   : null;

        if ($starts && $ends) {
            $durationHours = $starts->diffInHours($ends);

            if ($type === 'flash_sale') {
                if ($durationHours < 1)  $errors[] = 'Flash sale must last at least 1 hour.';
                if ($durationHours > 72) $errors[] = 'Flash sale cannot exceed 72 hours.';
            } else {
                $durationDays = $starts->diffInDays($ends);
                if ($durationDays < 1)  $errors[] = 'Discount must last at least 1 day.';
                if ($durationDays > 90) $errors[] = 'Discount cannot exceed 90 days.';
            }
        }

        if (isset($data['discount_type'], $data['discount_value'])) {
            $maxDiscount = $type === 'flash_sale' ? 90 : 70;
            if ($data['discount_type'] === 'percentage' && $data['discount_value'] > $maxDiscount) {
                $errors[] = "Max discount for {$type} is {$maxDiscount}%.";
            }
            if ($data['discount_value'] <= 0) {
                $errors[] = 'Discount value must be greater than 0.';
            }
        }

        return $errors;
    }
}
