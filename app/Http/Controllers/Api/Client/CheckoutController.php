<?php

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\FlashSaleSoldOut;
use App\Exceptions\InsufficientStock;
use App\Services\Ads\AttributionService;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderItemSnapshot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\CouponService;
use App\Services\FinancialSnapshotService;
use App\Services\Orders\BuyerOrderNotifier;
use App\Services\Orders\OrderPricing;
use App\Services\Orders\PricingException;
use App\Services\Orders\SellerOrderNotifier;
use App\Services\Orders\OrderStock;
use App\Services\Payments\CheckoutPaymentMethods;
use App\Services\WalletService;
use App\Services\StockAlertService;
use App\Services\PromotionService;
use App\Services\Recommendation\InteractionTracker;
use App\Support\Millimes;
use App\Support\ShippingAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Checkout. Every amount comes from App\Services\Orders\OrderPricing: the
 * storefront shows its quote (POST /checkout/quote) and the order is priced
 * again here when placed — prices, fees and totals sent by the client are
 * never trusted (expected_total only detects a change, see priceChanged()).
 *
 * One seller = one parcel (seller_order) with its own delivery fee and COD
 * amount; regular products and packs of the same seller share that parcel.
 */
class CheckoutController extends Controller
{
    private const CART_RELATIONS = [
        'product',
        'variant.attributeOptions.attribute',
        'pack.items.product',
        'pack.items.product.variants.attributeOptions.attribute',
    ];

    public function __construct(
        private WalletService            $walletService,
        private StockAlertService        $stockAlertService,
        private PromotionService         $promoService,
        private FinancialSnapshotService $financialSnapshot,
        private InteractionTracker       $tracker,
        private CouponService            $couponService,
        private SellerOrderNotifier      $sellerNotifier,
        private OrderStock               $orderStock,
        private BuyerOrderNotifier       $buyerNotifier,
        private OrderPricing             $pricing,
        private CheckoutPaymentMethods   $paymentMethods,
    ) {}

