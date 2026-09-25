<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Coupon;
use App\Services\CouponService;
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
     * Body: { code: string }
     */
    public function preview(Request $request)
    {
        $this->validate($request, ['code' => 'required|string']);
        $code = strtoupper($request->input('code'));
        $user = $request->user();

        $coupon = Coupon::where('code', $code)->first();
        if (!$coupon) {
            return response()->json(['success' => false, 'message' => __('messages.coupon.invalid')], 404);
        }

        // Non-pack cart rows for this coupon's seller only — packs never
        // carry promotions and, by the same precedent, never carry coupons.
        $items = Cart::where('user_id', $user->id)
            ->whereNull('pack_id')
            ->with('product:id,seller_id,price', 'variant:id,price_override')
            ->get()
            ->filter(fn ($item) => $item->product && $item->product->seller_id === $coupon->seller_id)
            ->map(function ($item) {
                $basePrice = $item->variant?->price_override ?? $item->product->price;
                $promo = app(\App\Services\PromotionService::class)->getEffectivePrice($item->product, (float) $basePrice);
                return [
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'line_total' => round($promo['effective_price'] * $item->quantity, 3),
                ];
            })
            ->values()
            ->all();

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
