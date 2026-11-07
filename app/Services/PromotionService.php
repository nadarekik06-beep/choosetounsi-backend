<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Carbon\Carbon;
use App\Support\Millimes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The one place product prices meet promotions. Every storefront payload, the
 * cart, checkout, the chatbot and the admin review price through here.
 *
 * Active promotion: status active|scheduled (dates decide, never the cron),
 * starts_at <= now < ends_at, flash quota not used up. Dates are stored and
 * compared in UTC (Tunis is UTC+1; forms send Tunis time, see App\Support\TunisTime).
 *
 * When several apply: flash sale first, then higher `priority`, then newest.
 *
 * Base price (single source of truth): the product's CURRENT price, or the
 * variant's own price_override, read from the database at pricing time. A
 * percentage promotion is applied to it at read time, so changing the price
 * re-prices every active promotion at once; nothing is snapshotted on the
 * promotion. Orders freeze their own prices at checkout (order_items).
 *
 * Pricing block (see pricing()):
 *   original_price   crossed-out price: the current base price
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
     * query whatever the list size.
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

        $promos = $this->activePromotionsFor(array_keys($prices));

        $out = [];
        foreach ($prices as $id => $price) {
            $out[$id] = $this->pricing($price, $promos[$id] ?? null);
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
        // Always the current price (product, or the variant's own): never an older one
        return $this->pricing($basePrice ?? (float) $product->price, $promotion);
    }

    /** Pre-promotion unit price: the variant's override, else the product price. */
    public static function basePrice(Product $product, ?ProductVariant $variant = null): float
    {
        return (float) ($variant?->price_override ?? $product->price);
    }

    /**
     * The product's final price (base price, no variant) as a SQL expression, for
     * filtering and sorting paginated lists in the database. Same rules as
     * priceMany(): winning promotion (flash first, priority, newest; live
     * status, inside its window, quota left), discount applied to the current
     * price. tests/Feature/Promotions checks both agree.
     *
     * @return array{0: string, 1: array} [sql, bindings]
     */
    public function finalPriceSql(string $table = 'products'): array
    {
        $now      = now();
        $statuses = "'" . implode("','", Promotion::PRICING_STATUSES) . "'";
        $base     = "ROUND({$table}.price, 3)";

        // A promotion that would bring the price to 0 is ignored (same rule as pricing())
        $sql = "COALESCE(NULLIF((SELECT ROUND(GREATEST(0, CASE WHEN pr.discount_type = 'percentage'
                        THEN {$base} * (1 - pr.discount_value / 100)
                        ELSE {$base} - pr.discount_value END), 3)
                FROM promotions pr
                JOIN promotion_products pp ON pp.promotion_id = pr.id
                WHERE pp.product_id = {$table}.id
                  AND pr.status IN ({$statuses})
                  AND pr.starts_at <= ? AND pr.ends_at > ?
                  AND (pr.flash_stock IS NULL OR pr.flash_stock_used < pr.flash_stock)
                ORDER BY (pr.type = 'flash_sale') DESC, pr.priority DESC, pr.created_at DESC, pr.id DESC
                LIMIT 1), 0), {$table}.price)";

        return [$sql, [$now, $now]];
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

    private function pricing(float $currentPrice, ?Promotion $promo): array
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

        // Integer millimes (AUDIT L-1): same result as finalPriceSql() to the millime
        $refM   = Millimes::of($currentPrice);
        $finalM = self::discountedMillimes($refM, $promo);
        if ($finalM <= 0) {
            // Never sell for free: a promotion that would is ignored. Creating one
            // (zeroPriceProducts) or lowering a price under it (fixedDiscountConflicts) is refused.
            return $this->pricing($currentPrice, null);
        }
        $final  = Millimes::toFloat($finalM);
        $amount = Millimes::toFloat($refM - $finalM);

        return [
            'original_price'   => Millimes::toFloat($refM),
            'final_price'      => $final,
            'effective_price'  => $final,
            'discount_amount'  => $amount,
            'discount_percent' => self::percentOff($promo, Millimes::toFloat($refM), $amount),
            'promo_type'       => $promo->type === 'flash_sale' ? 'flash_sale' : 'promotion',
            'promo_label'      => $promo->name,
            'ends_at'          => $this->endsAt($promo)->toISOString(),
            'promotion'        => $this->formatPromotion($promo),
        ];
    }

    /**
     * The "-X%" every screen shows. A percentage promotion shows its own rate
     * (rounded), so the product card, detail page, cart and checkout always agree
     * whatever the price or variant; a fixed amount is converted from the real prices.
     */
    public static function percentOff(Promotion $promo, float $reference, float $amount): int
    {
        if ($promo->discount_type === 'percentage') {
            return (int) round((float) $promo->discount_value);
        }
        return $reference > 0 ? (int) round($amount / $reference * 100) : 0;
    }

    /**
     * Final price in millimes, never below 0: reference × (1 − rate) rounded
     * half-up once (the rate kept to the thousandth of a percent, as stored), or
     * reference − fixed amount. finalPriceSql() is the same formula in MySQL.
     */
    public static function discountedMillimes(int $referenceM, Promotion $promo): int
    {
        if ($promo->discount_type === 'percentage') {
            $rate = Millimes::of($promo->discount_value); // percent × 1000
            return max(0, Millimes::divRound($referenceM * (100000 - $rate), 100000));
        }
        return max(0, $referenceM - Millimes::of($promo->discount_value));
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
            'source'                => $promo->created_by ?? Promotion::SOURCE_SELLER,
            // Who pays the discount (admin promotions may be platform-funded) and the flash limit per customer
            'funded_by'             => $promo->isPlatformFunded() ? Promotion::FUNDED_PLATFORM : Promotion::FUNDED_SELLER,
            'per_customer_limit'    => $promo->type === 'flash_sale' ? $promo->per_customer_limit : null,
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
     *
     * With $productName (checkout), the flash sale's per-customer limit is checked
     * first under a lock on the promotion row, so two orders of the same customer
     * placed at the same time can't both pass (AUDIT L-4).
     *
     * @throws \App\Exceptions\FlashCustomerLimit
     */
    public function reserveForLine(array $pricing, int $qty, ?int $userId = null, ?string $phone = null, ?string $productName = null): ?int
    {
        $promo = $pricing['promotion'] ?? null;
        if ($productName !== null) {
            $this->assertFlashCustomerLimit($pricing, $qty, $userId, $phone, $productName, true);
        }
        if (!$promo || !$promo['is_flash_sale'] || $promo['flash_stock_remaining'] === null) {
            return 0;
        }
        return $this->reserveFlashStock($promo['id'], $qty) ? $qty : null;
    }

    /**
     * Flash-priced units this customer already holds on orders still in play
     * (cancelled / refused parcels don't count). Account: by user id. Guest: by
     * delivery phone, on every order (guest or account) delivered to it.
     * $lock: shared locking read inside the checkout transaction, so rows other
     * orders committed a moment ago are counted.
     */
    public function flashUnitsBoughtBy(int $promotionId, ?int $userId, ?string $phone, bool $lock = false): int
    {
        if ($userId === null && !$phone) {
            return 0;
        }
        $q = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('seller_orders as so', 'so.id', '=', 'oi.seller_order_id')
            ->where('oi.promotion_id', $promotionId)
            ->where(fn ($w) => $w->whereNull('so.id')->orWhereNotIn('so.status', \App\Models\SellerOrder::NOT_SHIPPED))
            ->where(fn ($w) => $userId !== null
                ? $w->where('o.user_id', $userId)
                : $w->where('o.phone', $phone)->orWhere('o.guest_phone', $phone));
        if ($lock) {
            $q->sharedLock();
        }
        return (int) $q->sum('oi.quantity');
    }

    /**
     * Refuse more than the flash sale's per-customer limit at the flash price.
     * $lock: inside the checkout transaction (locks the promotion row first).
     *
     * @throws \App\Exceptions\FlashCustomerLimit
     */
    public function assertFlashCustomerLimit(array $pricing, int $qty, ?int $userId, ?string $phone, string $productName, bool $lock = false): void
    {
        $promo = $pricing['promotion'] ?? null;
        $limit = $promo['per_customer_limit'] ?? null;
        if (!$promo || empty($promo['is_flash_sale']) || !$limit) {
            return;
        }
        if ($lock) {
            DB::table('promotions')->where('id', $promo['id'])->lockForUpdate()->first(['id']);
        }
        $bought = $this->flashUnitsBoughtBy((int) $promo['id'], $userId, $phone, $lock);
        if ($bought + $qty > $limit) {
            throw new \App\Exceptions\FlashCustomerLimit($productName, (int) $limit, $bought);
        }
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

    /** Give back the quota held by these order lines (returned to the seller). */
    public function releaseForOrderItems(array $orderItemIds): int
    {
        return $orderItemIds
            ? $this->releaseOrderItems(fn ($q) => $q->whereIn('id', $orderItemIds))
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

    /**
     * A cancelled seller order re-opened: its flash-priced lines take their
     * quota back. The customer keeps the flash price, so a sold-out quota
     * rejects the re-open (FlashSaleSoldOut) instead of over-selling it.
     * Call inside the re-open transaction: a failure rolls everything back.
     *
     * @throws \App\Exceptions\FlashSaleSoldOut
     */
    public function reclaimForSellerOrders(array $sellerOrderIds): int
    {
        if (!$sellerOrderIds) return 0;

        $lines = DB::table('order_items as oi')
            ->join('promotions as pr', 'pr.id', '=', 'oi.promotion_id')
            ->whereIn('oi.seller_order_id', $sellerOrderIds)
            ->where('oi.flash_reserved', 0)
            ->where('oi.quantity', '>', 0)
            ->where('pr.type', 'flash_sale')
            ->whereNotNull('pr.flash_stock')
            ->orderBy('oi.id')
            ->lockForUpdate()
            ->get(['oi.id', 'oi.promotion_id', 'oi.quantity', 'oi.product_name']);

        $held = 0;
        foreach ($lines as $line) {
            $qty = (int) $line->quantity;
            $ok  = DB::table('promotions')->where('id', $line->promotion_id)
                ->whereRaw('flash_stock - flash_stock_used >= ?', [$qty])
                ->update(['flash_stock_used' => DB::raw('flash_stock_used + ' . $qty)]);
            if (!$ok) {
                throw new \App\Exceptions\FlashSaleSoldOut((string) $line->product_name);
            }
            DB::table('order_items')->where('id', $line->id)->update(['flash_reserved' => $qty]);
            $held += $qty;
        }
        return $held;
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

        $cardImages = ProductCardImages::forIds($allProducts->pluck('id')->all());
        $variants   = ProductCardImages::variantsFor($allProducts->pluck('id')->all());
        $shops      = \App\Services\ShopOverview::shopsFor($allProducts->pluck('seller_id')->filter()->unique()->all());

        return $promotions->map(function ($promo) use ($allColorImages, $pricing, $winners, $cardImages, $variants, $shops) {
            $products = $promo->products
                ->filter(fn ($product) => $product->stock > 0
                    && ($winners[$product->id]->id ?? null) === $promo->id)
                ->map(function ($product) use ($allColorImages, $pricing, $cardImages, $variants, $shops) {
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
                        // Cards show the shop's name and pepper tier, not the account's personal name
                        'seller'            => $product->seller
                            ? ['id' => $product->seller->id, 'name' => $product->seller->name]
                              + array_intersect_key($shops[$product->seller_id] ?? [], array_flip(['business_name', 'plan', 'avatar']))
                            : null,
                    ] + $pricing[$product->id] + ($cardImages[$product->id] ?? ['card_images' => [], 'card_swatches' => []])
                      + ['variants' => $variants[$product->id] ?? []];
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

    /** Highest percentage an admin promotion may take (owner's decision 2026-11-05). */
    public const ADMIN_MAX_PERCENT = 99;

    /**
     * Validate a promotion payload against business rules.
     * Returns array of error messages (empty = valid).
     *
     * Sellers: durations 1 h-72 h (flash) / 1-90 days (discount), max 90% / 70%.
     * Admin ($source = 'admin'): any duration, max ADMIN_MAX_PERCENT.
     * Both: end after start, value > 0.
     */
    public function validate(array $data, string $type, string $source = Promotion::SOURCE_SELLER): array
    {
        $errors = [];
        $starts = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $ends   = isset($data['ends_at'])   ? Carbon::parse($data['ends_at'])   : null;

        if ($starts && $ends && $ends <= $starts) {
            $errors[] = 'The end date must be after the start date.';
        }

        if ($source === Promotion::SOURCE_ADMIN) {
            if (isset($data['discount_type'], $data['discount_value'])) {
                if ($data['discount_value'] <= 0) {
                    $errors[] = 'Discount value must be greater than 0.';
                } elseif ($data['discount_type'] === 'percentage' && $data['discount_value'] > self::ADMIN_MAX_PERCENT) {
                    $errors[] = 'Max discount is ' . self::ADMIN_MAX_PERCENT . '%.';
                }
            }
            return $errors;
        }

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

    /**
     * Products (or variants) this discount would bring to 0 DT or less, from
     * their CURRENT price (fresh from the database). Empty = every final price > 0.
     * A fixed amount must be lower than the current price of every line.
     *
     * @param  int[] $productIds
     * @return string[] labels with the current price: "Name (50.000 DT)" or "Name — variant (50.000 DT)"
     */
    public function zeroPriceProducts(array $productIds, string $discountType, float $value): array
    {
        $promo    = $this->draftPromotion($discountType, $value);
        $bad      = [];
        $products = Product::with(['variants' => fn ($q) => $q->where('is_active', true)])->whereIn('id', $productIds)->get();
        foreach ($products as $product) {
            foreach (self::priceLines($product) as [$variant, $price]) {
                if (self::discountedMillimes(Millimes::of($price), $promo) <= 0) {
                    $bad[] = self::lineLabel($product->name, $variant?->label, $price);
                }
            }
        }
        return $bad;
    }

    /**
     * Live or upcoming FIXED-amount promotions on this product that the new
     * prices would make free (amount ≥ price). Used before saving a price change
     * (seller and admin edit), so a promotion never silently stops applying.
     *
     * @param  float $newBase      new product price
     * @param  array<int, array{label: ?string, price: ?float}> $variantLines new variant prices
     *         (price null = follows the base price)
     * @return string[] readable conflicts, empty = the price change is fine
     */
    public function fixedDiscountConflicts(Product $product, float $newBase, array $variantLines = []): array
    {
        $promos = Promotion::query()
            ->whereIn('status', Promotion::PRICING_STATUSES)
            ->where('ends_at', '>', now())
            ->where('discount_type', 'fixed')
            ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
            ->get();
        if ($promos->isEmpty()) return [];

        $lines = [[null, $newBase]];
        foreach ($variantLines as $line) {
            $price = $line['price'] ?? null;
            if ($price !== null && abs((float) $price - $newBase) > 0.0005) {
                $lines[] = [$line['label'] ?? 'variant', (float) $price];
            }
        }

        $out = [];
        foreach ($promos as $promo) {
            foreach ($lines as [$label, $price]) {
                if (self::discountedMillimes(Millimes::of($price), $promo) <= 0) {
                    $out[] = __('messages.discount.fixed_above_price', [
                        'promotion' => $promo->name,
                        'amount'    => number_format((float) $promo->discount_value, 3, '.', ''),
                        'product'   => self::lineLabel($product->name, $label, $price),
                    ]);
                }
            }
        }
        return $out;
    }

    /**
     * [variant|null, current price] for the product's base price and each active
     * variant with its own price (variants without one follow the base price).
     *
     * @return array<int, array{0: ?ProductVariant, 1: float}>
     */
    private static function priceLines(Product $product): array
    {
        $lines = [[null, (float) $product->price]];
        foreach ($product->variants as $variant) {
            if ($variant->price_override !== null) {
                $lines[] = [$variant, (float) $variant->price_override];
            }
        }
        return $lines;
    }

    private static function lineLabel(string $name, ?string $variant, float $price): string
    {
        return ($variant ? "{$name} — {$variant}" : $name) . ' (' . number_format($price, 3, '.', '') . ' DT)';
    }

    /**
     * What a draft discount would charge, per product and per variant, with the
     * exact rules and rounding of checkout (admin form live preview).
     *
     * @param  int[] $productIds
     */
    public function preview(array $productIds, string $discountType, float $value): array
    {
        $promo    = $this->draftPromotion($discountType, $value);
        $products = Product::with(['variants' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('id', $productIds)->get();

        $row = function (Product $product, ?ProductVariant $variant) use ($promo): array {
            $base = self::basePrice($product, $variant);
            $p    = $this->pricing($base, $promo);
            return [
                'current_price'    => round($base, 3),
                'original_price'   => $p['original_price'],
                'final_price'      => $p['final_price'],
                'discount_amount'  => $p['discount_amount'],
                'discount_percent' => $p['discount_percent'],
                'valid'            => $p['promotion'] !== null,
            ];
        };

        return $products->map(fn (Product $product) => ['product_id' => $product->id, 'name' => $product->name]
            + $row($product, null)
            + ['variants' => $product->variants
                ->filter(fn ($v) => $v->price_override !== null)
                ->map(fn ($v) => ['variant_id' => $v->id, 'label' => $v->label] + $row($product, $v))
                ->values()->all()],
        )->values()->all();
    }

    /** An unsaved promotion, to price a draft with the real rules. */
    private function draftPromotion(string $discountType, float $value): Promotion
    {
        return new Promotion([
            'name' => 'preview', 'type' => 'discount', 'discount_type' => $discountType,
            'discount_value' => $value, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDay(),
        ]);
    }

    // ── One promotion per product ─────────────────────────────────────────

    /**
     * Live or upcoming promotions (active / scheduled, not ended) on any of these
     * products whose window overlaps [$startsAt, $endsAt). A product may only
     * have one promotion at a time, for sellers and admins alike. Paused ones
     * don't block; they are checked again when resumed.
     *
     * @param  int[] $productIds
     * @return Collection<int, Promotion> each with `products` limited to the overlapping ones
     */
    public function conflicts(array $productIds, $startsAt, $endsAt, ?int $ignoreId = null): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) return collect();

        return Promotion::query()
            ->whereIn('status', Promotion::PRICING_STATUSES)
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', Carbon::parse($endsAt))
            ->where('ends_at', '>', Carbon::parse($startsAt))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereHas('products', fn ($q) => $q->whereIn('products.id', $ids))
            ->with(['products' => fn ($q) => $q->whereIn('products.id', $ids)->select('products.id', 'products.name', 'products.seller_id')])
            ->orderBy('starts_at')
            ->get();
    }

    /** conflicts() for an API response (admin warning, seller error). */
    public function formatConflicts(Collection $conflicts): array
    {
        return $conflicts->map(fn (Promotion $p) => [
            'id'             => $p->id,
            'name'           => $p->name,
            'type'           => $p->type,
            'source'         => $p->created_by ?? Promotion::SOURCE_SELLER,
            'seller_id'      => $p->seller_id,
            'discount_type'  => $p->discount_type,
            'discount_value' => (float) $p->discount_value,
            'status'         => $p->displayStatus(),
            'starts_at'      => $p->starts_at?->toISOString(),
            'ends_at'        => $p->ends_at?->toISOString(),
            'products'       => $p->products->map(fn ($prod) => ['id' => $prod->id, 'name' => $prod->name])->values(),
        ])->values()->all();
    }

    /**
     * Admin chose "replace": the overlapping products leave the conflicting
     * promotions (which keep their other products); a promotion left with no
     * product ends. Past orders keep their promotion_id and prices.
     *
     * @return array<int, array{promotion: Promotion, product_ids: int[], ended: bool}>
     */
    public function removeProductsFromConflicts(Collection $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $promo) {
            $ids = $promo->products->pluck('id')->map(fn ($id) => (int) $id)->all();
            $promo->products()->detach($ids);
            \App\Support\PricingCache::bump();
            $ended = !$promo->products()->exists();
            if ($ended) {
                $promo->update(['status' => 'expired']);
            }
            $out[] = ['promotion' => $promo, 'product_ids' => $ids, 'ended' => $ended];
        }
        return $out;
    }
}

