<?php

namespace App\Services\Pricing;

use App\Models\Pack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Services\Delivery\DeliveryMode;
use App\Services\Delivery\DeliverySettings;
use App\Services\PromotionService;
use App\Support\Millimes;
use App\Support\ParcelSize;
use Illuminate\Support\Facades\DB;

/**
 * The only place that knows whether a delivery fee is inside the price the
 * client sees, according to the seller's mode (App\Services\Delivery\DeliveryMode):
 *
 *   included  display price = seller's price (after its promotion) + admin "Client fee" of the product's parcel size
 *   separate  display price = seller's price (after its promotion); the block carries
 *             free_delivery false and delivery_charge = the fee of its size, shown apart
 *             ("+ X DT livraison") and charged once per parcel at checkout
 *
 * The fee is read live from DeliverySettings (cached, flushed when the admin
 * saves) and never copied onto a product: changing a size's fee re-prices every
 * product of that size at once. Orders freeze their own figures at checkout.
 *
 * PromotionService keeps pricing the SELLER's (base) price: checkout money,
 * commission, coupons, seller and admin screens read it. Storefront payloads
 * read this service, whose blocks hold display prices only (no base price, no fee).
 *
 * Discounts apply to the base price only; the "-X %" badge comes from the two
 * display prices, rounded down.
 *
 * One seller = one parcel. Per parcel (parcelFees()):
 *   included_fees_total  Σ units × fee of the unit's size (a pack = one unit of its largest item)
 *   parcel_fee           fee of the parcel's size (its largest unit)
 *   multi_item_discount  included_fees_total − parcel_fee (0 for a single unit)
 */
class PricingService
{
    /** seller_orders.pricing_model of orders placed with delivery included in the prices. */
    public const MODEL_INCLUDED = 'included';

    /** … and of parcels whose delivery is charged apart: every screen shows their delivery line. */
    public const MODEL_SEPARATE = 'separate';

    /** Sizes resolved for rows that came without parcel_size (one query per batch). */
    private array $sizes = [];

    /** Sellers resolved for rows that came without seller_id (one query per batch). */
    private array $sellers = [];

    public function __construct(private DeliverySettings $delivery, private DeliveryMode $modes) {}

    private function promotions(): PromotionService
    {
        return app(PromotionService::class);
    }

    // ── Fees ──────────────────────────────────────────────────────────────

    /** Included delivery fee of a parcel size, in millimes (admin "Client fee"). */
    public function feeM(?string $size): int
    {
        return $this->delivery->clientFee($size);
    }

    /** @return array<string, int> size => included fee in millimes */
    public function fees(): array
    {
        $out = [];
        foreach (ParcelSize::ALL as $size) {
            $out[$size] = $this->feeM($size);
        }
        return $out;
    }

    /** Is this product's delivery charged apart (its seller is in separate mode)? */
    public function isSeparate(object $product): bool
    {
        // No separate seller at all: nothing to look up
        return $this->modes->separateSellerIds() !== [] && $this->modes->isSeparate($this->sellerOf($product));
    }

    /** The fee inside this product's displayed price: 0 in separate mode. */
    public function includedFeeM(object $product): int
    {
        return $this->isSeparate($product) ? 0 : $this->feeM($this->sizeOf($product));
    }

    /** Seller price → price the client sees, in millimes. */
    public function displayM(int $baseM, object $product): int
    {
        return $baseM + $this->includedFeeM($product);
    }

    /** Seller price → price the client sees (JSON payloads only). */
    public function display($base, object $product): float
    {
        return Millimes::toFloat($this->displayM(Millimes::of($base), $product));
    }

    /** "-X %" between two prices the client sees, rounded down. */
    public static function badgePercent(int $oldM, int $newM): int
    {
        return $oldM > 0 && $newM < $oldM ? intdiv(($oldM - $newM) * 100, $oldM) : 0;
    }

    // ── Storefront price blocks (same shape as PromotionService's) ─────────

