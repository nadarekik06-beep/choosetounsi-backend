<?php

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Pack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\CouponService;
use App\Services\Delivery\DeliverySettings;
use App\Services\PromotionService;
use App\Support\Millimes;
use Illuminate\Support\Collection;

/**
 * The single source of truth for what an order costs (quote AND order):
 * the storefront only displays this, and checkout recomputes it when the
 * order is placed. All money is integer millimes (App\Support\Millimes).
 *
 * One seller = one parcel = one pickup. Per parcel:
 *   items_subtotal  Σ line totals (post pack price / discount / flash sale)
 *   coupon_discount the seller's coupon (one per seller, never on promoted lines or packs)
 *   is_free         every item of the parcel is free delivery (a pack: all its products)
 *   delivery_fee    0 when free, else the admin client_delivery_fee
 *   parcel_total    items_subtotal − coupon_discount + delivery_fee
 *   cod_amount      parcel_total for cash on delivery (0 when prepaid)
 *   commission      Σ line commissions, on (line − coupon share) only, never on delivery
 *   contribution    seller_free_delivery_contribution when free, else 0
 *   seller_payout   items_subtotal − coupon_discount − commission − contribution
 *   agency_cost     admin agency_delivery_cost (the courier keeps it from the cash)
 *   delivery_margin free ? contribution − agency_cost : delivery_fee − agency_cost
 *
 * Always: parcel_total = seller_payout + commission + agency_cost + delivery_margin.
 *
 * Packs: priced at the pack's CURRENT price × quantity; a pack spanning
 * several sellers is split between them in proportion to the value of their
 * products (normal price × quantity in the pack), remainder on the last seller.
 */
class OrderPricing
{
    public function __construct(
        private PromotionService  $promo,
        private CommissionService $commission,
        private CouponService     $coupons,
        private DeliverySettings  $delivery,
    ) {}

    // ── Entry points ─────────────────────────────────────────────────────────

    /**
     * @param Collection<int, Cart> $rows cart rows (product and pack rows)
     * @param string[] $couponCodes
     */
    public function forCart(Collection $rows, array $couponCodes, int $userId, string $paymentMethod = 'cod'): array
    {
        if ($rows->isEmpty()) {
            throw new PricingException(__('messages.checkout.cart_empty'));
        }

        $lines = [];
        foreach ($rows as $row) {
            if ($row->isPack()) {
                array_push($lines, ...$this->packLines($row));
            } else {
                $lines[] = $this->productLine($row->product, $row->variant, (int) $row->quantity, $userId, 'c' . $row->id, $row->id);
            }
        }

        return $this->build($lines, $this->resolveCodes($couponCodes), $userId, $paymentMethod);
    }

    public function forBuyNow(Product $product, ?ProductVariant $variant, int $quantity, ?string $couponCode, int $userId, string $paymentMethod = 'cod'): array
    {
        $line = $this->productLine($product, $variant, $quantity, $userId, 'buy_now', null);

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

        return $this->build([$line], $codes, $userId, $paymentMethod);
    }

    // ── Lines ────────────────────────────────────────────────────────────────

    private function productLine(?Product $product, ?ProductVariant $variant, int $qty, int $userId, string $key, ?int $cartId): array
    {
        if (!$product || !$product->is_approved || !$product->is_active) {
            throw new PricingException($product
                ? __('messages.checkout.product_unavailable_named', ['product' => $product->name])
                : __('messages.checkout.product_unavailable'));
        }
        if ($variant && (!$variant->is_active || $variant->product_id !== $product->id)) {
            throw new PricingException(__('messages.checkout.variant_unavailable'));
        }
        if ($product->seller_id !== null && (int) $product->seller_id === $userId) {
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

        $pricing = $this->promo->priceLine($product, $variant);
        $unitM   = Millimes::of($pricing['final_price']);

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
            'is_free'   => $product->isFreeDelivery(),
        ];
    }

