<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerCouponController extends Controller
{
    // ── Index ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $seller = $request->user();

        $query = Coupon::where('seller_id', $seller->id)
            ->with(['products:id,name,slug,price', 'products.primaryImage'])
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('created_at');

        $coupons = $query->paginate((int) $request->input('per_page', 12));
        $coupons->getCollection()->transform(fn ($c) => $this->format($c));

        return response()->json(['success' => true, 'data' => $coupons]);
    }

    // ── Show ───────────────────────────────────────────────────────────────

    public function show(Request $request, int $id)
    {
        $coupon = Coupon::where('seller_id', $request->user()->id)
            ->with(['products:id,name,slug,price', 'products.primaryImage'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->format($coupon)]);
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $seller = $request->user();

        try {
            $validated = $request->validate([
                'code'                       => 'required|string|max:64|alpha_dash|unique:coupons,code',
                'discount_type'              => 'required|in:percentage,fixed',
                'discount_value'             => 'required|numeric|min:0.001',
                'min_order_amount'           => 'nullable|numeric|min:0',
                'usage_limit'                => 'nullable|integer|min:1',
                'usage_limit_per_customer'   => 'nullable|integer|min:1',
                'is_active'                  => 'nullable|boolean',
                'product_ids'                => 'required|array|min:1',
                'product_ids.*'              => 'integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        }

        if ($validated['discount_type'] === 'percentage' && $validated['discount_value'] > 100) {
            return response()->json([
                'success' => false,
                'errors'  => ['discount_value' => ['A percentage discount cannot exceed 100%.']],
            ], 422);
        }

        $productIds = array_unique($validated['product_ids']);
        $owned = Product::where('seller_id', $seller->id)
            ->whereIn('id', $productIds)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->count();

        if ($owned !== count($productIds)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more products are invalid or do not belong to you.',
            ], 403);
        }

        DB::beginTransaction();
        try {
            $coupon = Coupon::create([
                'seller_id'                 => $seller->id,
                'code'                      => strtoupper($validated['code']),
                'discount_type'             => $validated['discount_type'],
                'discount_value'            => $validated['discount_value'],
                'min_order_amount'          => $validated['min_order_amount'] ?? null,
                'usage_limit'               => $validated['usage_limit'] ?? null,
                'usage_limit_per_customer'  => $validated['usage_limit_per_customer'] ?? null,
                'usage_count'               => 0,
                'is_active'                 => $validated['is_active'] ?? true,
            ]);

            $coupon->products()->attach($productIds);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Coupon created successfully.',
                'data'    => $this->format($coupon->load(['products:id,name,slug,price', 'products.primaryImage'])),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function update(Request $request, int $id)
    {
        $coupon = Coupon::where('seller_id', $request->user()->id)->findOrFail($id);

        try {
            $validated = $request->validate([
                'discount_value'            => 'sometimes|numeric|min:0.001',
                'min_order_amount'          => 'sometimes|nullable|numeric|min:0',
                'usage_limit'               => 'sometimes|nullable|integer|min:1',
                'usage_limit_per_customer'  => 'sometimes|nullable|integer|min:1',
                'is_active'                 => 'sometimes|boolean',
                'product_ids'               => 'sometimes|array|min:1',
                'product_ids.*'             => 'integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $coupon->update(array_filter([
                'discount_value'            => $validated['discount_value']           ?? null,
                'min_order_amount'          => array_key_exists('min_order_amount', $validated) ? $validated['min_order_amount'] : null,
                'usage_limit'               => array_key_exists('usage_limit', $validated) ? $validated['usage_limit'] : null,
                'usage_limit_per_customer'  => array_key_exists('usage_limit_per_customer', $validated) ? $validated['usage_limit_per_customer'] : null,
                'is_active'                 => array_key_exists('is_active', $validated) ? $validated['is_active'] : null,
            ], fn ($v) => $v !== null));

            if (isset($validated['product_ids'])) {
                $productIds = array_unique($validated['product_ids']);
                $owned = Product::where('seller_id', $request->user()->id)
                    ->whereIn('id', $productIds)->count();
                if ($owned !== count($productIds)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more products are invalid or do not belong to you.',
                    ], 403);
                }
                $coupon->products()->sync($productIds);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Coupon updated.',
                'data'    => $this->format($coupon->load(['products:id,name,slug,price', 'products.primaryImage'])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Destroy ────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $id)
    {
        $coupon = Coupon::where('seller_id', $request->user()->id)->findOrFail($id);
        $coupon->delete();
        return response()->json(['success' => true, 'message' => 'Coupon deleted.']);
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    public function stats(Request $request)
    {
        $sellerId = $request->user()->id;

        return response()->json(['success' => true, 'data' => [
            'total'           => Coupon::where('seller_id', $sellerId)->count(),
            'active'          => Coupon::where('seller_id', $sellerId)->where('is_active', true)->count(),
            'total_redemptions' => \App\Models\CouponRedemption::whereHas(
                'coupon', fn ($q) => $q->where('seller_id', $sellerId)
            )->count(),
            'total_discount_given' => (float) \App\Models\CouponRedemption::whereHas(
                'coupon', fn ($q) => $q->where('seller_id', $sellerId)
            )->sum('discount_amount'),
        ]]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function format(Coupon $c): array
    {
        $products = $c->relationLoaded('products') ? $c->products : collect();
        return [
            'id'                        => $c->id,
            'code'                      => $c->code,
            'discount_type'             => $c->discount_type,
            'discount_value'            => (float) $c->discount_value,
            'discount_label'            => $c->discount_label,
            'min_order_amount'          => $c->min_order_amount !== null ? (float) $c->min_order_amount : null,
            'usage_limit'               => $c->usage_limit,
            'usage_limit_per_customer'  => $c->usage_limit_per_customer,
            'usage_count'               => $c->usage_count,
            'is_active'                 => $c->is_active,
            'products_count'            => $products->count(),
            'products'                  => $products->map(fn ($p) => [
                'id'                => $p->id,
                'name'              => $p->name,
                'slug'              => $p->slug,
                'price'             => (float) $p->price,
                'primary_image_url' => $p->primary_image_url,
            ])->values(),
            'created_at'                => $c->created_at?->toISOString(),
        ];
    }
}
