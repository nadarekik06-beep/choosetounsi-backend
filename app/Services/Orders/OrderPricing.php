<?php

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Pack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Exceptions\FlashCustomerLimit;
use App\Services\CommissionService;
use App\Services\CouponService;
use App\Services\Delivery\DeliverySettings;
use App\Services\Pricing\PricingService;
use App\Services\PromotionService;
use App\Support\Millimes;
use App\Support\ParcelSize;
use Illuminate\Support\Collection;

/**
 * The single source of truth for what an order costs (quote AND order):
 * the storefront only displays this, and checkout recomputes it when the
 * order is placed. All money is integer millimes (App\Support\Millimes).
 *
 * One discount per line, never stacked (AUDIT_DISCOUNTS §3):
 *   pack row      → the pack price, spread over its lines (nothing else applies)
 *   product row   → at most one promotion: flash sale > promotion (admin = seller)
 *   seller coupon → only that seller's coupon products WITHOUT a promotion; promoted
 *                   ones are skipped, the coupon is refused only if none remains
 *
 * Delivery is free for the client and included in the price they see ("free
 * delivery everywhere", App\Services\Pricing\PricingService): every unit's display
 * price = its base price + the admin client fee of its size, and a parcel with
 * several units gives the extra fees back as the multi-item discount. The money
 * is unchanged: the client pays Σ base prices − coupon + ONE parcel fee, which
 * is exactly Σ display prices − coupon − multi-item discount. Every figure
 * below stays a BASE (seller) amount; the display figures sit next to them.
 * Discounts, commission and the seller payout never touch the delivery part.
 *
 * One seller = one parcel = one pickup. Per parcel:
 *   items_subtotal  Σ line totals (post pack price / discount / flash sale)
 *   promo_savings   Σ (original − final unit price) × qty of promoted lines: shown to
 *                   the buyer only, the money above is already the final price
 *   coupon_discount the seller's coupon (one per seller, never on promoted lines or packs)
 *   parcel_size     the largest size of its items (S / L / XL, App\Support\ParcelSize);
 *                   every delivery amount below is the admin's figure for that size
 *   delivery_fee    the admin client fee for the size (the "parcel fee" the platform keeps)
 *   included_fees   Σ units × client fee of the unit's size (a pack = ONE unit of its largest item)
 *   multi_item_discount  included_fees − delivery_fee (0 for a single unit)
 *   display_subtotal     items_subtotal + included_fees (Σ of the prices the client saw)
 *   parcel_total    items_subtotal − coupon_discount + delivery_fee
 *                   = display_subtotal − coupon_discount − multi_item_discount
 *   cod_amount      parcel_total for cash on delivery (0 when prepaid)
 *   commission      Σ line commissions: bracket from the ORIGINAL unit price, rate on
 *                   the final amount (line − coupon share), never on delivery (AUDIT M-7)
 *   platform_funded Σ discounts of platform-funded admin promotions, paid back to the
 *                   seller (AUDIT M-8); commission is then what the platform keeps
 *   seller_payout   Σ line seller amounts (never anything about delivery)
 *   agency_cost     admin agency_delivery_cost (the courier keeps it from the cash)
 *   delivery_margin delivery_fee − agency_cost
 *   (seller-offered free delivery no longer exists: is_free / contribution are always false / 0)
 *
 * Always: parcel_total = seller_payout + commission + agency_cost + delivery_margin.
 *
 * Packs (AUDIT C-1): priced at the pack's CURRENT price × quantity. One pack's
 * price is spread over ALL its lines in proportion to each item's original
 * price (normal price × quantity in the pack), in millimes, remainder on the
 * last line: the lines sum exactly to the pack price. Each line keeps its own
 * share, so returns, refunds, commission and payouts work line by line.
 */
class OrderPricing
{
    public function __construct(
        private PromotionService  $promo,
        private CommissionService $commission,
        private CouponService     $coupons,
        private DeliverySettings  $delivery,
        private PricingService    $pricing,
    ) {}