    /**
     * A PromotionService block (seller prices) as the client sees it.
     * `price` is the display price before any promotion.
     */
    public function toClient(array $block, object $product): array
    {
        $originalM = $this->displayM(Millimes::of($block['original_price']), $product);
        $finalM    = $this->displayM(Millimes::of($block['final_price']), $product);
        $separate  = $this->isSeparate($product);
        $promotion = $block['promotion'] ?? null;
        $percent   = $promotion ? self::badgePercent($originalM, $finalM) : 0;

        if ($promotion) {
            // What the client sees of the promotion: the real "-X %", never the seller's own rate
            $promotion['discount_type']  = 'percentage';
            $promotion['discount_value'] = (float) $percent;
            $promotion['discount_label'] = __('messages.discount.percent_off', ['value' => $percent]);
            unset($promotion['funded_by']);
        }

        return [
            'price'            => Millimes::toFloat($originalM),
            'original_price'   => Millimes::toFloat($originalM),
            'final_price'      => Millimes::toFloat($finalM),
            'effective_price'  => Millimes::toFloat($finalM),
            'discount_amount'  => Millimes::toFloat($originalM - $finalM),
            'discount_percent' => $percent,
            'promo_type'       => $block['promo_type'] ?? null,
            'promo_label'      => $block['promo_label'] ?? null,
            'ends_at'          => $block['ends_at'] ?? null,
            'promotion'        => $promotion,
            'free_delivery'    => !$separate,
        ] + ($separate ? ['delivery_charge' => Millimes::toFloat($this->feeM($this->sizeOf($product)))] : []);
    }

    /**
     * Client blocks for a list of products (base price, no variant).
     *
     * @param  iterable $products models or DB rows with `id` and `price` (`parcel_size` looked up when absent)
     * @return array<int, array> product id => client block
     */
    public function priceMany(iterable $products): array
    {
        $list = [];
        foreach ($products as $p) {
            $list[] = $p;
        }
        $this->resolveSizes($list);
        if ($this->modes->separateSellerIds() !== []) {
            $this->resolveSellers($list);
        }
        $base = $this->promotions()->priceMany($list);

        $out = [];
        foreach ($list as $p) {
            $out[(int) $p->id] = $this->toClient($base[(int) $p->id], $p);
        }
        return $out;
    }

    /** Client block with the promotion already resolved (null = none). */
    public function priceWith(object $product, ?float $basePrice, ?int $variantId, ?Promotion $promotion): array
    {
        return $this->toClient($this->promotions()->priceWith($product, $basePrice, $variantId, $promotion), $product);
    }

    /** Client block for one product, optionally one variant's price. */
    public function getEffectivePrice(Product $product, ?float $basePrice = null, ?int $variantId = null): array
    {
        return $this->toClient($this->promotions()->getEffectivePrice($product, $basePrice, $variantId), $product);
    }

    /** Client block for a product line: the variant's own price when it has one. */
    public function priceLine(Product $product, ?ProductVariant $variant = null): array
    {
        return $this->toClient($this->promotions()->priceLine($product, $variant), $product);
    }

