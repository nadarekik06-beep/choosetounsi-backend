<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pack;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Services\CommissionService;
use App\Services\FinancialSnapshotService;
use App\Services\WalletService;
use App\Services\StockAlertService;
use App\Services\PromotionService;
use App\Services\UserPreferenceService; // ← CHANGE 1: Added import
use App\Http\Controllers\Api\Seller\SellerForecastController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    // ← CHANGE 2: Added UserPreferenceService to constructor
    public function __construct(
        private WalletService            $walletService,
        private StockAlertService        $stockAlertService,
        private PromotionService         $promoService,
        private CommissionService        $commissionService,
        private FinancialSnapshotService $financialSnapshot,
        private UserPreferenceService    $preferenceService, // ← ADD THIS LINE
    ) {}

    /**
     * POST /api/checkout
     *
     * Handles two types of cart rows:
     *   A) Regular product rows  (product_id set, pack_id null)
     *   B) Pack bundle rows      (pack_id set, product_id null)
     *
     * After success, clears forecast cache for ALL sellers in the order.
     */
    public function store(Request $request)
    {
        $request->validate([
            'wilaya'         => 'required|string|max:255',
            'address'        => 'required|string|max:500',
            'phone'          => 'required|string|max:30',
            'notes'          => 'nullable|string|max:1000',
            'payment_method' => 'nullable|string|in:cod,card,d17,wallet',
            'item_ids'       => 'nullable|array',
            'item_ids.*'     => 'integer',
            ]);

        $user = $request->user();

       $cartQuery = Cart::with([
    'product',
    'variant.attributeOptions.attribute',
    'pack.items.product',
    'pack.items.product.variants.attributeOptions.attribute',
])->where('user_id', $user->id);

$selectedIds = $request->input('item_ids');
if (!empty($selectedIds)) {
    $cartQuery->whereIn('id', $selectedIds);
}

$cartItems      = $cartQuery->get();
$checkingOutIds = $cartItems->pluck('id')->all();
        if ($cartItems->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Your cart is empty.'], 422);
        }

        $paymentMethod = $request->payment_method ?? 'cod';

        // ── Pre-flight validation ─────────────────────────────────────────────
        foreach ($cartItems as $item) {
            if ($item->isPack()) {
                $pack = $item->pack;
                if (!$pack || !$pack->is_active || !$pack->is_approved) {
                    return response()->json(['success' => false, 'message' => "The bundle \"{$item->pack_name}\" is no longer available."], 422);
                }
                $selectionMap = collect($item->pack_selections ?? [])->keyBy('pack_item_id');
                foreach ($pack->items as $packItem) {
                    $sel       = $selectionMap->get($packItem->id);
                    $variantId = $sel['variant_id'] ?? null;
                    $product   = $packItem->product;
                    if (!$product || !$product->is_approved || !$product->is_active) {
                        return response()->json(['success' => false, 'message' => "A product in bundle \"{$pack->name}\" is no longer available."], 422);
                    }
                    $stock = $variantId ? (ProductVariant::find($variantId)?->stock ?? 0) : $product->stock;
                    if ($stock < $packItem->quantity) {
                        return response()->json(['success' => false, 'message' => "\"{$product->name}\" in bundle \"{$pack->name}\" only has {$stock} item(s) in stock."], 422);
                    }
                }
            } else {
                $product = $item->product;
                if (!$product || !$product->is_approved || !$product->is_active) {
                    return response()->json(['success' => false, 'message' => "\"$product->name\" is no longer available."], 422);
                }
                $this->ensureNotProductOwner($request, $product);
                $stockPool = $item->variant ? $item->variant->stock : $product->stock;
                if ($stockPool < $item->quantity) {
                    $label = $item->variant ? "\"{$product->name}\" ({$item->variant->label})" : "\"{$product->name}\"";
                    return response()->json(['success' => false, 'message' => "{$label} only has {$stockPool} item(s) in stock but {$item->quantity} were requested."], 422);
                }
            }
        }

        $sellerCol       = $this->getSellerCol();
        $total           = $this->calculateCartTotal($cartItems);
        $sellerPlanCache = [];

        if ($paymentMethod === 'wallet') {
            if ((float) $user->wallet_balance < $total) {
                return response()->json(['success' => false, 'message' => 'Insufficient wallet balance.', 'data' => ['wallet_balance' => (float) $user->wallet_balance, 'required' => $total]], 422);
            }
        }

        $decrementedVariants = [];
        $decrementedProducts = [];

        DB::beginTransaction();
        try {
            $order = Order::create([
                'user_id'        => $user->id,
                'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'payment_method' => $paymentMethod,
                'total_amount'   => $total,
                'wilaya'         => $request->wilaya,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'notes'          => $request->notes ?? null,
            ]);

            $productRows = $cartItems->filter(fn($i) => !$i->isPack());
            $packRows    = $cartItems->filter(fn($i) =>  $i->isPack());

            // ── A) Regular product rows ───────────────────────────────────────
            // UNCHANGED — product commission logic is correct as-is.
            if ($productRows->isNotEmpty()) {
                $groupedBySeller = $productRows->groupBy(function ($item) use ($sellerCol) {
                    $sid = $item->product->{$sellerCol};
                    return $sid !== null ? $sid : 'platform';
                });

                foreach ($groupedBySeller as $groupKey => $sellerItems) {
                    $sellerIdForDb = ($groupKey === 'platform') ? null : $groupKey;

                    if (!isset($sellerPlanCache[$groupKey])) {
                        $sellerPlanCache[$groupKey] = ($sellerIdForDb === null)
                            ? 'free'
                            : (SellerApplication::where('user_id', $sellerIdForDb)->first()?->plan ?? 'free');
                    }
                    $sellerPlan = $sellerPlanCache[$groupKey];

                    $sellerSubtotal = $sellerItems->sum(function ($item) {
                        $basePrice = $item->variant
                            ? (float) ($item->variant->price_override ?? $item->product->price)
                            : (float) $item->product->price;
                        $priceData = $this->promoService->getEffectivePrice($item->product, $basePrice);
                        return round($priceData['effective_price'] * $item->quantity, 3);
                    });

                    $sellerOrder = SellerOrder::create([
                        'order_id'       => $order->id,
                        'seller_id'      => $sellerIdForDb,
                        'status'         => 'pending',
                        'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                        'subtotal'       => $sellerSubtotal,
                    ]);

                    foreach ($sellerItems as $item) {
                        $product      = $item->product;
                        $variant      = $item->variant;
                        $basePrice    = $variant
                            ? (float) ($variant->price_override ?? $product->price)
                            : (float) $product->price;
                        $priceData    = $this->promoService->getEffectivePrice($product, $basePrice);
                        $unitPrice    = $priceData['effective_price'];
                        $qty          = (int) $item->quantity;
                        $commission   = $this->commissionService->calculate($unitPrice, $sellerPlan, $qty);
                        $variantLabel = $variant ? $variant->attributeOptions->pluck('value')->join(' / ') : null;

                        OrderItem::create([
                            'order_id'              => $order->id,
                            'seller_order_id'       => $sellerOrder->id,
                            'product_id'            => $product->id,
                            'variant_id'            => $variant?->id,
                            'variant_label'         => $variantLabel,
                            'product_name'          => $product->name,
                            'quantity'              => $qty,
                            'unit_price'            => $unitPrice,
                            'price'                 => $unitPrice,
                            'total'                 => $commission['total_price'],
                            'commission_percentage' => $commission['commission_percentage'],
                            'commission_amount'     => $commission['commission_amount'],
                            'seller_amount'         => $commission['seller_amount'],
                            'plan_used'             => $commission['plan_used'],
                        ]);

                        if ($variant) {
                            ProductVariant::where('id', $variant->id)->decrement('stock', $item->quantity);
                            $decrementedVariants[] = ['variant_id' => $variant->id, 'product' => $product];
                        } else {
                            $product->decrement('stock', $item->quantity);
                            $decrementedProducts[] = $product->id;
                        }
                    }

                    // Freeze financial snapshot AFTER all items for this seller_order are inserted
                    $this->financialSnapshot->freeze($sellerOrder->id);
                }
            }

            // ── B) Pack rows ──────────────────────────────────────────────────
            //
            // FIX: Commission is now calculated ONCE on the whole pack_price,
            // NOT on each individual product inside the pack.
            //
            // Why this was wrong before:
            //   Old code called commissionService->calculate($product->price) per item.
            //   For a 70 DT pack with a 50 DT + 40 DT product:
            //     Wrong:   commission(50) + commission(40)  ← wrong price, wrong total
            //     Correct: commission(70)                  ← pack_price, single calculation
            //
            // How the fix works:
            //   1. One commission calculation per pack using pack_price_snapshot.
            //   2. The FIRST order_item row carries the full commission figures.
            //   3. Subsequent items are inventory-tracking rows only (zero commission).
            //   4. seller_order.subtotal = pack_price_snapshot (already correct above).
            //
            foreach ($packRows as $cartRow) {
                $pack         = $cartRow->pack;
                $selectionMap = collect($cartRow->pack_selections ?? [])->keyBy('pack_item_id');

                // pack_price_snapshot: the price the customer paid for the whole pack.
                // This is the ONLY price commission should be calculated on.
                $packPrice = (float) $cartRow->pack_price_snapshot;

                $packItemsBySeller = $pack->items->groupBy(function ($pi) use ($sellerCol) {
                    $sid = $pi->product->{$sellerCol};
                    return $sid !== null ? $sid : 'platform';
                });

                foreach ($packItemsBySeller as $groupKey => $packItems) {
                    $proportion     = $packItems->count() / $pack->items->count();
                    $sellerSubtotal = round($packPrice * $proportion, 3);
                    $sellerIdForDb  = ($groupKey === 'platform') ? null : $groupKey;

                    if (!isset($sellerPlanCache[$groupKey])) {
                        $sellerPlanCache[$groupKey] = ($sellerIdForDb === null)
                            ? 'free'
                            : (SellerApplication::where('user_id', $sellerIdForDb)->first()?->plan ?? 'free');
                    }
                    $sellerPlan = $sellerPlanCache[$groupKey];

                    // ── FIXED: Calculate commission ONCE on the seller's portion ──
                    // Each seller's commission is calculated on their proportional
                    // share of the pack_price, not on individual product prices.
                    //
                    // If a pack has 2 products from the same seller: proportion = 1.0
                    //   → commission($packPrice × 1.0, $plan)
                    //
                    // If a pack has 4 products, 2 from seller A and 2 from seller B:
                    //   → seller A: commission($packPrice × 0.5, $plan)
                    //   → seller B: commission($packPrice × 0.5, $plan)
                    //
                    // quantity = 1 because pack_price already covers all items.
                    $packCommission = $this->commissionService->calculate(
                        $sellerSubtotal,  // ← proportional share of pack_price
                        $sellerPlan,
                        1                 // ← 1 pack unit
                    );

                    $sellerOrder = SellerOrder::create([
                        'order_id'       => $order->id,
                        'seller_id'      => $sellerIdForDb,
                        'status'         => 'pending',
                        'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                        'subtotal'       => $sellerSubtotal,
                    ]);

                    // Track whether we've written the commission to the first item yet
                    $commissionWritten = false;

                    foreach ($packItems as $packItem) {
                        $product      = $packItem->product;
                        $sel          = $selectionMap->get($packItem->id);
                        $variantId    = $sel['variant_id'] ?? null;
                        $variant      = $variantId ? $product->variants->firstWhere('id', $variantId) : null;
                        $qty          = (int) $packItem->quantity;
                        $variantLabel = $variant ? $variant->attributeOptions->pluck('value')->join(' / ') : null;

                        if (!$commissionWritten) {
                            // ── FIRST item: carries ALL commission for this pack+seller ──
                            // The financial figures here represent the whole seller's portion
                            // of the pack. Subsequent items are stock-tracking rows only.
                            OrderItem::create([
                                'order_id'              => $order->id,
                                'seller_order_id'       => $sellerOrder->id,
                                'product_id'            => $product->id,
                                'variant_id'            => $variantId,
                                'variant_label'         => $variantLabel,
                                'product_name'          => $product->name . ' (Bundle: ' . $pack->name . ')',
                                'quantity'              => $qty,

                                // unit_price = seller's portion of pack_price
                                // (not the product's individual retail price)
                                'unit_price'            => $packCommission['unit_price'],
                                'price'                 => $packCommission['unit_price'],
                                'total'                 => $packCommission['total_price'],

                                // Commission calculated on pack_price portion, not product price
                                'commission_percentage' => $packCommission['commission_percentage'],
                                'commission_amount'     => $packCommission['commission_amount'],
                                'seller_amount'         => $packCommission['seller_amount'],
                                'plan_used'             => $packCommission['plan_used'],
                            ]);
                            $commissionWritten = true;

                        } else {
                            // ── SUBSEQUENT items: inventory tracking only ──
                            // Commission is already captured on the first row.
                            // These rows exist so stock can be decremented per product.
                            // Financial dashboard queries should filter commission_amount > 0.
                            OrderItem::create([
                                'order_id'              => $order->id,
                                'seller_order_id'       => $sellerOrder->id,
                                'product_id'            => $product->id,
                                'variant_id'            => $variantId,
                                'variant_label'         => $variantLabel,
                                'product_name'          => $product->name . ' (Bundle: ' . $pack->name . ')',
                                'quantity'              => $qty,
                                'unit_price'            => 0,  // financial data is on the first row
                                'price'                 => 0,
                                'total'                 => 0,
                                'commission_percentage' => 0,  // intentionally zero
                                'commission_amount'     => 0,  // intentionally zero
                                'seller_amount'         => 0,  // intentionally zero
                                'plan_used'             => $sellerPlan,
                            ]);
                        }

                        // Stock decrement runs for EVERY item regardless of commission row
                        if ($variant) {
                            ProductVariant::where('id', $variantId)->decrement('stock', $packItem->quantity);
                            $decrementedVariants[] = ['variant_id' => $variantId, 'product' => $product];
                        } else {
                            $product->decrement('stock', $packItem->quantity);
                            $decrementedProducts[] = $product->id;
                        }
                    }

                    // Freeze financial snapshot AFTER all pack items for this seller_order
                    $this->financialSnapshot->freeze($sellerOrder->id);
                }
            }

            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            Cart::where('user_id', $user->id)
                ->whereIn('id', $checkingOutIds)
                ->delete();
            DB::commit();

            // ── CHANGE 3a: Log purchase activity for preferences ──────────────
            try {
                $sessionId = $this->safeSessionId($request);
                foreach ($productRows as $item) {
                    $this->preferenceService->logActivity(
                        userId:     $user->id,
                        productId:  $item->product_id,
                        categoryId: $item->product->category_id,
                        action:     'purchase',
                        sessionId:  $sessionId
                    );
                }
                foreach ($packRows as $cartRow) {
                    foreach ($cartRow->pack->items as $packItem) {
                        $this->preferenceService->logActivity(
                            userId:     $user->id,
                            productId:  $packItem->product_id,
                            categoryId: $packItem->product->category_id,
                            action:     'purchase',
                            sessionId:  $sessionId
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[Preferences] purchase log failed: ' . $e->getMessage());
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] store failed: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Failed to place order. Please try again.'], 500);
        }

        // ── Stock alerts ──────────────────────────────────────────────────────
        $this->fireStockAlerts($decrementedVariants, $decrementedProducts);

        // ── Clear forecast cache for ALL sellers in this order ────────────────
        try {
            $order->load('sellerOrders.items');
            foreach ($order->sellerOrders as $so) {
                if (!$so->seller_id) continue;
                $pids = $so->items->pluck('product_id')->filter()->unique();
                foreach ($pids as $pid) {
                    SellerForecastController::clearForecastCache((int) $pid, (int) $so->seller_id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Forecast] Cache clear after store() failed: ' . $e->getMessage());
        }

        $sellerCount = $productRows->isNotEmpty()
            ? $productRows->groupBy(function ($i) use ($sellerCol) {
                $sid = $i->product->{$sellerCol};
                return $sid !== null ? $sid : 'platform';
            })->count() + $packRows->count()
            : $packRows->count();

        return response()->json([
            'success'       => true,
            'message'       => 'Order placed successfully!',
            'order_number'  => $order->order_number,
            'order_id'      => $order->id,
            'total'         => $total,
            'seller_count'  => $sellerCount,
            'needs_payment' => $paymentMethod === 'card',
        ], 201);
    }

    /**
     * POST /api/checkout/buy-now
     * Single product direct purchase — bypasses cart. UNCHANGED.
     */
    public function buyNow(Request $request)
    {
        $request->validate([
            'product_id'     => 'required|integer|exists:products,id',
            'variant_id'     => 'nullable|integer|exists:product_variants,id',
            'quantity'       => 'required|integer|min:1|max:100',
            'wilaya'         => 'required|string|max:255',
            'address'        => 'required|string|max:500',
            'phone'          => 'required|string|max:30',
            'notes'          => 'nullable|string|max:1000',
            'payment_method' => 'nullable|string|in:cod,card,d17,wallet',
        ]);

        $user          = $request->user();
        $quantity      = (int) $request->quantity;
        $paymentMethod = $request->payment_method ?? 'cod';
        $product       = Product::find($request->product_id);

        if (!$product || !$product->is_approved || !$product->is_active) {
            return response()->json(['success' => false, 'message' => 'This product is no longer available.'], 422);
        }

        $variant = null;
        if ($request->filled('variant_id')) {
            $variant = ProductVariant::with('attributeOptions.attribute')
                ->where('id', $request->variant_id)
                ->where('product_id', $product->id)
                ->first();
            if (!$variant || !$variant->is_active) {
                return response()->json(['success' => false, 'message' => 'Selected variant is not available.'], 422);
            }
        }

        $stockPool = $variant ? $variant->stock : $product->stock;
        if ($stockPool < $quantity) {
            $label = $variant ? "\"{$product->name}\" ({$variant->label})" : "\"{$product->name}\"";
            return response()->json(['success' => false, 'message' => "{$label} only has {$stockPool} item(s) in stock."], 422);
        }

        $sellerCol  = $this->getSellerCol();
        $sellerId   = $product->{$sellerCol};
        $sellerPlan = 'free';
        if ($sellerId !== null) {
            $sellerPlan = SellerApplication::where('user_id', $sellerId)->first()?->plan ?? 'free';
        }

        $basePrice    = $variant ? (float) ($variant->price_override ?? $product->price) : (float) $product->price;
        $priceData    = $this->promoService->getEffectivePrice($product, $basePrice);
        $unitPrice    = $priceData['effective_price'];
        $commission   = $this->commissionService->calculate($unitPrice, $sellerPlan, $quantity);
        $subtotal     = $commission['total_price'];
        $deliveryFee = $product->getEffectiveDeliveryFee();
        $total       = round($subtotal + $deliveryFee, 3);

        $variantLabel = $variant ? $variant->attributeOptions->pluck('value')->join(' / ') : null;

        if ($paymentMethod === 'wallet' && (float) $user->wallet_balance < $total) {
            return response()->json(['success' => false, 'message' => 'Insufficient wallet balance.', 'data' => ['wallet_balance' => (float) $user->wallet_balance, 'required' => $total]], 422);
        }

        $decrementedVariants = [];
        $decrementedProducts = [];

        DB::beginTransaction();
        try {
            $order = Order::create([
                'user_id'        => $user->id,
                'order_number'   => 'ORD-' . strtoupper(Str::random(8)),
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'payment_method' => $paymentMethod,
                'total_amount'   => $total,
                'wilaya'         => $request->wilaya,
                'address'        => $request->address,
                'phone'          => $request->phone,
                'notes'          => $request->notes ?? null,
            ]);

            $sellerOrder = SellerOrder::create([
                'order_id'       => $order->id,
                'seller_id'      => $sellerId,
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'subtotal'       => $subtotal,
            ]);

            OrderItem::create([
                'order_id'              => $order->id,
                'seller_order_id'       => $sellerOrder->id,
                'product_id'            => $product->id,
                'variant_id'            => $variant?->id,
                'variant_label'         => $variantLabel,
                'product_name'          => $product->name,
                'quantity'              => $quantity,
                'unit_price'            => $unitPrice,
                'price'                 => $unitPrice,
                'total'                 => $commission['total_price'],
                'commission_percentage' => $commission['commission_percentage'],
                'commission_amount'     => $commission['commission_amount'],
                'seller_amount'         => $commission['seller_amount'],
                'plan_used'             => $commission['plan_used'],
            ]);

            if ($variant) {
                ProductVariant::where('id', $variant->id)->decrement('stock', $quantity);
                $decrementedVariants[] = ['variant_id' => $variant->id, 'product' => $product];
            } else {
                $product->decrement('stock', $quantity);
                $decrementedProducts[] = $product->id;
            }

            $this->financialSnapshot->freeze($sellerOrder->id);

            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            DB::commit();

            // ── CHANGE 3b: Log purchase activity for preferences ──────────────
            try {
                $this->preferenceService->logActivity(
                    userId:     $user->id,
                    productId:  $product->id,
                    categoryId: $product->category_id,
                    action:     'purchase',
                    sessionId:  $this->safeSessionId($request)
                );
            } catch (\Throwable $e) {
                Log::warning('[Preferences] buyNow purchase log failed: ' . $e->getMessage());
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] buyNow failed: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Failed to place order. Please try again.'], 500);
        }

        $this->fireStockAlerts($decrementedVariants, $decrementedProducts);

        try {
            if ($sellerId !== null) {
                SellerForecastController::clearForecastCache((int) $product->id, (int) $sellerId);
            }
        } catch (\Throwable $e) {
            Log::warning('[Forecast] Cache clear after buyNow() failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'       => true,
            'message'       => 'Order placed successfully!',
            'order_number'  => $order->order_number,
            'order_id'      => $order->id,
            'total'         => $total,
            'delivery_fee'  => $deliveryFee,
            'needs_payment' => $paymentMethod === 'card',
        ], 201);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fireStockAlerts(array $decrementedVariants, array $decrementedProducts): void
    {
        try {
            foreach ($decrementedVariants as $entry) {
                $freshVariant = ProductVariant::find($entry['variant_id']);
                if ($freshVariant) {
                    $freshVariant->load('attributeOptions.attribute');
                    $this->stockAlertService->checkVariant($freshVariant, $entry['product']);
                }
            }
            foreach ($decrementedProducts as $productId) {
                $freshProduct = Product::with('seller')->find($productId);
                if ($freshProduct) {
                    $this->stockAlertService->checkProduct($freshProduct);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[Checkout] fireStockAlerts failed: ' . $e->getMessage());
        }
    }

    private function calculateCartTotal($cartItems): float
    {
        $subtotal = $cartItems->sum(function ($item) {
            if ($item->isPack()) {
                return (float) $item->pack_price_snapshot;
            }
            $basePrice = $item->variant
                ? (float) ($item->variant->price_override ?? $item->product->price)
                : (float) $item->product->price;
            $priceData = $this->promoService->getEffectivePrice($item->product, $basePrice);
            return round($priceData['effective_price'] * $item->quantity, 3);
        });
 
        $deliveryFee = $this->resolveCartDeliveryFee($cartItems);
 
        return round($subtotal + $deliveryFee, 3);
    }
    private function resolveCartDeliveryFee($cartItems): float
    {
        // Packs always require delivery
        if ($cartItems->contains(fn($i) => $i->isPack())) {
            return \App\Models\Product::DEFAULT_DELIVERY_FEE;
        }
 
        // Check if every product in the cart has free delivery
        $allFreeDelivery = $cartItems->every(function ($item) {
            return $item->product && $item->product->isFreeDelivery();
        });
 
        return $allFreeDelivery ? 0.0 : \App\Models\Product::DEFAULT_DELIVERY_FEE;
    }

    private function getSellerCol(): string
    {
        static $col = null;
        if ($col) return $col;
        $columns  = DB::select('SHOW COLUMNS FROM products');
        $colNames = array_map(fn($c) => $c->Field, $columns);
        $col      = in_array('seller_id', $colNames) ? 'seller_id' : 'user_id';
        return $col;
    }

    protected function ensureNotProductOwner(Request $request, Product $product): void
    {
        $sellerCol = $this->getSellerCol();
        $sellerId  = $product->{$sellerCol};
        if ($sellerId === null) return;
        if ($sellerId === $request->user()->id) {
            abort(422, 'You cannot purchase your own product.');
        }
    }

    // ── CHANGE 4: Safe session ID helper ─────────────────────────────────────
    private function safeSessionId(Request $request): ?string
    {
        try {
            return $request->session()->getId();
        } catch (\Throwable $e) {
            return null; // API routes may not have a session — that's fine
        }
    }
}