    // ── Entry points ─────────────────────────────────────────────────────────

    /**
     * @param Collection<int, Cart> $rows cart rows (product and pack rows)
     * @param string[] $couponCodes
     * $userId null = guest checkout; $guestPhone = the guest's delivery phone
     * (coupon and flash-sale "per customer" limits).
     */
    public function forCart(Collection $rows, array $couponCodes, ?int $userId, string $paymentMethod = 'cod', ?string $guestPhone = null): array
    {
        if ($rows->isEmpty()) {
            throw new PricingException(__('messages.checkout.cart_empty'));
        }

        $lines = [];
        foreach ($rows as $row) {
            if ($row->isPack()) {
                array_push($lines, ...$this->packLines($row, $userId));
            } else {
                $lines[] = $this->productLine($row->product, $row->variant, (int) $row->quantity, $userId, 'c' . $row->id, $row->id, $row);
            }
        }
        $this->checkFlashLimits($lines, $userId, $guestPhone);

        return $this->build($lines, $this->resolveCodes($couponCodes), $userId, $paymentMethod, $guestPhone);
    }

    public function forBuyNow(Product $product, ?ProductVariant $variant, int $quantity, ?string $couponCode, ?int $userId, string $paymentMethod = 'cod', ?string $guestPhone = null): array
    {
        $line = $this->productLine($product, $variant, $quantity, $userId, 'buy_now', null);
        $this->checkFlashLimits([$line], $userId, $guestPhone);

        if ($couponCode !== null && $couponCode !== '' && $line['seller_id'] === null) {
            throw new PricingException(__('messages.checkout.no_coupon_platform'));
        }
        $codes = [];
        if ($couponCode !== null && $couponCode !== '') {
            if (!Coupon::where('code', strtoupper($couponCode))->exists()) {
                throw new PricingException(__('messages.coupon.invalid'));
            }
            $codes = $this->resolveCodes([$couponCode]);
        }

        return $this->build([$line], $codes, $userId, $paymentMethod, $guestPhone);
    }

    // ── Lines ────────────────────────────────────────────────────────────────

    /** $row: the cart row, to tell the buyer when the promotion it was added with has ended. */
    private function productLine(?Product $product, ?ProductVariant $variant, int $qty, ?int $userId, string $key, ?int $cartId, ?Cart $row = null): array
    {
        if (!$product || !$product->is_approved || !$product->is_active) {
            throw new PricingException($product
                ? __('messages.checkout.product_unavailable_named', ['product' => $product->name])
                : __('messages.checkout.product_unavailable'));
        }
        if ($variant && (!$variant->is_active || $variant->product_id !== $product->id)) {
            throw new PricingException(__('messages.checkout.variant_unavailable'));
        }
        if ($userId !== null && $product->seller_id !== null && (int) $product->seller_id === $userId) {
            throw new PricingException(__('messages.checkout.own_product'));
        }
        if ($qty < 1) {
            throw new PricingException(__('messages.checkout.product_unavailable'));
        }
        $stock = $variant ? $variant->stock : $product->stock;
        if ($stock < $qty) {
            $label = $variant ? "\"{$product->name}\" ({$variant->label})" : "\"{$product->name}\"";
            throw new PricingException(__('messages.checkout.stock_requested', ['label' => $label, 'stock' => $stock, 'requested' => $qty]));
        }

        // Always the live price: promotion active at this exact moment, never a client figure
        $pricing   = $this->promo->priceLine($product, $variant);
        $promotion = $pricing['promotion'];
        $unitM     = Millimes::of($pricing['final_price']);
        $originalM = $promotion ? max($unitM, Millimes::of($pricing['original_price'])) : $unitM;
        $ended     = self::promotionEnded($row, $pricing);
        $promoM    = ($originalM - $unitM) * $qty;
        $platform  = $promotion && ($promotion['funded_by'] ?? null) === Promotion::FUNDED_PLATFORM;

        return [
            'kind'      => 'product',
            'key'       => $key,
            'cart_id'   => $cartId,
            'seller_id' => $product->seller_id !== null ? (int) $product->seller_id : null,
            'product'   => $product,
            'variant'   => $variant,
            'quantity'  => $qty,
            'pricing'   => $pricing,
            'unit_m'    => $unitM,
            'total_m'   => $unitM * $qty,
            'original_unit_m'  => $originalM,
            'tier_m'           => $originalM,                       // commission bracket (AUDIT M-7)
            'promo_discount_m' => $promoM,
            'promotion_source' => $promotion ? ($promotion['source'] ?? 'seller') : null,
            'funded_by'        => $promotion ? ($platform ? Promotion::FUNDED_PLATFORM : Promotion::FUNDED_SELLER) : null,
            'funded_m'         => $platform ? $promoM : 0,          // paid back to the seller (AUDIT M-8)
            'promo_ended'      => $ended,
            'promo_ended_price_m' => $ended ? Millimes::of($row->promo_price_snapshot) : null,
            'is_free'   => false,
            'size'      => ParcelSize::normalize($product->parcel_size),
        ];
    }

