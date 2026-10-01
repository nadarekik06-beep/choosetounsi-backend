<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CouponService;
use App\Services\PromotionService;
use Illuminate\Http\Request;

/**
 * Preview-only coupon validation for the checkout page. Never redeems —
 * redemption (usage_count increment + CouponRedemption row) only happens
 * for real inside CheckoutController::store()/buyNow() at order-placement
 * time, which re-validates from scratch rather than trusting this preview.
 */
class CouponController extends Controller
{
    public function __construct(private CouponService $couponService) {}

    /**
     * POST /api/coupons/validate
     * Body: { code: string, item_ids?: int[] }                       cart checkout
     *    or { code: string, product_id, variant_id?, quantity }      buy-now
     *
     * Evaluates exactly the lines being checked out, priced like checkout does,
     * so the previewed discount is the one the order gets.
     */
    public function preview(Request $request)
    {
        $this->validate($request, [
            'code'       => 'required|string',
            'item_ids'   => 'nullable|array',
            'item_ids.*' => 'integer',
            'product_id' => 'nullable|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity'   => 'nullable|integer|min:1|max:100',
        ]);
        $code = strtoupper($request->input('code'));
        $user = $request->user();

        $coupon = Coupon::where('code', $code)->first();
        if (!$coupon) {
            return response()->json(['success' => false, 'message' => __('messages.coupon.invalid')], 404);
        }

        $promotions = app(PromotionService::class);

        if ($request->filled('product_id')) {
            // Buy-now: the single line CheckoutController::buyNow() prices
            $product = Product::find($request->product_id);
            $variant = $request->filled('variant_id')
                ? ProductVariant::where('product_id', $product->id)->find($request->variant_id)
                : null;
            $qty     = (int) $request->input('quantity', 1);
            $items   = $product->seller_id === $coupon->seller_id ? [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'line_total' => round($promotions->priceLine($product, $variant)['final_price'] * $qty, 3),
            ]] : [];
        } else {
            // Non-pack cart rows (the selected ones) for this coupon's seller only —
            // packs never carry promotions and, by the same precedent, never carry coupons.
            $items = Cart::where('user_id', $user->id)
                ->whereNull('pack_id')
                ->when($request->filled('item_ids'), fn ($q) => $q->whereIn('id', $request->input('item_ids')))
                ->with('product:id,seller_id,price', 'variant:id,price_override')
                ->get()
                ->filter(fn ($item) => $item->product && $item->product->seller_id === $coupon->seller_id)
                ->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'line_total' => round($promotions->priceLine($item->product, $item->variant)['final_price'] * $item->quantity, 3),
                ])
                ->values()
                ->all();
        }

        $result = $this->couponService->validateForSeller($code, $coupon->seller_id, $user->id, $items);

        return response()->json([
            'success'          => $result['valid'],
            'message'          => $result['message'],
            'seller_id'        => $coupon->seller_id,
            'discount_amount'  => $result['discount_amount'],
            'eligible_product_ids' => $result['eligible_product_ids'],
        ], $result['valid'] ? 200 : 422);
    }
}
