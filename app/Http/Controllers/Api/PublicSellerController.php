<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\SellerFollow;
use App\Models\User;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;

/**
 * PublicSellerController
 *
 * Public (no auth) storefront endpoint for a single seller.
 *   GET /api/sellers/{id} — seller header/branding + active promotions
 *
 * Product listing itself is NOT duplicated here — the storefront's product
 * grid calls the existing GET /api/products?seller_id={id}, which already
 * gives pagination, sorting, category filters and promo pricing for free.
 *
 * "is_following" is intentionally NOT included here — this endpoint stays
 * fully public/unauthenticated. The frontend checks follow state separately
 * via GET /api/seller-follows/check/{id} only when the visitor is logged in.
 */
class PublicSellerController extends Controller
{
    public function __construct(private PromotionService $promoService) {}

    public function show($id): JsonResponse
    {
        $seller = User::approvedSellers()->where('id', $id)->first();

        if (!$seller) {
            return response()->json(['success' => false, 'message' => __('messages.not_found.seller')], 404);
        }

        $application = SellerApplication::where('user_id', $seller->id)
            ->approved()
            ->latest()
            ->first();

        $branding       = $seller->storefrontBranding();
        $totalProducts  = Product::available()->where('seller_id', $seller->id)->count();
        $followersCount = SellerFollow::where('seller_id', $seller->id)->count();

        $data = [
            'id'                 => $seller->id,
            'name'               => $seller->name,
            'business_name'      => $branding['business_name'],
            'business_description' => $application?->business_description ?? null,
            'plan'               => $application?->plan ?? 'free',
            'wilaya'             => $application?->wilaya ?? null,
            'avatar'             => $branding['avatar'],
            'cover_photo'        => $branding['cover_photo'],
            'total_products'     => $totalProducts,
            'followers_count'    => $followersCount,
            'member_since'       => $seller->created_at?->toISOString(),
            'promotions'         => $this->promoService
                ->getActivePromotionsFormatted(fn ($q) => $q->where('seller_id', $seller->id))
                ->values(),
            'coupons'            => Coupon::where('seller_id', $seller->id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'))
                ->withCount('products')
                ->get()
                ->map(fn ($c) => [
                    'code'              => $c->code,
                    'discount_label'    => $c->discount_label,
                    'min_order_amount'  => $c->min_order_amount !== null ? (float) $c->min_order_amount : null,
                    'product_count'     => $c->products_count,
                ])
                ->values(),
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }
}