    /** One line per seller present in the pack, carrying that seller's share of the pack price. */
    private function packLines(Cart $row): array
    {
        $pack    = $row->pack;
        $packQty = max(1, (int) $row->quantity);

        if (!$pack || !$pack->is_active || !$pack->is_approved) {
            throw new PricingException(__('messages.checkout.pack_unavailable', ['pack' => $row->pack_name]));
        }

        $selections = collect($row->pack_selections ?? [])->keyBy('pack_item_id');
        $components = [];
        foreach ($pack->items as $packItem) {
            $product = $packItem->product;
            if (!$product || !$product->is_approved || !$product->is_active) {
                throw new PricingException(__('messages.checkout.pack_product_unavailable', ['pack' => $pack->name]));
            }
            $variantId = $selections->get($packItem->id)['variant_id'] ?? null;
            $variant   = $variantId ? ($product->variants->firstWhere('id', (int) $variantId) ?? ProductVariant::find($variantId)) : null;
            $qty       = (int) $packItem->quantity * $packQty;
            $stock     = $variant ? (int) $variant->stock : (int) $product->stock;
            if ($stock < $qty) {
                throw new PricingException(__('messages.checkout.pack_product_stock', ['product' => $product->name, 'pack' => $pack->name, 'stock' => $stock]));
            }
            $components[] = [
                'pack_item' => $packItem,
                'product'   => $product,
                'variant'   => $variant,
                'quantity'  => $qty,
                'seller_id' => $product->seller_id !== null ? (int) $product->seller_id : null,
                // Value weight for the seller split: normal price × quantity in one pack
                'weight_m'  => Millimes::of(PromotionService::basePrice($product, $variant)) * (int) $packItem->quantity,
            ];
        }
        if (!$components) {
            throw new PricingException(__('messages.checkout.pack_unavailable', ['pack' => $pack->name]));
        }

        $priceM    = Millimes::of($pack->pack_price);              // current price, never the cart snapshot
        $snapshotM = Millimes::of($row->pack_price_snapshot);
        $isFree    = collect($components)->every(fn ($c) => $c['product']->isFreeDelivery());

        // Split one pack's price between its sellers by value
        $bySeller = [];
        foreach ($components as $c) {
            $k = $c['seller_id'] ?? 'platform';
            $bySeller[$k]['seller_id']    = $c['seller_id'];
            $bySeller[$k]['weight_m']     = ($bySeller[$k]['weight_m'] ?? 0) + $c['weight_m'];
            $bySeller[$k]['components'][] = $c;
        }
        $weights = array_map(fn ($s) => $s['weight_m'], $bySeller);
        $shares  = $this->splitByWeight($priceM, $weights);

        $lines = [];
        foreach ($bySeller as $k => $s) {
            $lines[] = [
                'kind'           => 'pack',
                'key'            => 'p' . $row->id . '-' . $k,
                'cart_id'        => $row->id,
                'seller_id'      => $s['seller_id'],
                'pack'           => $pack,
                'pack_quantity'  => $packQty,
                'components'     => $s['components'],
                'unit_m'         => $shares[$k],          // this seller's share of ONE pack
                'total_m'        => $shares[$k] * $packQty,
                'is_free'        => $isFree,
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

    private function build(array $lines, array $coupons, int $userId, string $paymentMethod): array
    {
        $settings     = $this->delivery->all();
        $clientFee    = $settings['client_delivery_fee'];
        $agencyCost   = $settings['agency_delivery_cost'];
        $contribution = $settings['seller_free_delivery_contribution'];
        $isCod        = $paymentMethod === 'cod';

        // Group lines into parcels, in cart order
        $parcels = [];
        foreach ($lines as $line) {
            $k = $line['seller_id'] ?? 'platform';
            $parcels[$k]['seller_id'] = $line['seller_id'];
            $parcels[$k]['lines'][]   = $line;
        }

        // Coupons: each must match a seller of this cart
        foreach ($coupons as $sellerId => $coupon) {
            $productLines = collect($parcels[$sellerId]['lines'] ?? [])->where('kind', 'product');
            $items = $productLines->map(fn ($l) => [
                'product_id' => $l['product']->id,
                'quantity'   => $l['quantity'],
                'line_total' => Millimes::toDecimal($l['total_m']),
            ])->values()->all();

            $result = $this->coupons->validateForSeller($coupon->code, (int) $sellerId, $userId, $items);
            if (!$result['valid']) {
                throw new PricingException($result['message']);
            }
            $eligible = $productLines->filter(fn ($l) => in_array($l['product']->id, $result['eligible_product_ids'], true))
                ->mapWithKeys(fn ($l) => [$l['key'] => $l['total_m']])->all();

            $parcels[$sellerId]['coupon']        = $result['coupon'];
            $parcels[$sellerId]['coupon_m']      = $result['discount_amount_m'];
            $parcels[$sellerId]['coupon_shares'] = $this->coupons->allocateDiscountM($eligible, $result['discount_amount_m']);
        }

        $out = [];
        foreach ($parcels as $k => $p) {
            $sellerId = $p['seller_id'];
            $shares   = $p['coupon_shares'] ?? [];
            $lineOut  = [];
            $itemsM = $discountM = $commissionM = $sellerItemsM = 0;

            foreach ($p['lines'] as $line) {
                $discount = $shares[$line['key']] ?? 0;
                $qty      = $line['kind'] === 'pack' ? $line['pack_quantity'] : $line['quantity'];
                $c        = $this->commission->calculateForSeller($sellerId, Millimes::toFloat($line['unit_m']), $qty, Millimes::toFloat($discount));

                $line['discount_m']   = $c['discount_amount_m'];
                $line['net_m']        = $c['net_total_m'];
                $line['commission']   = $c;
                $lineOut[] = $line;

                $itemsM       += $line['total_m'];
                $discountM    += $c['discount_amount_m'];
                $commissionM  += $c['commission_amount_m'];
                $sellerItemsM += $c['seller_amount_m'];
            }

            $isFree        = collect($lineOut)->every(fn ($l) => $l['is_free']);
            $feeM          = $isFree ? 0 : $clientFee;
            $contributionM = $isFree ? $contribution : 0;
            $marginM       = $isFree ? $contribution - $agencyCost : $clientFee - $agencyCost;
            $netM          = $itemsM - $discountM;
            $totalM        = $netM + $feeM;
            $codM          = $isCod ? $totalM : 0;

            $out[$k] = [
                'seller_id'          => $sellerId,
                'seller_name'        => $sellerId ? (User::whereKey($sellerId)->value('name') ?? '') : "CHOOSE'Tounsi",
                'lines'              => $lineOut,
                'coupon'             => $p['coupon'] ?? null,
                'items_subtotal_m'   => $itemsM,
                'coupon_discount_m'  => $discountM,
                'is_free_delivery'   => $isFree,
                'delivery_fee_m'     => $feeM,
                'parcel_total_m'     => $totalM,
                'cod_amount_m'       => $codM,
                'commission_m'       => $commissionM,
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
                    'seller_free_delivery_contribution' => $contribution,
                ],
            ];
        }

        $sum = fn (string $key) => array_sum(array_column($out, $key));
        $paidBy = collect($out)->every(fn ($p) => $p['is_free_delivery']) ? 'seller'
            : (collect($out)->contains(fn ($p) => $p['is_free_delivery']) ? 'mixed' : 'customer');

        return [
            'payment_method'     => $paymentMethod,
            'parcels'            => $out,
            'items_subtotal_m'   => $sum('items_subtotal_m'),
            'discount_m'         => $sum('coupon_discount_m'),
            'delivery_fee_m'     => $sum('delivery_fee_m'),
            'total_m'            => $sum('parcel_total_m'),
            'agency_cost_m'      => $sum('agency_cost_m'),
            'shipping_paid_by'   => $paidBy,
            'pack_price_changed' => collect($lines)->contains(fn ($l) => $l['kind'] === 'pack' && $l['pack_changed']),
        ];
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /** What the storefront displays (never internal figures: commission, payouts, agency). */
    public function present(array $quote): array
    {
        $f = fn (int $m) => Millimes::toFloat($m);

        return [
            'payment_method' => $quote['payment_method'],
            'parcels'        => array_values(array_map(fn ($p) => [
                'seller_id'        => $p['seller_id'],
                'seller_name'      => $p['seller_name'],
                'items_subtotal'   => $f($p['items_subtotal_m']),
                'coupon_code'      => $p['coupon']?->code,
                'coupon_discount'  => $f($p['coupon_discount_m']),
                'is_free_delivery' => $p['is_free_delivery'],
                'delivery_fee'     => $f($p['delivery_fee_m']),
                'total'            => $f($p['parcel_total_m']),
                'cod_amount'       => $f($p['cod_amount_m']),
                'lines'            => array_map(fn ($l) => [
                    'cart_id'    => $l['cart_id'],
                    'kind'       => $l['kind'],
                    'name'       => $l['kind'] === 'pack' ? $l['pack']->name : $l['product']->name,
                    'quantity'   => $l['kind'] === 'pack' ? $l['pack_quantity'] : $l['quantity'],
                    'unit_price' => $f($l['unit_m']),
                    'total'      => $f($l['total_m']),
                    'discount'   => $f($l['discount_m']),
                ], $p['lines']),
            ], $quote['parcels'])),
            'subtotal'           => $f($quote['items_subtotal_m']),
            'discount_amount'    => $f($quote['discount_m']),
            'delivery_fee'       => $f($quote['delivery_fee_m']),
            'shipping_fee'       => $f($quote['delivery_fee_m']),
            'total'              => $f($quote['total_m']),
            'parcel_count'       => count($quote['parcels']),
            'pack_price_changed' => $quote['pack_price_changed'],
        ];
    }
}
