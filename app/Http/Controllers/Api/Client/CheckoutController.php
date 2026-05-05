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
use App\Services\WalletService;
use App\Services\StockAlertService;
use App\Services\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(
        private WalletService     $walletService,
        private StockAlertService $stockAlertService,
        private PromotionService  $promoService,
        private CommissionService $commissionService,
    ) {}

    /**
     * POST /api/checkout
     *
     * Handles two types of cart rows transparently:
     *   A) Regular product rows  (product_id set, pack_id null)  — original logic
     *   B) Pack bundle rows      (pack_id set, product_id null)  — new branch
     *
     * Both types flow through the same Order / SellerOrder / OrderItem system.
     * Commission is calculated and stored per OrderItem at creation time.
     */
    public function store(Request $request)
    {
        $request->validate([
            'wilaya'         => 'required|string|max:255',
            'address'        => 'required|string|max:500',
            'phone'          => 'required|string|max:30',
            'notes'          => 'nullable|string|max:1000',
            'payment_method' => 'nullable|string|in:cod,card,d17,wallet',
        ]);

        $user = $request->user();

        $cartItems = Cart::with([
            'product',
            'variant.attributeOptions.attribute',
            'pack.items.product',
            'pack.items.product.variants.attributeOptions.attribute',
        ])->where('user_id', $user->id)->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty.',
            ], 422);
        }

        $paymentMethod = $request->payment_method ?? 'cod';

        // ── Pre-flight validation ─────────────────────────────────────────────
        foreach ($cartItems as $item) {

            if ($item->isPack()) {
                $pack = $item->pack;

                if (!$pack || !$pack->is_active || !$pack->is_approved) {
                    return response()->json([
                        'success' => false,
                        'message' => "The bundle \"{$item->pack_name}\" is no longer available.",
                    ], 422);
                }

                $selectionMap = collect($item->pack_selections ?? [])->keyBy('pack_item_id');

                foreach ($pack->items as $packItem) {
                    $sel       = $selectionMap->get($packItem->id);
                    $variantId = $sel['variant_id'] ?? null;
                    $product   = $packItem->product;

                    if (!$product || !$product->is_approved || !$product->is_active) {
                        return response()->json([
                            'success' => false,
                            'message' => "A product in bundle \"{$pack->name}\" is no longer available.",
                        ], 422);
                    }

                    $stock = $variantId
                        ? (ProductVariant::find($variantId)?->stock ?? 0)
                        : $product->stock;

                    if ($stock < $packItem->quantity) {
                        return response()->json([
                            'success' => false,
                            'message' => "\"{$product->name}\" in bundle \"{$pack->name}\" only has {$stock} item(s) in stock.",
                        ], 422);
                    }
                }

            } else {
                $product = $item->product;

                if (!$product || !$product->is_approved || !$product->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => "\"$product->name\" is no longer available.",
                    ], 422);
                }

                $this->ensureNotProductOwner($request, $product);

                $stockPool = $item->variant
                    ? $item->variant->stock
                    : $product->stock;

                if ($stockPool < $item->quantity) {
                    $label = $item->variant
                        ? "\"{$product->name}\" ({$item->variant->label})"
                        : "\"{$product->name}\"";

                    return response()->json([
                        'success' => false,
                        'message' => "{$label} only has {$stockPool} item(s) in stock but {$item->quantity} were requested.",
                    ], 422);
                }
            }
        }

        // ── Calculate total ───────────────────────────────────────────────────
        $sellerCol = $this->getSellerCol();
        $total     = $this->calculateCartTotal($cartItems);

        if ($paymentMethod === 'wallet') {
            if ((float) $user->wallet_balance < $total) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance.',
                    'data'    => [
                        'wallet_balance' => (float) $user->wallet_balance,
                        'required'       => $total,
                    ],
                ], 422);
            }
        }

        $decrementedVariants = [];
        $decrementedProducts = [];

        // ── Cache seller plans once per checkout — avoids N+1 on SellerApplication ──
        // Shared between product rows (A) and pack rows (B).
        $sellerPlanCache = [];

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

            // ── Separate product rows from pack rows ──────────────────────────
            $productRows = $cartItems->filter(fn($i) => !$i->isPack());
            $packRows    = $cartItems->filter(fn($i) =>  $i->isPack());

            // ── A) Process regular product rows ───────────────────────────────
            if ($productRows->isNotEmpty()) {
                $groupedBySeller = $productRows->groupBy(function ($item) use ($sellerCol) {
                    return $item->product->{$sellerCol};
                });

                foreach ($groupedBySeller as $sellerId => $sellerItems) {

                    // Resolve and cache this seller's active plan
                    if (!isset($sellerPlanCache[$sellerId])) {
                        $app = SellerApplication::where('user_id', $sellerId)->first();
                        $sellerPlanCache[$sellerId] = $app?->plan ?? 'free';
                    }
                    $sellerPlan = $sellerPlanCache[$sellerId];

                    $sellerSubtotal = $sellerItems->sum(function ($item) {
                        $basePrice = $item->variant
                            ? (float) ($item->variant->price_override ?? $item->product->price)
                            : (float) $item->product->price;
                        $priceData = $this->promoService->getEffectivePrice($item->product, $basePrice);
                        return round($priceData['effective_price'] * $item->quantity, 3);
                    });

                    $sellerOrder = SellerOrder::create([
                        'order_id'       => $order->id,
                        'seller_id'      => $sellerId,
                        'status'         => 'pending',
                        'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                        'subtotal'       => $sellerSubtotal,
                    ]);

                    foreach ($sellerItems as $item) {
                        $product = $item->product;
                        $variant = $item->variant;

                        $basePrice = $variant
                            ? (float) ($variant->price_override ?? $product->price)
                            : (float) $product->price;
                        $priceData = $this->promoService->getEffectivePrice($product, $basePrice);
                        $unitPrice = $priceData['effective_price'];

                        $qty = (int) $item->quantity;

                        // ── Calculate commission for this line item ───────────
                        $commission = $this->commissionService->calculate($unitPrice, $sellerPlan, $qty);

                        $variantLabel = $variant
                            ? $variant->attributeOptions->pluck('value')->join(' / ')
                            : null;

                        OrderItem::create([
                            'order_id'               => $order->id,
                            'seller_order_id'        => $sellerOrder->id,
                            'product_id'             => $product->id,
                            'variant_id'             => $variant?->id,
                            'variant_label'          => $variantLabel,
                            'product_name'           => $product->name,
                            'quantity'               => $qty,
                            'unit_price'             => $unitPrice,
                            'price'                  => $unitPrice,
                            'total'                  => $commission['total_price'],
                            // ── Commission snapshot ───────────────────────────
                            'commission_percentage'  => $commission['commission_percentage'],
                            'commission_amount'      => $commission['commission_amount'],
                            'seller_amount'          => $commission['seller_amount'],
                            'plan_used'              => $commission['plan_used'],
                        ]);

                        if ($variant) {
                            ProductVariant::where('id', $variant->id)
                                ->decrement('stock', $item->quantity);
                            $decrementedVariants[] = [
                                'variant_id' => $variant->id,
                                'product'    => $product,
                            ];
                        } else {
                            $product->decrement('stock', $item->quantity);
                            $decrementedProducts[] = $product->id;
                        }
                    }
                }
            }

            // ── B) Process pack rows ──────────────────────────────────────────
            foreach ($packRows as $cartRow) {
                $pack         = $cartRow->pack;
                $selectionMap = collect($cartRow->pack_selections ?? [])->keyBy('pack_item_id');
                $packPrice    = (float) $cartRow->pack_price_snapshot;

                $packItemsBySeller = $pack->items->groupBy(
                    fn($pi) => $pi->product->{$sellerCol}
                );

                foreach ($packItemsBySeller as $sellerId => $packItems) {
                    $proportion     = $packItems->count() / $pack->items->count();
                    $sellerSubtotal = round($packPrice * $proportion, 3);

                    // Resolve and cache this seller's active plan
                    if (!isset($sellerPlanCache[$sellerId])) {
                        $app = SellerApplication::where('user_id', $sellerId)->first();
                        $sellerPlanCache[$sellerId] = $app?->plan ?? 'free';
                    }
                    $sellerPlan = $sellerPlanCache[$sellerId];

                    $sellerOrder = SellerOrder::create([
                        'order_id'       => $order->id,
                        'seller_id'      => $sellerId,
                        'status'         => 'pending',
                        'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                        'subtotal'       => $sellerSubtotal,
                    ]);

                    foreach ($packItems as $packItem) {
                        $product   = $packItem->product;
                        $sel       = $selectionMap->get($packItem->id);
                        $variantId = $sel['variant_id'] ?? null;
                        $variant   = $variantId
                            ? $product->variants->firstWhere('id', $variantId)
                            : null;

                        $unitPrice = $variant
                            ? (float) ($variant->price_override ?? $product->price)
                            : (float) $product->price;

                        $qty = (int) $packItem->quantity;

                        // ── Commission on pack item — uses total pack price proportioned per item ──
                        // For packs we commission on the individual product's unit price
                        // (the pack discount is the seller's marketing cost, not ours).
                        $commission = $this->commissionService->calculate($unitPrice, $sellerPlan, $qty);

                        $variantLabel = $variant
                            ? $variant->attributeOptions->pluck('value')->join(' / ')
                            : null;

                        OrderItem::create([
                            'order_id'               => $order->id,
                            'seller_order_id'        => $sellerOrder->id,
                            'product_id'             => $product->id,
                            'variant_id'             => $variantId,
                            'variant_label'          => $variantLabel,
                            'product_name'           => $product->name . ' (Bundle: ' . $pack->name . ')',
                            'quantity'               => $qty,
                            'unit_price'             => $unitPrice,
                            'price'                  => $unitPrice,
                            'total'                  => $commission['total_price'],
                            // ── Commission snapshot ───────────────────────────
                            'commission_percentage'  => $commission['commission_percentage'],
                            'commission_amount'      => $commission['commission_amount'],
                            'seller_amount'          => $commission['seller_amount'],
                            'plan_used'              => $commission['plan_used'],
                        ]);

                        if ($variant) {
                            ProductVariant::where('id', $variantId)
                                ->decrement('stock', $packItem->quantity);
                            $decrementedVariants[] = [
                                'variant_id' => $variantId,
                                'product'    => $product,
                            ];
                        } else {
                            $product->decrement('stock', $packItem->quantity);
                            $decrementedProducts[] = $product->id;
                        }
                    }
                }
            }

            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            Cart::where('user_id', $user->id)->delete();

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] store failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order. Please try again.',
            ], 500);
        }

        $this->fireStockAlerts($decrementedVariants, $decrementedProducts);

        $sellerCount = $productRows->isNotEmpty()
            ? $productRows->groupBy(fn($i) => $i->product->{$sellerCol})->count()
                + $packRows->count()
            : $packRows->count();

        return response()->json([
            'success'        => true,
            'message'        => 'Order placed successfully!',
            'order_number'   => $order->order_number,
            'order_id'       => $order->id,
            'total'          => $total,
            'seller_count'   => $sellerCount,
            'needs_payment'  => $paymentMethod === 'card',
        ], 201);
    }

    /**
     * POST /api/checkout/buy-now
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

        $product = Product::find($request->product_id);

        if (!$product || !$product->is_approved || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This product is no longer available.',
            ], 422);
        }

        $variant = null;
        if ($request->filled('variant_id')) {
            $variant = ProductVariant::with('attributeOptions.attribute')
                ->where('id', $request->variant_id)
                ->where('product_id', $product->id)
                ->first();

            if (!$variant || !$variant->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variant is not available.',
                ], 422);
            }
        }

        $stockPool = $variant ? $variant->stock : $product->stock;

        if ($stockPool < $quantity) {
            $label = $variant
                ? "\"{$product->name}\" ({$variant->label})"
                : "\"{$product->name}\"";

            return response()->json([
                'success' => false,
                'message' => "{$label} only has {$stockPool} item(s) in stock.",
            ], 422);
        }

        $basePrice = $variant
            ? (float) ($variant->price_override ?? $product->price)
            : (float) $product->price;
        $priceData = $this->promoService->getEffectivePrice($product, $basePrice);
        $unitPrice = $priceData['effective_price'];

        // ── Resolve seller plan for commission ────────────────────────────────
        $sellerCol  = $this->getSellerCol();
        $sellerId   = $product->{$sellerCol};
        $app        = SellerApplication::where('user_id', $sellerId)->first();
        $sellerPlan = $app?->plan ?? 'free';

        // ── Calculate commission ──────────────────────────────────────────────
        $commission = $this->commissionService->calculate($unitPrice, $sellerPlan, $quantity);

        $subtotal     = $commission['total_price'];
        $total        = round($subtotal + 8, 3);
        $variantLabel = $variant
            ? $variant->attributeOptions->pluck('value')->join(' / ')
            : null;

        if ($paymentMethod === 'wallet') {
            if ((float) $user->wallet_balance < $total) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient wallet balance.',
                    'data'    => [
                        'wallet_balance' => (float) $user->wallet_balance,
                        'required'       => $total,
                    ],
                ], 422);
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

            $sellerOrder = SellerOrder::create([
                'order_id'       => $order->id,
                'seller_id'      => $sellerId,
                'status'         => 'pending',
                'payment_status' => $paymentMethod === 'wallet' ? 'paid' : 'unpaid',
                'subtotal'       => $subtotal,
            ]);

            OrderItem::create([
                'order_id'               => $order->id,
                'seller_order_id'        => $sellerOrder->id,
                'product_id'             => $product->id,
                'variant_id'             => $variant?->id,
                'variant_label'          => $variantLabel,
                'product_name'           => $product->name,
                'quantity'               => $quantity,
                'unit_price'             => $unitPrice,
                'price'                  => $unitPrice,
                'total'                  => $commission['total_price'],
                // ── Commission snapshot ───────────────────────────────────────
                'commission_percentage'  => $commission['commission_percentage'],
                'commission_amount'      => $commission['commission_amount'],
                'seller_amount'          => $commission['seller_amount'],
                'plan_used'              => $commission['plan_used'],
            ]);

            if ($variant) {
                ProductVariant::where('id', $variant->id)->decrement('stock', $quantity);
                $decrementedVariants[] = ['variant_id' => $variant->id, 'product' => $product];
            } else {
                $product->decrement('stock', $quantity);
                $decrementedProducts[] = $product->id;
            }

            if ($paymentMethod === 'wallet') {
                $this->walletService->deductForOrder($user, $order);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Checkout] buyNow failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order. Please try again.',
            ], 500);
        }

        $this->fireStockAlerts($decrementedVariants, $decrementedProducts);

        return response()->json([
            'success'       => true,
            'message'       => 'Order placed successfully!',
            'order_number'  => $order->order_number,
            'order_id'      => $order->id,
            'total'         => $total,
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

    /**
     * calculateCartTotal — uses effective (post-promotion) price for product rows.
     * Pack rows use pack_price_snapshot directly.
     */
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

        return round($subtotal + 8, 3);
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
        if ($product->{$sellerCol} === $request->user()->id) {
            abort(422, 'You cannot purchase your own product.');
        }
    }
}