    /**
     * The cart row was added during a promotion that no longer prices it (ended,
     * paused, sold out): the normal price applies and the buyer is told so.
     */
    public static function promotionEnded(?Cart $row, array $pricing): bool
    {
        return $row !== null
            && $row->promotion_id_snapshot !== null
            && $row->promo_price_snapshot !== null
            && $pricing['promotion'] === null;
    }

    /**
     * One line per item of the pack, each carrying its share of the pack price
     * (by original price, remainder on the last line — AUDIT C-1).
     */
    private function packLines(Cart $row, ?int $userId): array
    {
        $pack    = $row->pack;
        $packQty = max(1, (int) $row->quantity);

        if (!$pack || !$pack->is_active || !$pack->is_approved) {
            throw new PricingException(__('messages.checkout.pack_unavailable', ['pack' => $row->pack_name]));
        }
        $priceM = Millimes::of($pack->pack_price);              // current price, never the cart snapshot
        if ($priceM <= 0) {
            throw new PricingException(__('messages.checkout.pack_invalid_price', ['pack' => $pack->name]));
        }

        $selections = collect($row->pack_selections ?? [])->keyBy('pack_item_id');
        $components = [];
        foreach ($pack->items as $packItem) {
            $product = $packItem->product;
            if (!$product || !$product->is_approved || !$product->is_active) {
                throw new PricingException(__('messages.checkout.pack_product_unavailable', ['pack' => $pack->name]));
            }
            if ($userId !== null && $product->seller_id !== null && (int) $product->seller_id === $userId) {
                throw new PricingException(__('messages.checkout.own_pack'));
            }
            $variantId = $selections->get($packItem->id)['variant_id'] ?? null;
            $variant   = $variantId ? ($product->variants->firstWhere('id', (int) $variantId) ?? ProductVariant::find($variantId)) : null;
            $perPack   = max(1, (int) $packItem->quantity);
            $qty       = $perPack * $packQty;
            $stock     = $variant ? (int) $variant->stock : (int) $product->stock;
            if ($stock < $qty) {
                throw new PricingException(__('messages.checkout.pack_product_stock', ['product' => $product->name, 'pack' => $pack->name, 'stock' => $stock]));
            }
            $unitOriginalM = Millimes::of(PromotionService::basePrice($product, $variant));
            $components[] = [
                'pack_item'  => $packItem,
                'product'    => $product,
                'variant'    => $variant,
                'per_pack'   => $perPack,
                'quantity'   => $qty,
                'seller_id'  => $product->seller_id !== null ? (int) $product->seller_id : null,
                'unit_original_m' => $unitOriginalM,
                // Weight of the split: normal price × quantity in one pack
                'weight_m'   => $unitOriginalM * $perPack,
            ];
        }
        if (!$components) {
            throw new PricingException(__('messages.checkout.pack_unavailable', ['pack' => $pack->name]));
        }

        $snapshotM = Millimes::of($row->pack_price_snapshot);
        $isFree    = false;
        $shares    = $this->splitByWeight($priceM, array_column($components, 'weight_m'));

        $lines = [];
        foreach ($components as $i => $c) {
            $lines[] = [
                'kind'           => 'pack',
                'key'            => 'p' . $row->id . '-' . $i,
                'cart_id'        => $row->id,
                'seller_id'      => $c['seller_id'],
                'pack'           => $pack,
                'pack_group'     => 'c' . $row->id,
                'pack_quantity'  => $packQty,
                'component'      => $c,
                'product'        => $c['product'],
                'variant'        => $c['variant'],
                'quantity'       => $c['quantity'],              // units of this item
                'unit_m'         => $shares[$i],                 // this line's share of ONE pack
                'total_m'        => $shares[$i] * $packQty,
                'tier_m'         => $c['unit_original_m'],       // commission bracket (AUDIT M-7)
                'original_value_m' => $c['weight_m'] * $packQty,
                'funded_m'       => 0,
                'is_free'        => $isFree,
                'size'           => ParcelSize::normalize($c['product']->parcel_size),
                'pack_price_m'   => $priceM,
                'pack_changed'   => $snapshotM !== $priceM,
            ];
        }
        return $lines;
    }