    /**
     * Copy a client block onto a product model or row for a storefront payload:
     * `price` becomes the display price too. Models are left clean on `price`, so
     * a payload model is never saved with a display price.
     */
    public function attach(object $product, array $pricing): object
    {
        foreach ($pricing as $key => $value) {
            $product->{$key} = $value;
        }
        if ($product instanceof \Illuminate\Database\Eloquent\Model) {
            $product->syncOriginalAttribute('price');
            // Never tell the storefront how a display price is built
            $product->makeHidden(['delivery_fee', 'parcel_size', 'parcel_size_confirmed_at']);
        } else {
            unset($product->delivery_fee, $product->parcel_size);
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

    /** The product's parcel size (S when unknown). */
    public function sizeOf(object $product): string
    {
        $size = $product instanceof \Illuminate\Database\Eloquent\Model
            ? ($product->getAttributes()['parcel_size'] ?? null)
            : ($product->parcel_size ?? null);
        if ($size === null && isset($product->id)) {
            $this->resolveSizes([$product]);
            $size = $this->sizes[(int) $product->id] ?? null;
        }
        return ParcelSize::normalize($size);
    }

    /** The product's seller (null = the platform's own product). */
    private function sellerOf(object $product): ?int
    {
        $model = $product instanceof \Illuminate\Database\Eloquent\Model;
        $has   = $model ? array_key_exists('seller_id', $product->getAttributes()) : property_exists($product, 'seller_id');
        if ($has) {
            $id = $model ? $product->getAttributes()['seller_id'] : $product->seller_id;
            return $id !== null ? (int) $id : null;
        }
        if (!isset($product->id)) {
            return null;
        }
        $this->resolveSellers([$product]);
        $id = $this->sellers[(int) $product->id] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /** One query for every row of the list that carries no seller_id. */
    private function resolveSellers(array $products): void
    {
        $missing = [];
        foreach ($products as $p) {
            $has = $p instanceof \Illuminate\Database\Eloquent\Model
                ? array_key_exists('seller_id', $p->getAttributes())
                : property_exists($p, 'seller_id');
            if (!$has && isset($p->id) && !array_key_exists((int) $p->id, $this->sellers)) {
                $missing[] = (int) $p->id;
            }
        }
        if ($missing) {
            $this->sellers += DB::table('products')->whereIn('id', array_unique($missing))->pluck('seller_id', 'id')->all();
        }
    }

    /** One query for every row of the list that carries no parcel_size. */
    private function resolveSizes(array $products): void
    {
        $missing = [];
        foreach ($products as $p) {
            $has = $p instanceof \Illuminate\Database\Eloquent\Model
                ? array_key_exists('parcel_size', $p->getAttributes())
                : property_exists($p, 'parcel_size');
            if (!$has && isset($p->id) && !isset($this->sizes[(int) $p->id])) {
                $missing[] = (int) $p->id;
            }
        }
        if ($missing) {
            $this->sizes += DB::table('products')->whereIn('id', array_unique($missing))->pluck('parcel_size', 'id')->all();
        }
    }

    /**
     * The price the client sees for a product (base price after promotion, plus
     * the fee of its size when its seller includes it) as a SQL expression:
     * storefront filters and sorting. Same rules as priceMany(); tests check both agree.
     *
     * @return array{0: string, 1: array} [sql, bindings]
     */
    public function displayPriceSql(string $table = 'products'): array
    {
        [$sql, $bindings] = $this->promotions()->finalPriceSql($table);
        $fees = $this->fees();
        $case = "(CASE {$table}.parcel_size WHEN 'XL' THEN ? WHEN 'L' THEN ? ELSE ? END)";

        $feeBindings = [Millimes::toDecimal($fees['XL']), Millimes::toDecimal($fees['L']), Millimes::toDecimal($fees['S'])];

        if ($separate = $this->modes->separateSellerIds()) {
            $marks = implode(',', array_fill(0, count($separate), '?'));
            $case  = "(CASE WHEN {$table}.seller_id IN ({$marks}) THEN 0 ELSE {$case} END)";
            $feeBindings = [...$separate, ...$feeBindings];
        }

        return ["({$sql}) + {$case}", [...$bindings, ...$feeBindings]];
    }

    // ── Packs (one unit of the largest item, per seller) ───────────────────

    /**
     * Included fee of ONE pack: the fee of its largest item, once per seller
     * shipping part of it (a pack of one seller = one fee). A separate-mode
     * seller's part includes nothing: its fee is in separatePackFeeM().
     */
    public function packFeeM(Pack $pack): int
    {
        $bySeller = $this->packSizes($pack);
        if (!$bySeller) {
            return $this->feeM(ParcelSize::DEFAULT);
        }
        $fee = 0;
        foreach ($bySeller as $sellerId => $sizes) {
            if (!$this->modes->isSeparate($sellerId ?: null)) {
                $fee += $this->feeM(ParcelSize::largest($sizes));
            }
        }
        return $fee;
    }

    /** Delivery charged apart for ONE pack (0 when no seller of it is in separate mode). */
    public function separatePackFeeM(Pack $pack): int
    {
        $fee = 0;
        foreach ($this->packSizes($pack) as $sellerId => $sizes) {
            if ($this->modes->isSeparate($sellerId ?: null)) {
                $fee += $this->feeM(ParcelSize::largest($sizes));
            }
        }
        return $fee;
    }

    private function isSeparatePack(Pack $pack): bool
    {
        foreach (array_keys($this->packSizes($pack)) as $sellerId) {
            if ($this->modes->isSeparate($sellerId ?: null)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, string[]> seller id (0 = platform) => the sizes of its items in the pack */
    private function packSizes(Pack $pack): array
    {
        $bySeller = [];
        foreach ($pack->items as $item) {
            if (!$item->product) continue;
            $bySeller[$item->product->seller_id ?? 0][] = $this->sizeOf($item->product);
        }
        return $bySeller;
    }

    /**
     * A pack's prices as the client sees them: pack price + one fee, the
     * crossed-out value of its items + the same fee.
     *
     * @param  mixed $price the pack price to show (default: its current price)
     * @return array{pack_price: float, original_price: float, savings: float, discount_percent: int, free_delivery: bool}
     */
    public function packClient(Pack $pack, $price = null): array
    {
        $feeM      = $this->packFeeM($pack);
        $priceM    = Millimes::of($price ?? $pack->pack_price) + $feeM;
        $originalM = max($priceM, Millimes::of($pack->currentItemsValue()) + $feeM);

        $separate = $this->isSeparatePack($pack);

        return [
            'pack_price'       => Millimes::toFloat($priceM),
            'original_price'   => Millimes::toFloat($originalM),
            'savings'          => Millimes::toFloat($originalM - $priceM),
            'discount_percent' => self::badgePercent($originalM, $priceM),
            'free_delivery'    => !$separate,
        ] + ($separate ? ['delivery_charge' => Millimes::toFloat($this->separatePackFeeM($pack))] : []);
    }

    // ── Parcels ───────────────────────────────────────────────────────────

    /**
     * Included delivery of one parcel (one seller's part of an order).
     *
     * @param  array<int, array{size: string, quantity: int}> $units product lines (quantity units each)
     *         and packs (quantity = number of packs, size = their largest item)
     * @param  array<string, int>|null $rates size => fee in millimes (default: the live admin fees;
     *         returns pass the fees frozen on the order)
     * @return array{parcel_size: string, units: int, included_fees_total_m: int, parcel_fee_m: int, multi_item_discount_m: int}
     */
    public function parcelFees(array $units, ?array $rates = null): array
    {
        $rates ??= $this->fees();
        $count = $total = 0;
        $sizes = [];
        foreach ($units as $u) {
            $qty = max(0, (int) $u['quantity']);
            if ($qty === 0) continue;
            $size    = ParcelSize::normalize($u['size']);
            $sizes[] = $size;
            $count  += $qty;
            $total  += $qty * (int) ($rates[$size] ?? 0);
        }
        $size      = ParcelSize::largest($sizes);
        $parcelFee = $count > 0 ? (int) ($rates[$size] ?? 0) : 0;

        return [
            'parcel_size'           => $size,
            'units'                 => $count,
            'included_fees_total_m' => $total,
            'parcel_fee_m'          => $parcelFee,
            'multi_item_discount_m' => max(0, $total - $parcelFee),
        ];
    }

    /**
     * A parcel placed with delivery included, recomputed from the units it still
     * holds, "as if the returned items had never been ordered" (returns):
     * with the per-size fees frozen at its checkout.
     *
     *   fee_delta_m = parcel fee before − parcel fee now: the delivery part of the
     *   refund (0 while the largest item stays; the whole fee on a full return).
     *
     * $minus simulates removing units (order_item_id => quantity) without writing.
     * $persist writes the new figures on the lines and the parcel (call it once
     * the returned lines and the parcel's base amounts are already reversed).
     * Null for a parcel placed before delivery was included: nothing to recompute.
     *
     * @param  array<int, int> $minus
     * @return array{units: int, parcel_fee_m: int, included_fees_total_m: int, multi_item_discount_m: int, fee_delta_m: int}|null
     */
    public function recomputeParcel(int $sellerOrderId, array $minus = [], bool $persist = false): ?array
    {
        $so = DB::table('seller_orders')->where('id', $sellerOrderId)->first();
        if (!$so || ($so->pricing_model ?? null) !== self::MODEL_INCLUDED) {
            return null;
        }
        $rates = [];
        foreach ((array) json_decode((string) ($so->fee_rates ?? ''), true) as $size => $fee) {
            $rates[$size] = Millimes::of($fee);
        }
        $rates += $this->fees();

        $lines = DB::table('order_items')->where('seller_order_id', $sellerOrderId)->orderBy('id')
            ->get(['id', 'quantity', 'parcel_size', 'included_fee', 'pack_group', 'pack_units', 'total']);

        $units = $lineFees = $groups = [];
        foreach ($lines as $line) {
            $qty  = max(0, (int) $line->quantity - (int) ($minus[$line->id] ?? 0));
            $size = ParcelSize::normalize($line->parcel_size);
            if ($line->pack_group !== null && $line->included_fee === null) {
                $groups[$line->pack_group][] = ['id' => (int) $line->id, 'qty' => $qty, 'size' => $size,
                    'weight' => max(0, Millimes::of($line->total)), 'packs' => max(1, (int) $line->pack_units)];
                continue;
            }
            $lineFees[(int) $line->id] = Millimes::of($line->included_fee) * $qty;
            $units[] = ['size' => $size, 'quantity' => $qty];
        }
        // A pack stays ONE unit (of its largest remaining item) while any of its items is kept
        foreach ($groups as $group) {
            $live = array_values(array_filter($group, fn ($l) => $l['qty'] > 0));
            foreach ($group as $l) {
                $lineFees[$l['id']] = 0;
            }
            if (!$live) continue;
            $size  = ParcelSize::largest(array_column($live, 'size'));
            $packs = max(array_column($live, 'packs'));
            $total = (int) ($rates[$size] ?? 0) * $packs;
            $sum   = array_sum(array_column($live, 'weight')) ?: count($live);
            $given = 0;
            foreach ($live as $k => $l) {
                $share = $k === count($live) - 1 ? $total - $given
                    : Millimes::share($total, array_sum(array_column($live, 'weight')) ? $l['weight'] : 1, $sum);
                $lineFees[$l['id']] = $share;
                $given += $share;
            }
            $units[] = ['size' => $size, 'quantity' => $packs];
        }

        $fees   = $this->parcelFees($units, $rates);
        $deltaM = max(0, Millimes::of($so->delivery_fee) - $fees['parcel_fee_m']);
        $out    = [
            'units'                 => $fees['units'],
            'parcel_fee_m'          => $fees['parcel_fee_m'],
            'included_fees_total_m' => $fees['included_fees_total_m'],
            'multi_item_discount_m' => $fees['multi_item_discount_m'],
            'fee_delta_m'           => $deltaM,
        ];
        if (!$persist) {
            return $out;
        }

        foreach ($lineFees as $id => $fee) {
            DB::table('order_items')->where('id', $id)->update(['included_fee_total' => Millimes::toDecimal($fee)]);
        }
        $dec = fn (int $m) => Millimes::toDecimal($m);
        DB::table('seller_orders')->where('id', $sellerOrderId)->update([
            'included_fees_total'      => $dec($fees['included_fees_total_m']),
            'parcel_fee'               => $dec($fees['parcel_fee_m']),
            'multi_item_discount'      => $dec($fees['multi_item_discount_m']),
            // What the client is still charged for delivery; Intigo's cost (shipping_cost) was paid and stays
            'delivery_fee'             => $dec($fees['parcel_fee_m']),
            'client_total'             => $dec(Millimes::of($so->subtotal) - Millimes::of($so->discount_amount) + $fees['parcel_fee_m']),
            'platform_delivery_margin' => $dec(Millimes::of($so->platform_delivery_margin) - $deltaM),
            'platform_profit'          => $dec(Millimes::of($so->platform_profit) - $deltaM),
            'updated_at'               => now(),
        ]);
        return $out;
    }

    /**
     * The cart as the client sees it, per shop (one shop = one parcel): what
     * ordering several items from the same shop saves, or the hint to add one.
     * Same figures as the checkout quote, but never throws (a row with a stock
     * problem still shows): the order itself is priced by OrderPricing.
     *
     * @param  iterable<\App\Models\Cart> $rows with product / variant / pack.items.product loaded
     * @return array{shops: array, subtotal: float, multi_item_discount: float, total: float}
     */
    public function cartSummary(iterable $rows): array
    {
        $shops    = [];
        $subtotal = 0;
        foreach ($rows as $row) {
            $qty = max(1, (int) $row->quantity);
            if ($row->isPack()) {
                if (!$row->pack) continue;
                $bySeller = [];
                foreach ($row->pack->items as $item) {
                    if ($item->product) {
                        $bySeller[$item->product->seller_id ?? 0][] = $this->sizeOf($item->product);
                    }
                }
                foreach ($bySeller as $sellerId => $sizes) {
                    $shops[$sellerId]['units'][]    = ['size' => ParcelSize::largest($sizes), 'quantity' => $qty];
                    $shops[$sellerId]['item_ids'][] = (int) $row->id;
                }
                $subtotal += (Millimes::of($row->pack->pack_price) + $this->packFeeM($row->pack)) * $qty;
                continue;
            }
            if (!$row->product) continue;
            $sellerId = $row->product->seller_id ?? 0;
            $shops[$sellerId]['units'][]    = ['size' => $this->sizeOf($row->product), 'quantity' => $qty];
            $shops[$sellerId]['item_ids'][] = (int) $row->id;
            $subtotal += Millimes::of($this->priceLine($row->product, $row->variant)['final_price']) * $qty;
        }

        $names    = \App\Services\ShopOverview::storeNames(array_filter(array_keys($shops)));
        $out      = [];
        $discount = $delivery = 0;
        $anySeparate = false;
        foreach (array_keys($shops) as $sellerId) {
            $anySeparate = $anySeparate || $this->modes->isSeparate($sellerId ?: null);
        }
        foreach ($shops as $sellerId => $shop) {
            $separate = $this->modes->isSeparate($sellerId ?: null);
            // Separate: nothing is inside the prices, so nothing to give back; one fee apart
            $fees      = $this->parcelFees($shop['units'], $separate ? array_fill_keys(ParcelSize::ALL, 0) : null);
            $charge    = $separate ? $this->feeM($fees['parcel_size']) : 0;
            $discount += $fees['multi_item_discount_m'];
            $delivery += $charge;
            $out[] = [
                'seller_id'           => $sellerId ?: null,
                'seller_name'         => $sellerId ? ($names[(int) $sellerId] ?? '') : "CHOOSE'Tounsi",
                'item_ids'            => array_values(array_unique($shop['item_ids'])),
                'units'               => $fees['units'],
                'multi_item_discount' => Millimes::toFloat($fees['multi_item_discount_m']),
                'upsell_min_saving'   => !$separate && $fees['units'] === 1 ? Millimes::toFloat($this->upsellMinSavingM()) : null,
            ] + ($anySeparate ? ['free_delivery' => !$separate, 'delivery_fee' => Millimes::toFloat($charge)] : []);
        }

        return [
            'shops'               => $out,
            'subtotal'            => Millimes::toFloat($subtotal),
            'multi_item_discount' => Millimes::toFloat($discount),
            'free_delivery'       => !$anySeparate,
            'total'               => Millimes::toFloat($subtotal - $discount + $delivery),
        ] + ($anySeparate ? ['delivery_fee' => Millimes::toFloat($delivery)] : []);
    }

    /** Smallest saving a second item of the same shop brings: the S fee (upsell hint). */
    public function upsellMinSavingM(): int
    {
        return $this->feeM('S');
    }
}
