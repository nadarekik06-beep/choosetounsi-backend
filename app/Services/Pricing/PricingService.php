<?php

namespace App\Services\Pricing;

use App\Models\Pack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Services\Delivery\DeliverySettings;
use App\Services\PromotionService;
use App\Support\Millimes;
use App\Support\ParcelSize;
use Illuminate\Support\Facades\DB;

/**
 * "Free delivery everywhere": the only place that knows a delivery fee is
 * included in the price the client sees.
 *
 *   display price = seller's price (after its promotion) + admin "Client fee" of the product's parcel size
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

    /** Sizes resolved for rows that came without parcel_size (one query per batch). */
    private array $sizes = [];

    public function __construct(private DeliverySettings $delivery) {}

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

    public function displayM(int $baseM, ?string $size): int
    {
        return $baseM + $this->feeM($size);
    }

    /** Seller price → price the client sees (JSON payloads only). */
    public function display($base, ?string $size): float
    {
        return Millimes::toFloat($this->displayM(Millimes::of($base), $size));
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
    public function toClient(array $block, ?string $size): array
    {
        $originalM = $this->displayM(Millimes::of($block['original_price']), $size);
        $finalM    = $this->displayM(Millimes::of($block['final_price']), $size);
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
            'free_delivery'    => true,
        ];
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
        $base = $this->promotions()->priceMany($list);

        $out = [];
        foreach ($list as $p) {
            $out[(int) $p->id] = $this->toClient($base[(int) $p->id], $this->sizeOf($p));
        }
        return $out;
    }

    /** Client block with the promotion already resolved (null = none). */
    public function priceWith(object $product, ?float $basePrice, ?int $variantId, ?Promotion $promotion): array
    {
        return $this->toClient($this->promotions()->priceWith($product, $basePrice, $variantId, $promotion), $this->sizeOf($product));
    }

    /** Client block for one product, optionally one variant's price. */
    public function getEffectivePrice(Product $product, ?float $basePrice = null, ?int $variantId = null): array
    {
        return $this->toClient($this->promotions()->getEffectivePrice($product, $basePrice, $variantId), $this->sizeOf($product));
    }

    /** Client block for a product line: the variant's own price when it has one. */
    public function priceLine(Product $product, ?ProductVariant $variant = null): array
    {
        return $this->toClient($this->promotions()->priceLine($product, $variant), $this->sizeOf($product));
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
     * The price the client pays for a product (base price after promotion + the
     * included fee of its size) as a SQL expression: storefront filters and
     * sorting. Same rules as priceMany(); tests/Feature/Pricing checks both agree.
     *
     * @return array{0: string, 1: array} [sql, bindings]
     */
    public function displayPriceSql(string $table = 'products'): array
    {
        [$sql, $bindings] = $this->promotions()->finalPriceSql($table);
        $fees = $this->fees();
        $case = "(CASE {$table}.parcel_size WHEN 'XL' THEN ? WHEN 'L' THEN ? ELSE ? END)";

        return ["({$sql}) + {$case}", [
            ...$bindings,
            Millimes::toDecimal($fees['XL']), Millimes::toDecimal($fees['L']), Millimes::toDecimal($fees['S']),
        ]];
    }

    // ── Packs (one unit of the largest item, per seller) ───────────────────

    /**
     * Included fee of ONE pack: the fee of its largest item, once per seller
     * shipping part of it (a pack of one seller = one fee).
     */
    public function packFeeM(Pack $pack): int
    {
        $bySeller = [];
        foreach ($pack->items as $item) {
            if (!$item->product) continue;
            $bySeller[$item->product->seller_id ?? 0][] = $this->sizeOf($item->product);
        }
        $fee = 0;
        foreach ($bySeller as $sizes) {
            $fee += $this->feeM(ParcelSize::largest($sizes));
        }
        return $fee ?: $this->feeM(ParcelSize::DEFAULT);
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

        return [
            'pack_price'       => Millimes::toFloat($priceM),
            'original_price'   => Millimes::toFloat($originalM),
            'savings'          => Millimes::toFloat($originalM - $priceM),
            'discount_percent' => self::badgePercent($originalM, $priceM),
            'free_delivery'    => true,
        ];
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
        $discount = 0;
        foreach ($shops as $sellerId => $shop) {
            $fees      = $this->parcelFees($shop['units']);
            $discount += $fees['multi_item_discount_m'];
            $out[] = [
                'seller_id'           => $sellerId ?: null,
                'seller_name'         => $sellerId ? ($names[(int) $sellerId] ?? '') : "CHOOSE'Tounsi",
                'item_ids'            => array_values(array_unique($shop['item_ids'])),
                'units'               => $fees['units'],
                'multi_item_discount' => Millimes::toFloat($fees['multi_item_discount_m']),
                'upsell_min_saving'   => $fees['units'] === 1 ? Millimes::toFloat($this->upsellMinSavingM()) : null,
            ];
        }

        return [
            'shops'               => $out,
            'subtotal'            => Millimes::toFloat($subtotal),
            'multi_item_discount' => Millimes::toFloat($discount),
            'free_delivery'       => true,
            'total'               => Millimes::toFloat($subtotal - $discount),
        ];
    }

    /** Smallest saving a second item of the same shop brings: the S fee (upsell hint). */
    public function upsellMinSavingM(): int
    {
        return $this->feeM('S');
    }
}