    /**
     * POST /api/checkout/quote
     *
     * What the order will cost, per parcel: the cart (item_ids / coupon_codes)
     * or one product (product_id, variant_id, quantity, coupon_code — buy now).
     */
    public function quote(Request $request)
    {
        $request->validate([
            'item_ids'       => 'nullable|array',
            'item_ids.*'     => 'integer',
            'coupon_codes'   => 'nullable|array',
            'coupon_codes.*' => 'string',
            'product_id'     => 'nullable|integer|exists:products,id',
            'variant_id'     => 'nullable|integer|exists:product_variants,id',
            'quantity'       => 'nullable|integer|min:1|max:100',
            'coupon_code'    => 'nullable|string',
        ]);
        $user = $request->user();

        try {
            $quote = $request->filled('product_id')
                ? $this->buyNowQuote($request, $user, 'cod')
                : $this->pricing->forCart($this->cartRows($request, $user), $request->input('coupon_codes', []), $user->id, 'cod');
        } catch (PricingException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->pricing->present($quote) + ['payment_methods' => $this->paymentMethods->all()],
        ]);
    }

    /**
     * POST /api/checkout
     *
     * Handles two types of cart rows:
     *   A) Regular product rows  (product_id set, pack_id null)
     *   B) Pack bundle rows      (pack_id set, product_id null)
     */
    public function store(Request $request)
    {
        ShippingAddress::prepare($request);
        $request->validate(ShippingAddress::rules() + [
            'payment_method' => 'nullable|string|in:' . implode(',', CheckoutPaymentMethods::ALL),
            'item_ids'       => 'nullable|array',
            'item_ids.*'     => 'integer',
            'coupon_codes'   => 'nullable|array',
            'coupon_codes.*' => 'string',
            'expected_total' => 'nullable|numeric|min:0',
        ]);

        $user          = $request->user();
        $paymentMethod = $request->payment_method ?? 'cod';
        if ($blocked = $this->paymentMethodBlocked($paymentMethod)) {
            return $blocked;
        }

        $cartItems = $this->cartRows($request, $user);
        if ($cartItems->isEmpty()) {
            return response()->json(['success' => false, 'message' => __('messages.checkout.cart_empty')], 422);
        }

        try {
            $quote = $this->pricing->forCart($cartItems, $request->input('coupon_codes', []), $user->id, $paymentMethod);
        } catch (PricingException $e) {
            return $e->toResponse();
        }

        // A pack price changed since it was added: the cart now shows the new price
        if ($quote['pack_price_changed']) {
            $this->refreshPackSnapshots($cartItems);
        }
        if ($changed = $this->priceChanged($request, $quote)) {
            return $changed;
        }
        if ($short = $this->walletShort($paymentMethod, $user, $quote['total_m'])) {
            return $short;
        }

        $checkingOutIds = $cartItems->pluck('id')->all();

        DB::beginTransaction();
        try {
            [$order, $soldLines] = $this->placeOrder($request, $user, $quote, $paymentMethod);

            Cart::where('user_id', $user->id)->whereIn('id', $checkingOutIds)->delete();

            // COD / wallet: sellers hear about it now (sent after the commit).
            // Card / D17 wait for the payment: see SellerOrderNotifier.
            $this->sellerNotifier->orderPlaced($order);
            $this->buyerNotifier->orderPlaced($order);
            DB::commit();
        } catch (FlashSaleSoldOut $e) {
            DB::rollBack();
            return $this->flashSoldOut($e);
        } catch (InsufficientStock $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => __('messages.checkout.stock_only', ['label' => $e->label, 'stock' => $e->available])], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] store failed: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => __('messages.checkout.order_failed')], 500);
        }

        $this->afterOrder($request, $order, $soldLines);

        return response()->json($this->orderResponse($order, $quote, $paymentMethod), 201);
    }

    /**
     * POST /api/checkout/buy-now
     * Single product direct purchase — bypasses cart.
     */
    public function buyNow(Request $request)
    {
        ShippingAddress::prepare($request);
        $request->validate(ShippingAddress::rules() + [
            'product_id'     => 'required|integer|exists:products,id',
            'variant_id'     => 'nullable|integer|exists:product_variants,id',
            'quantity'       => 'required|integer|min:1|max:100',
            'payment_method' => 'nullable|string|in:' . implode(',', CheckoutPaymentMethods::ALL),
            'coupon_code'    => 'nullable|string',
            'expected_total' => 'nullable|numeric|min:0',
        ]);

        $user          = $request->user();
        $paymentMethod = $request->payment_method ?? 'cod';
        if ($blocked = $this->paymentMethodBlocked($paymentMethod)) {
            return $blocked;
        }

        try {
            $quote = $this->buyNowQuote($request, $user, $paymentMethod);
        } catch (PricingException $e) {
            return $e->toResponse();
        }

        if ($changed = $this->priceChanged($request, $quote)) {
            return $changed;
        }
        if ($short = $this->walletShort($paymentMethod, $user, $quote['total_m'])) {
            return $short;
        }

        DB::beginTransaction();
        try {
            [$order, $soldLines] = $this->placeOrder($request, $user, $quote, $paymentMethod);
            $this->sellerNotifier->orderPlaced($order);
            $this->buyerNotifier->orderPlaced($order);
            DB::commit();
        } catch (FlashSaleSoldOut $e) {
            DB::rollBack();
            return $this->flashSoldOut($e);
        } catch (InsufficientStock $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => __('messages.checkout.stock_only', ['label' => $e->label, 'stock' => $e->available])], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] buyNow failed: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => __('messages.checkout.order_failed')], 500);
        }

        $this->afterOrder($request, $order, $soldLines);

        return response()->json($this->orderResponse($order, $quote, $paymentMethod), 201);
    }

    // ── Order writer ──────────────────────────────────────────────────────────

    /**
     * Write the order exactly as quoted: one seller_order per parcel, its
     * lines (pack money on the pack's first line per seller, the other pack
     * lines track stock only), stock + flash reservations, then the frozen
     * parcel snapshot. Runs inside the caller's transaction.
     *
     * @return array{0: Order, 1: array} the order and the sold lines for stock alerts
     */
    private function placeOrder(Request $request, User $user, array $quote, string $paymentMethod): array
    {
        $dec    = fn (int $m) => Millimes::toDecimal($m);
        $paid   = $paymentMethod === 'wallet' ? 'paid' : 'unpaid';
        $coupons = collect($quote['parcels'])->pluck('coupon')->filter();

        $order = Order::create([
            'user_id'             => $user->id,
            'order_number'        => 'ORD-' . strtoupper(Str::random(8)),
            'status'              => 'pending',
            'payment_status'      => $paid,
            'payment_method'      => $paymentMethod,
            'subtotal'            => $dec($quote['items_subtotal_m']),
            'discount_amount'     => $dec($quote['discount_m']),
            'coupon_codes'        => $coupons->map(fn ($c) => $c->code)->values()->all() ?: null,
            'shipping_fee'        => $dec($quote['delivery_fee_m']),   // Σ parcel fees
            'shipping_cost'       => $dec($quote['agency_cost_m']),    // Σ agency costs
            'shipping_paid_by'    => $quote['shipping_paid_by'],       // customer | seller | mixed
            'shipping_per_parcel' => true,
            'total_amount'        => $dec($quote['total_m']),
            // Address snapshot: later address-book edits never touch this order.
            ...ShippingAddress::columns($request),
        ]);

        $soldLines = [];
        foreach ($quote['parcels'] as $parcel) {
            $coupon = $parcel['coupon'];
            $sellerOrder = SellerOrder::create([
                'order_id'        => $order->id,
                'seller_id'       => $parcel['seller_id'],
                'status'          => 'pending',
                'payment_status'  => $paid,
                'subtotal'        => $dec($parcel['items_subtotal_m']),
                'coupon_id'       => $coupon?->id,
                'coupon_code'     => $coupon?->code,
                'coupon_type'     => $coupon?->discount_type,
                'coupon_value'    => $coupon?->discount_value,
                'discount_amount' => $dec($parcel['coupon_discount_m']),
            ]);

            foreach ($parcel['lines'] as $line) {
                $line['kind'] === 'pack'
                    ? $this->writePackLine($order, $sellerOrder, $line, $soldLines)
                    : $this->writeProductLine($order, $sellerOrder, $line, $soldLines);
            }

            $this->financialSnapshot->snapshotParcel($sellerOrder->id, $parcel);

            if ($coupon) {
                $this->couponService->redeem($coupon, $sellerOrder, $order, $user->id, Millimes::toFloat($parcel['coupon_discount_m']));
            }
        }

        if ($paymentMethod === 'wallet') {
            $this->walletService->deductForOrder($user, $order);
        }

        return [$order, $soldLines];
    }

    private function writeProductLine(Order $order, SellerOrder $sellerOrder, array $line, array &$soldLines): void
    {
        $product = $line['product'];
        $variant = $line['variant'];
        $qty     = $line['quantity'];
        $c       = $line['commission'];
        $held    = $this->promoService->reserveForLine($line['pricing'], $qty) ?? throw new FlashSaleSoldOut($product->name);

        OrderItem::create([
            'order_id'              => $order->id,
            'seller_order_id'       => $sellerOrder->id,
            'product_id'            => $product->id,
            'variant_id'            => $variant?->id,
            'promotion_id'          => $line['pricing']['promotion']['id'] ?? null,
            'flash_reserved'        => $held,
            'variant_label'         => $this->variantLabel($variant),
            ...OrderItemSnapshot::capture($product, $variant), // image + attributes as bought
            'product_name'          => $product->getAttributes()['name'], // order snapshot keeps the seller's original text
            'quantity'              => $qty,
            'unit_price'            => Millimes::toDecimal($line['unit_m']),
            'price'                 => Millimes::toDecimal($line['unit_m']),
            'total'                 => Millimes::toDecimal($c['total_price_m']),
            'discount_amount'       => Millimes::toDecimal($c['discount_amount_m']),
            'net_total'             => Millimes::toDecimal($c['net_total_m']),
            'commission_percentage' => $c['commission_percentage'],
            'commission_source'     => $c['commission_source'],
            'commission_amount'     => Millimes::toDecimal($c['commission_amount_m']),
            'seller_amount'         => Millimes::toDecimal($c['seller_amount_m']),
            'plan_used'             => $c['plan_used'],
        ]);

        $this->orderStock->reserve($variant?->id, $product->id, $qty, $this->stockLabel($product, $variant));
        $soldLines[] = ['product_id' => (int) $product->id, 'variant_id' => $variant ? (int) $variant->id : null];
    }

    /**
     * A pack's share for one seller: the FIRST line carries the money (the
     * seller's share of the pack price × pack quantity, commission on it);
     * the following lines track stock only (zero money, by design).
     */
    private function writePackLine(Order $order, SellerOrder $sellerOrder, array $line, array &$soldLines): void
    {
        $c     = $line['commission'];
        $first = true;

        foreach ($line['components'] as $component) {
            $product = $component['product'];
            $variant = $component['variant'];
            $money   = $first ? [
                'unit_price'            => Millimes::toDecimal($line['unit_m']),
                'price'                 => Millimes::toDecimal($line['unit_m']),
                'total'                 => Millimes::toDecimal($c['total_price_m']),
                'discount_amount'       => Millimes::toDecimal($c['discount_amount_m']),
                'net_total'             => Millimes::toDecimal($c['net_total_m']),
                'commission_percentage' => $c['commission_percentage'],
                'commission_source'     => $c['commission_source'],
                'commission_amount'     => Millimes::toDecimal($c['commission_amount_m']),
                'seller_amount'         => Millimes::toDecimal($c['seller_amount_m']),
            ] : [
                'unit_price' => 0, 'price' => 0, 'total' => 0, 'net_total' => 0,
                'commission_percentage' => 0, 'commission_amount' => 0, 'seller_amount' => 0,
            ];

            OrderItem::create([
                'order_id'        => $order->id,
                'seller_order_id' => $sellerOrder->id,
                'product_id'      => $product->id,
                'variant_id'      => $variant?->id,
                'variant_label'   => $this->variantLabel($variant),
                ...OrderItemSnapshot::capture($product, $variant), // image + attributes as bought
                'product_name'    => $product->getAttributes()['name'] . ' (Bundle: ' . $line['pack']->name . ')',
                'quantity'        => $component['quantity'],
                'plan_used'       => $c['plan_used'],
            ] + $money);
            $first = false;

            $this->orderStock->reserve($variant?->id, $product->id, $component['quantity'], $this->stockLabel($product, $variant));
            $soldLines[] = ['product_id' => (int) $product->id, 'variant_id' => $variant ? (int) $variant->id : null];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function cartRows(Request $request, User $user): Collection
    {
        $query = Cart::with(self::CART_RELATIONS)->where('user_id', $user->id)->orderBy('id');
        if (!empty($ids = $request->input('item_ids'))) {
            $query->whereIn('id', $ids);
        }
        return $query->get();
    }

    private function buyNowQuote(Request $request, User $user, string $paymentMethod): array
    {
        $product = Product::find($request->product_id);
        $variant = null;
        if ($request->filled('variant_id')) {
            $variant = ProductVariant::with('attributeOptions.attribute')
                ->where('id', $request->variant_id)
                ->where('product_id', $request->product_id)
                ->first();
            if (!$variant || !$variant->is_active) {
                throw new PricingException(__('messages.checkout.variant_unavailable'));
            }
        }
        return $this->pricing->forBuyNow($product, $variant, (int) ($request->quantity ?? 1), $request->input('coupon_code'), $user->id, $paymentMethod);
    }

    /** Disabled methods ("Coming soon" in the storefront) are refused here too. */
    private function paymentMethodBlocked(string $method): ?\Illuminate\Http\JsonResponse
    {
        if ($this->paymentMethods->enabled($method)) {
            return null;
        }
        return response()->json([
            'success' => false,
            'code'    => 'payment_method_unavailable',
            'message' => __('messages.checkout.payment_method_unavailable'),
            'errors'  => ['payment_method' => [__('messages.checkout.payment_method_unavailable')]],
        ], 422);
    }

    private function walletShort(string $method, User $user, int $totalM): ?\Illuminate\Http\JsonResponse
    {
        if ($method !== 'wallet' || Millimes::of($user->wallet_balance) >= $totalM) {
            return null;
        }
        return response()->json(['success' => false, 'message' => __('messages.checkout.insufficient_wallet'), 'data' => ['wallet_balance' => (float) $user->wallet_balance, 'required' => Millimes::toFloat($totalM)]], 422);
    }

    /**
     * The storefront sends the total it showed (expected_total). When the server's
     * total differs (a promotion started/ended, a price or a fee changed) the order
     * is not placed: the customer gets the new total and confirms again. Without
     * expected_total, a pack whose price changed since it was added is refused the
     * same way. The server's numbers are always the ones charged.
     */
    private function priceChanged(Request $request, array $quote): ?\Illuminate\Http\JsonResponse
    {
        $differs = $request->filled('expected_total')
            ? Millimes::of($request->input('expected_total')) !== $quote['total_m']
            : $quote['pack_price_changed'];
        if (!$differs) {
            return null;
        }
        return response()->json([
            'success' => false,
            'code'    => 'price_changed',
            'message' => __('messages.checkout.price_changed'),
            'data'    => ['total' => Millimes::toFloat($quote['total_m']), 'quote' => $this->pricing->present($quote)],
        ], 409);
    }

    private function refreshPackSnapshots(Collection $cartItems): void
    {
        foreach ($cartItems->filter(fn ($i) => $i->isPack() && $i->pack) as $row) {
            if (Millimes::of($row->pack_price_snapshot) !== Millimes::of($row->pack->pack_price)) {
                $row->update(['pack_price_snapshot' => $row->pack->pack_price]);
            }
        }
    }

    private function orderResponse(Order $order, array $quote, string $paymentMethod): array
    {
        $shown = $this->pricing->present($quote);
        return [
            'success'         => true,
            'message'         => __('messages.checkout.order_placed'),
            'order_number'    => $order->order_number,
            'order_id'        => $order->id,
            'subtotal'        => $shown['subtotal'],
            'discount_amount' => $shown['discount_amount'],
            'delivery_fee'    => $shown['delivery_fee'],
            'shipping_fee'    => $shown['shipping_fee'],
            'total'           => $shown['total'],
            'parcels'         => $shown['parcels'],
            'seller_count'    => $shown['parcel_count'],
            'needs_payment'   => $paymentMethod === 'card',
        ];
    }

    /** After the commit: stock alerts, purchase signals, ad attribution (never throw). */
    private function afterOrder(Request $request, Order $order, array $soldLines): void
    {
        try {
            $this->stockAlertService->recordSales($soldLines);
        } catch (\Throwable $e) {
            Log::error('[Checkout] fireStockAlerts failed: ' . $e->getMessage());
        }

        try {
            $order->loadMissing('items.product:id,category_id');
            foreach ($order->items as $item) {
                $this->tracker->recordFromRequest($request, 'purchase', $item->product_id, ['category_id' => $item->product?->category_id, 'order_id' => $order->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Preferences] purchase log failed: ' . $e->getMessage());
        }
        // Credit lines bought after clicking an ad to that campaign (never throws).
        app(AttributionService::class)->recordOrder($order, InteractionTracker::sessionIdFrom($request));
    }

    private function variantLabel(?ProductVariant $variant): ?string
    {
        return $variant ? $variant->attributeOptions->map(fn ($o) => $o->getAttributes()['value'])->join(' / ') : null;
    }

    /** "Name" or "Name" (Variant) — same label as the pre-flight stock messages. */
    private function stockLabel(Product $product, ?ProductVariant $variant): string
    {
        return $variant ? "\"{$product->name}\" ({$variant->label})" : "\"{$product->name}\"";
    }

    private function flashSoldOut(FlashSaleSoldOut $e): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => 'flash_sold_out',
            'message' => __('messages.checkout.flash_sold_out', ['product' => $e->productName]),
        ], 422);
    }
}