    /**
     * $total split proportionally to $weights, half-up per share, the
     * remainder on the last key (shares always sum exactly to $total).
     *
     * @param array<int|string, int> $weights
     * @return array<int|string, int>
     */
    public function splitByWeight(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            $weights = array_map(fn () => 1, $weights);
            $sum     = count($weights);
        }
        $out = [];
        $allocated = 0;
        $last = array_key_last($weights);
        foreach ($weights as $k => $w) {
            $out[$k] = $k === $last ? $total - $allocated : Millimes::share($total, $w, $sum);
            $allocated += $out[$k];
        }
        return $out;
    }

    /**
     * Flash sales: at most `per_customer_limit` units per customer at the flash
     * price, counting what this cart adds (several lines of the same sale) and
     * earlier orders. Checked again under lock when the order is written.
     */
    private function checkFlashLimits(array $lines, ?int $userId, ?string $guestPhone): void
    {
        $byPromo = [];
        foreach ($lines as $l) {
            $p = $l['pricing']['promotion'] ?? null;
            if ($l['kind'] === 'product' && $p && !empty($p['is_flash_sale']) && !empty($p['per_customer_limit'])) {
                $byPromo[$p['id']]['pricing']  = $l['pricing'];
                $byPromo[$p['id']]['name']     = $l['product']->name;
                $byPromo[$p['id']]['quantity'] = ($byPromo[$p['id']]['quantity'] ?? 0) + $l['quantity'];
            }
        }
        foreach ($byPromo as $p) {
            try {
                $this->promo->assertFlashCustomerLimit($p['pricing'], $p['quantity'], $userId, $guestPhone, $p['name']);
            } catch (FlashCustomerLimit $e) {
                throw new PricingException(__('messages.checkout.flash_customer_limit', [
                    'product' => $e->productName, 'limit' => $e->limit, 'bought' => $e->bought,
                ]), 422, 'flash_customer_limit');
            }
        }
    }

    // ── Coupons ──────────────────────────────────────────────────────────────

    /** @return array<int, Coupon> seller_id => coupon (validated against the lines later) */
    private function resolveCodes(array $codes): array
    {
        $bySeller = [];
        foreach (array_filter(array_unique(array_map('strval', $codes)), 'strlen') as $raw) {
            $coupon = Coupon::where('code', strtoupper($raw))->first();
            if (!$coupon) {
                throw new PricingException(__('messages.checkout.coupon_invalid_named', ['code' => $raw]));
            }
            if (isset($bySeller[$coupon->seller_id])) {
                throw new PricingException(__('messages.checkout.one_coupon_per_seller'));
            }
            $bySeller[$coupon->seller_id] = $coupon;
        }
        return $bySeller;
    }

    // ── Parcels ──────────────────────────────────────────────────────────────

    private function build(array $lines, array $coupons, ?int $userId, string $paymentMethod, ?string $guestPhone = null): array
    {
        $isCod = $paymentMethod === 'cod';

        // Group lines into parcels, in cart order
        $parcels = [];
        foreach ($lines as $line) {
            $k = $line['seller_id'] ?? 'platform';
            $parcels[$k]['seller_id'] = $line['seller_id'];
            $parcels[$k]['lines'][]   = $line;
        }

        // Coupons: each must match a seller of this cart; promoted lines are skipped (AUDIT M-5)
        foreach ($coupons as $sellerId => $coupon) {
            $productLines = collect($parcels[$sellerId]['lines'] ?? [])->where('kind', 'product');
            $items = $productLines->map(fn ($l) => [
                'product_id' => $l['product']->id,
                'quantity'   => $l['quantity'],
                'line_total' => Millimes::toDecimal($l['total_m']),
                'promoted'   => $l['pricing']['promotion'] !== null,  // the promotion actually charged
            ])->values()->all();

            $result = $this->coupons->validateForSeller($coupon->code, (int) $sellerId, $userId, $items, $guestPhone);
            if (!$result['valid']) {
                throw new PricingException($result['message']);
            }
            $eligible = $productLines
                ->filter(fn ($l) => $l['pricing']['promotion'] === null && in_array($l['product']->id, $result['eligible_product_ids'], true))
                ->mapWithKeys(fn ($l) => [$l['key'] => $l['total_m']])->all();

            $parcels[$sellerId]['coupon']         = $result['coupon'];
            $parcels[$sellerId]['coupon_m']       = $result['discount_amount_m'];
            $parcels[$sellerId]['coupon_shares']  = $this->coupons->allocateDiscountM($eligible, $result['discount_amount_m']);
            $parcels[$sellerId]['coupon_applied'] = $result['eligible_product_ids'];
            $parcels[$sellerId]['coupon_skipped'] = $result['skipped_product_ids'] ?? [];
        }

        // Buyers see the shop's name, never the seller's personal name
        $storeNames = \App\Services\ShopOverview::storeNames(array_column($parcels, 'seller_id'));

        $out = [];
        foreach ($parcels as $k => $p) {
            $sellerId = $p['seller_id'];
            $shares   = $p['coupon_shares'] ?? [];
            $lineOut  = [];
            $itemsM = $discountM = $commissionM = $sellerItemsM = $fundedM = 0;

            foreach ($p['lines'] as $line) {
                $discount = $shares[$line['key']] ?? 0;
                $qty      = $line['kind'] === 'pack' ? $line['pack_quantity'] : $line['quantity'];
                $c        = $this->commission->calculateForSeller(
                    $sellerId,
                    Millimes::toFloat($line['unit_m']),
                    $qty,
                    Millimes::toFloat($discount),
                    Millimes::toFloat($line['tier_m']),
                    (int) ($line['funded_m'] ?? 0),
                );

                $line['discount_m']   = $c['discount_amount_m'];
                $line['net_m']        = $c['net_total_m'];
                $line['commission']   = $c;
                $line['coupon']       = $c['discount_amount_m'] > 0 ? ($p['coupon'] ?? null) : null;
                $lineOut[] = $line;

                $itemsM       += $line['total_m'];
                $discountM    += $c['discount_amount_m'];
                $commissionM  += $c['commission_amount_m'];
                $sellerItemsM += $c['seller_amount_m'];
                $fundedM      += $c['platform_funded_m'];
            }

            // One parcel, one size: the largest of its items, priced by the admin per size.
            // The fee is included in the displayed prices; several units give the extra fees back.
            $feeRates      = $this->pricing->fees();
            [$lineOut, $units] = $this->withIncludedFees($lineOut, $feeRates);
            $fees          = $this->pricing->parcelFees($units, $feeRates);
            $size          = ParcelSize::largest(array_column($lineOut, 'size'));
            $rates         = $this->delivery->forSize($size);
            $clientFee     = $rates['client_fee'];
            $agencyCost    = $rates['agency_cost'];
            $isFree        = false;
            $feeM          = $clientFee;                  // = $fees['parcel_fee_m']
            $contributionM = 0;
            $marginM       = $clientFee - $agencyCost;
            $netM          = $itemsM - $discountM;
            $totalM        = $netM + $feeM;
            $codM          = $isCod ? $totalM : 0;

            $out[$k] = [
                'seller_id'          => $sellerId,
                'seller_name'        => $sellerId ? ($storeNames[(int) $sellerId] ?? '') : "CHOOSE'Tounsi",
                'lines'              => $lineOut,
                'coupon'             => $p['coupon'] ?? null,
                'coupon_product_ids' => $p['coupon_applied'] ?? [],
                'coupon_skipped_product_ids' => $p['coupon_skipped'] ?? [],
                'parcel_size'        => $size,
                'items_subtotal_m'   => $itemsM,
                'coupon_discount_m'  => $discountM,
                'is_free_delivery'   => $isFree,
                'delivery_fee_m'     => $feeM,
                // Delivery included in the displayed prices
                'units'                 => $fees['units'],
                'included_fees_total_m' => $fees['included_fees_total_m'],
                'multi_item_discount_m' => $fees['multi_item_discount_m'],
                'display_subtotal_m'    => $itemsM + $fees['included_fees_total_m'],
                'upsell_min_saving_m'   => $fees['units'] === 1 ? $this->pricing->upsellMinSavingM() : null,
                'fee_rates'             => $feeRates,
                'parcel_total_m'     => $totalM,
                'cod_amount_m'       => $codM,
                'commission_m'       => $commissionM,
                'platform_funded_m'  => $fundedM,
                'seller_items_net_m' => $sellerItemsM,
                'contribution_m'     => $contributionM,
                'seller_payout_m'    => $sellerItemsM - $contributionM,
                'agency_cost_m'      => $agencyCost,
                'delivery_margin_m'  => $marginM,
                'amount_to_remit_m'  => $codM - $agencyCost,
                // settings in force (snapshot on the seller_order)
                'settings'           => [
                    'client_delivery_fee'               => $clientFee,
                    'agency_delivery_cost'              => $agencyCost,
                    'seller_free_delivery_contribution' => 0,
                ],
            ];
        }

        $sum = fn (string $key) => array_sum(array_column($out, $key));
        $promoM = 0;
        foreach ($lines as $l) {
            $promoM += $l['promo_discount_m'] ?? 0;
        }
        // The client pays one parcel fee per shop, inside the displayed prices
        $paidBy = 'customer';

        return [
            'payment_method'     => $paymentMethod,
            'parcels'            => $out,
            'items_subtotal_m'   => $sum('items_subtotal_m'),
            'discount_m'         => $sum('coupon_discount_m'),
            'promo_savings_m'    => $promoM,
            'promo_ended'        => collect($lines)->filter(fn ($l) => $l['promo_ended'] ?? false)
                ->map(fn ($l) => $l['product']->name)->values()->all(),
            'delivery_fee_m'     => $sum('delivery_fee_m'),
            'included_fees_total_m' => $sum('included_fees_total_m'),
            'multi_item_discount_m' => $sum('multi_item_discount_m'),
            'display_subtotal_m'    => $sum('display_subtotal_m'),
            'total_m'            => $sum('parcel_total_m'),
            'agency_cost_m'      => $sum('agency_cost_m'),
            'shipping_paid_by'   => $paidBy,
            'pack_price_changed' => collect($lines)->contains(fn ($l) => $l['kind'] === 'pack' && $l['pack_changed']),
        ];
    }

    /**
     * Included delivery fee of every line, and the parcel's units.
     *   product line  each unit carries the fee of its size
     *   pack          ONE unit per pack bought, at the size of its largest item in this
     *                 parcel; that fee is spread over the pack's lines like its price
     *
     * @param  array<string, int> $rates size => fee in millimes
     * @return array{0: array, 1: array<int, array{size: string, quantity: int}>} [lines, units]
     */
    private function withIncludedFees(array $lines, array $rates): array
    {
        $units  = [];
        $groups = [];
        foreach ($lines as $i => $line) {
            if ($line['kind'] === 'pack') {
                $groups[$line['pack_group']][] = $i;
                continue;
            }
            $fee = $rates[$line['size']];
            $lines[$i]['included_fee_m']       = $fee;
            $lines[$i]['included_fee_total_m'] = $fee * $line['quantity'];
            $lines[$i]['unit_size']            = $line['size'];
            $units[] = ['size' => $line['size'], 'quantity' => $line['quantity']];
        }
        foreach ($groups as $indexes) {
            $size    = ParcelSize::largest(array_map(fn ($i) => $lines[$i]['size'], $indexes));
            $packQty = $lines[$indexes[0]]['pack_quantity'];
            $shares  = $this->splitByWeight($rates[$size] * $packQty, array_map(fn ($i) => $lines[$i]['total_m'], $indexes));
            foreach ($indexes as $k => $i) {
                $lines[$i]['included_fee_m']       = null;          // the fee belongs to the pack, not to a unit
                $lines[$i]['included_fee_total_m'] = $shares[$k];
                $lines[$i]['unit_size']            = $size;
                $lines[$i]['pack_fee_m']           = $rates[$size];
            }
            $units[] = ['size' => $size, 'quantity' => $packQty];
        }
        return [$lines, $units];
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * What the storefront displays: the prices the client sees (delivery
     * included), the multi-item discount per shop and a free delivery. Never an
     * internal figure: base prices, fees, commission, payouts, agency cost.
     */
    public function present(array $quote): array
    {
        $f = fn (int $m) => Millimes::toFloat($m);

        return [
            'payment_method' => $quote['payment_method'],
            'parcels'        => array_values(array_map(fn ($p) => [
                'seller_id'        => $p['seller_id'],
                'seller_name'      => $p['seller_name'],
                'parcel_size'      => $p['parcel_size'],
                'items_subtotal'   => $f($p['display_subtotal_m']),
                'coupon_code'      => $p['coupon']?->code,
                'coupon_discount'  => $f($p['coupon_discount_m']),
                // Which items the coupon applied to, and the promoted ones it skipped (AUDIT M-5)
                'coupon_product_ids'         => $p['coupon_product_ids'] ?? [],
                'coupon_skipped_product_ids' => $p['coupon_skipped_product_ids'] ?? [],
                // Delivery is always shown as free: it is inside the prices
                'is_free_delivery' => true,
                'delivery_fee'     => 0.0,
                'units'               => $p['units'],
                'multi_item_discount' => $f($p['multi_item_discount_m']),
                // Exactly one unit from this shop: what a second one would save at least
                'upsell_min_saving'   => $p['upsell_min_saving_m'] !== null ? $f($p['upsell_min_saving_m']) : null,
                'total'            => $f($p['parcel_total_m']),
                'cod_amount'       => $f($p['cod_amount_m']),
                'lines'            => array_map(function ($l) use ($f) {
                    $qty = max(1, $l['kind'] === 'pack' ? $l['pack_quantity'] : $l['quantity']);
                    if ($l['kind'] === 'pack') {
                        $unit = $l['unit_m'] + intdiv($l['included_fee_total_m'], $qty);
                        return [
                            'cart_id'    => $l['cart_id'],
                            'kind'       => 'pack',
                            'name'       => $l['pack']->name,
                            'quantity'   => $l['pack_quantity'],
                            'unit_price' => $f($unit),
                            'total'      => $f($l['total_m'] + $l['included_fee_total_m']),
                            'discount'   => 0.0,
                            'product_id' => null, 'variant_id' => null,
                            'original_unit_price' => $f($unit),
                            'promo_discount'      => 0.0,
                            'discount_percent'    => 0,
                            'promo_type'          => null,
                            'promotion_source'    => null,
                            'promo_ended'         => false,
                            'promo_ended_price'   => null,
                            'coupon_applied'      => false,
                        ];
                    }
                    $fee       = $l['included_fee_m'];
                    $unit      = $l['unit_m'] + $fee;
                    $original  = ($l['original_unit_m'] ?? $l['unit_m']) + $fee;
                    return [
                        'cart_id'    => $l['cart_id'],
                        'kind'       => $l['kind'],
                        'name'       => $l['product']->name,
                        'quantity'   => $l['quantity'],
                        'unit_price' => $f($unit),
                        'total'      => $f($l['total_m'] + $l['included_fee_total_m']),
                        'discount'   => $f($l['discount_m']),
                        // Promotion breakdown: original → final, as displayed
                        'product_id'          => $l['product']->id,
                        'variant_id'          => $l['variant']?->id,
                        'original_unit_price' => $f($original),
                        'promo_discount'      => $f($l['promo_discount_m'] ?? 0),
                        'discount_percent'    => $l['pricing']['promotion'] !== null ? PricingService::badgePercent($original, $unit) : 0,
                        'promo_type'          => $l['pricing']['promo_type'],
                        'promotion_source'    => $l['promotion_source'] ?? null,
                        'promo_ended'         => (bool) ($l['promo_ended'] ?? false),
                        'promo_ended_price'   => isset($l['promo_ended_price_m']) ? $f($l['promo_ended_price_m'] + $fee) : null,
                        'coupon_applied'      => $l['discount_m'] > 0,
                    ];
                }, self::mergePackLines($p['lines'])),
            ], $quote['parcels'])),
            'subtotal'            => $f($quote['display_subtotal_m']),
            'discount_amount'     => $f($quote['discount_m']),
            'multi_item_discount' => $f($quote['multi_item_discount_m']),
            'promo_savings'       => $f($quote['promo_savings_m']),
            'total_savings'       => $f($quote['promo_savings_m'] + $quote['discount_m']),
            'promo_ended'         => $quote['promo_ended'],
            'free_delivery'       => true,
            'delivery_fee'        => 0.0,
            'shipping_fee'        => 0.0,
            'total'               => $f($quote['total_m']),
            'parcel_count'        => count($quote['parcels']),
            'pack_price_changed'  => $quote['pack_price_changed'],
        ];
    }

    /** The storefront shows one line per pack row (and seller): its item lines summed. */
    private static function mergePackLines(array $lines): array
    {
        $out = [];
        $packAt = [];
        foreach ($lines as $l) {
            if ($l['kind'] !== 'pack') {
                $out[] = $l;
                continue;
            }
            if (!isset($packAt[$l['cart_id']])) {
                $packAt[$l['cart_id']] = count($out);
                $out[] = $l;
                continue;
            }
            $i = $packAt[$l['cart_id']];
            $out[$i]['unit_m']  += $l['unit_m'];
            $out[$i]['total_m'] += $l['total_m'];
            $out[$i]['included_fee_total_m'] += $l['included_fee_total_m'];
        }
        return $out;
    }
}
