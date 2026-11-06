<?php
namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\PromotionService;
use App\Support\TunisTime;
use App\Services\GrowthRadar\GrowthActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerPromotionController extends Controller
{
    /** A start typed a few minutes ago (form opened, then submitted) is still "now" (AUDIT L-8). */
    private const START_GRACE_MINUTES = 10;

    public function __construct(private PromotionService $promoService) {}

    /**
     * POST /api/seller/promotions/preview — what buyers would really pay with
     * this discount, per product and variant: the exact pricing checkout uses
     * (PromotionService::preview → the same pricing() OrderPricing charges,
     * 30-day reference price, millime rounding). AUDIT M-6.
     */
    public function preview(Request $request)
    {
        $v = $request->validate([
            'product_ids'    => 'required|array|min:1|max:100',
            'product_ids.*'  => 'integer',
            'discount_type'  => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
        ]);
        $own = Product::where('seller_id', $request->user()->id)
            ->whereIn('id', array_map('intval', $v['product_ids']))->pluck('id')->map(fn ($i) => (int) $i)->all();

        return response()->json(['success' => true, 'data' => $this->promoService->preview(
            $own, $v['discount_type'], (float) $v['discount_value'],
        )]);
    }

    // ── Index ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $seller     = $request->user();
        $type       = $request->query('type'); // 'flash_sale' | 'discount' | null
        $statusFilter = $request->query('status');

        $query = Promotion::where('seller_id', $seller->id)
            ->with(['products:id,name,slug,price', 'products.primaryImage'])
            ->when($type,         fn($q) => $q->where('type', $type))
            ->when($statusFilter, fn($q) => $q->where('status', $statusFilter))
            ->orderByDesc('created_at');

        // Auto-expire before returning list
        $this->syncExpiredStatuses($seller->id);

        $promotions = $query->paginate((int)$request->input('per_page', 12));
        $promotions->getCollection()->transform(fn($p) => $this->format($p));

        return response()->json(['success' => true, 'data' => $promotions]);
    }

    // ── Show ───────────────────────────────────────────────────────────────

    public function show(Request $request, int $id)
    {
        $promo = Promotion::where('seller_id', $request->user()->id)
            ->with(['products:id,name,slug,price', 'products.primaryImage'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->format($promo)]);
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function store(Request $request, GrowthActions $actions)
    {
        $seller = $request->user();

        try {
            $validated = $request->validate([
                'name'           => 'required|string|max:255',
                'type'           => 'required|in:flash_sale,discount',
                'discount_type'  => 'required|in:percentage,fixed',
                'discount_value' => 'required|numeric|min:0.001',
                'starts_at' => 'required|date',
                'ends_at'        => 'required|date|after:starts_at',
                'flash_stock'    => 'nullable|integer|min:1',
                'per_customer_limit' => 'nullable|integer|min:1|max:100',
                'product_ids'    => 'required|array|min:1',
                'product_ids.*'  => 'integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        }

        // Typed in Tunis time, stored in UTC (AUDIT L-3); never started in the past (AUDIT L-8)
        $validated['starts_at'] = TunisTime::toUtcString($validated['starts_at']);
        $validated['ends_at']   = TunisTime::toUtcString($validated['ends_at']);
        if (\Carbon\Carbon::parse($validated['starts_at'])->lt(now()->subMinutes(self::START_GRACE_MINUTES))) {
            return response()->json(['success' => false, 'errors' => ['starts_at' => [__('seller.promotion.start_in_past')]]], 422);
        }

        // Business rule validation — return as field-keyed errors so frontend shows inline
$businessErrors = $this->promoService->validate($validated, $validated['type']);
if (!empty($businessErrors)) {
    // Map each error message to the relevant field
    $fieldErrors = [];
    foreach ($businessErrors as $msg) {
        if (str_contains($msg, 'hour') || str_contains($msg, 'day') || str_contains($msg, 'exceed')) {
            $fieldErrors['ends_at'][] = $msg;
        } elseif (str_contains($msg, 'discount') || str_contains($msg, '%')) {
            $fieldErrors['discount_value'][] = $msg;
        } else {
            $fieldErrors['general'][] = $msg;
        }
    }
    return response()->json(['success' => false, 'errors' => $fieldErrors], 422);
}

        // Verify seller owns all products
        $productIds = array_unique($request->product_ids);
        $owned = Product::where('seller_id', $seller->id)
            ->whereIn('id', $productIds)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->count();

        if ($owned !== count($productIds)) {
            return response()->json([
                'success' => false,
                'message' => __('seller.common.products_not_yours'),
            ], 403);
        }

        // Final price must stay above 0 DT (fixed amount ≥ price, on any variant)
        if ($zero = $this->promoService->zeroPriceProducts($productIds, $validated['discount_type'], (float) $validated['discount_value'])) {
            return response()->json(['success' => false, 'errors' => [
                'discount_value' => [__('seller.promotion.zero_price', ['products' => implode(', ', $zero)])],
            ]], 422);
        }

        // One promotion per product at a time (flash sale or discount, seller or admin)
        if ($conflict = $this->conflictResponse($productIds, $validated['starts_at'], $validated['ends_at'])) {
            return $conflict;
        }

        DB::beginTransaction();
        try {
            $status = now()->lessThan($validated['starts_at']) ? 'scheduled' : 'active';

            $promo = Promotion::create([
                'seller_id'      => $seller->id,
                'name'           => $validated['name'],
                'type'           => $validated['type'],
                'discount_type'  => $validated['discount_type'],
                'discount_value' => $validated['discount_value'],
                'starts_at'      => $validated['starts_at'],
                'ends_at'        => $validated['ends_at'],
                'flash_stock'    => $validated['type'] === 'flash_sale' ? ($validated['flash_stock'] ?? null) : null,
                'per_customer_limit' => $validated['type'] === 'flash_sale' ? ($validated['per_customer_limit'] ?? Promotion::DEFAULT_PER_CUSTOMER_LIMIT) : null,
                'funded_by'      => Promotion::FUNDED_SELLER,
                'status'         => $status,
                'priority'       => $validated['type'] === 'flash_sale' ? 10 : 5,
            ]);

            $promo->products()->attach($productIds);

            // Opened from a Growth Radar card: remember it so the result can be measured
            if ($card = $actions->cardFromRequest($request)) {
                $actions->record($seller->id, $card, $promo->type, $promo->id,
                    in_array((int) $card->product_id, array_map('intval', $productIds), true) ? (int) $card->product_id : (int) $productIds[0],
                    $promo->starts_at, $promo->ends_at);
            }
            // Opened from Analyse des visiteurs: remember it for the before / after funnel
            app(\App\Services\VisitorInsights\InsightActions::class)
                ->recordFromRequest($request, $promo->type, $promo->id, $productIds, $promo->starts_at, $promo->ends_at);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('seller.promotion.created'),
                'data'    => $this->format($promo->load(['products:id,name,slug,price', 'products.primaryImage'])
),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Update ─────────────────────────────────────────────────────────────

    /**
     * Editable: name, discount value, end date, flash quota, products, status
     * (active | paused). Type, discount type and start date are fixed once created.
     * Same rules as store(): ownership, caps, final price > 0, one promotion per product.
     */
    public function update(Request $request, int $id)
    {
        $seller = $request->user();
        $promo  = Promotion::where('seller_id', $seller->id)->findOrFail($id);

        if ($promo->isAdminPromotion()) {
            return response()->json(['success' => false, 'message' => __('seller.promotion.admin_read_only')], 403);
        }
        if ($promo->status === 'expired') {
            return response()->json(['success' => false, 'message' => __('seller.promotion.expired')], 422);
        }

        try {
            $request->validate([
                'name'           => 'sometimes|required|string|max:255',
                'discount_value' => 'sometimes|required|numeric|min:0.001',
                'ends_at'        => 'sometimes|required|date',
                'flash_stock'    => 'nullable|integer|min:1',
                'per_customer_limit' => 'nullable|integer|min:1|max:100',
                'status'         => 'sometimes|required|in:active,scheduled,paused',
                'product_ids'    => 'sometimes|required|array|min:1',
                'product_ids.*'  => 'integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        }

        $data = [
            'name'           => $request->input('name', $promo->name),
            'discount_type'  => $promo->discount_type,
            'discount_value' => (float) $request->input('discount_value', $promo->discount_value),
            'starts_at'      => $promo->starts_at->toDateTimeString(),
            'ends_at'        => $request->filled('ends_at') ? TunisTime::toUtcString($request->input('ends_at')) : $promo->ends_at->toDateTimeString(),
        ];
        if ($errors = $this->promoService->validate($data, $promo->type)) {
            return response()->json(['success' => false, 'errors' => $this->fieldErrors($errors)], 422);
        }

        $productIds = $request->has('product_ids')
            ? array_values(array_unique(array_map('intval', $request->product_ids)))
            : $promo->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all();

        if ($request->has('product_ids')) {
            $owned = Product::where('seller_id', $seller->id)->whereIn('id', $productIds)
                ->where('is_approved', true)->where('is_active', true)->count();
            if ($owned !== count($productIds)) {
                return response()->json(['success' => false, 'message' => __('seller.common.products_not_yours')], 403);
            }
        }

        if ($zero = $this->promoService->zeroPriceProducts($productIds, $promo->discount_type, $data['discount_value'])) {
            return response()->json(['success' => false, 'errors' => [
                'discount_value' => [__('seller.promotion.zero_price', ['products' => implode(', ', $zero)])],
            ]], 422);
        }

        // paused stays paused; active / scheduled follow the start date
        $status = $request->input('status', $promo->status);
        if ($status !== 'paused') {
            $status = now()->lessThan($promo->starts_at) ? 'scheduled' : 'active';
            if ($conflict = $this->conflictResponse($productIds, $data['starts_at'], $data['ends_at'], $promo->id)) {
                return $conflict;
            }
        }

        DB::beginTransaction();
        try {
            $promo->update([
                'name'           => $data['name'],
                'discount_value' => $data['discount_value'],
                'ends_at'        => $data['ends_at'],
                'flash_stock'    => $promo->type === 'flash_sale' ? $request->input('flash_stock', $promo->flash_stock) : null,
                'per_customer_limit' => $promo->type === 'flash_sale'
                    ? (int) $request->input('per_customer_limit', $promo->per_customer_limit ?? Promotion::DEFAULT_PER_CUSTOMER_LIMIT)
                    : null,
                'status'         => $status,
            ]);

            if ($request->has('product_ids')) {
                $promo->products()->sync($productIds);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => __('seller.promotion.updated'),
                'data'    => $this->format($promo->load(['products:id,name,slug,price', 'products.primaryImage'])),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Destroy ────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $id)
    {
        $promo = Promotion::where('seller_id', $request->user()->id)->findOrFail($id);
        if ($promo->isAdminPromotion()) {
            return response()->json(['success' => false, 'message' => __('seller.promotion.admin_read_only')], 403);
        }
        // Orders point at it: end it instead, so they keep their promotion
        if ($promo->orderItems()->exists()) {
            $promo->update(['status' => 'expired']);
            return response()->json(['success' => true, 'message' => __('seller.promotion.ended_kept')]);
        }
        $promo->delete();
        return response()->json(['success' => true, 'message' => __('seller.promotion.deleted')]);
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    public function stats(Request $request)
    {
        $sellerId = $request->user()->id;
        $now      = now();

        return response()->json(['success' => true, 'data' => [
            'total'       => Promotion::where('seller_id', $sellerId)->count(),
            'active'      => Promotion::where('seller_id', $sellerId)->where('status', 'active')
                               ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->count(),
            'scheduled'   => Promotion::where('seller_id', $sellerId)->where('status', 'scheduled')
                               ->where('starts_at', '>', $now)->count(),
            'expired'     => Promotion::where('seller_id', $sellerId)->where('status', 'expired')->count(),
            'flash_sales' => Promotion::where('seller_id', $sellerId)->where('type', 'flash_sale')->count(),
            'discounts'   => Promotion::where('seller_id', $sellerId)->where('type', 'discount')->count(),
        ]]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function format(Promotion $p): array
    {
        $products = $p->relationLoaded('products') ? $p->products : collect();
        return [
            'id'                    => $p->id,
            'name'                  => $p->name,
            'type'                  => $p->type,
            'discount_type'         => $p->discount_type,
            'discount_value'        => (float) $p->discount_value,
            'discount_label'        => $p->discount_type === 'percentage'
                                         ? (int)$p->discount_value . '% OFF'
                                         : number_format($p->discount_value, 3) . ' DT OFF',
            'starts_at'             => $p->starts_at?->toISOString(),
            'ends_at'               => $p->ends_at?->toISOString(),
            'flash_stock'           => $p->flash_stock,
            'flash_stock_used'      => $p->flash_stock_used,
            'flash_stock_remaining' => $p->flashStockRemaining(),
            'per_customer_limit'    => $p->per_customer_limit,
            'funded_by'             => $p->funded_by ?? Promotion::FUNDED_SELLER,
            'status'                => $p->status,
            'display_status'        => $p->displayStatus(),
            // Admin promotions: shown as "Promotion by Choose'Tounsi", read-only here
            'created_by'            => $p->created_by ?? Promotion::SOURCE_SELLER,
            'is_admin_promotion'    => $p->isAdminPromotion(),
            'read_only'             => $p->isAdminPromotion(),
            'priority'              => $p->priority,
            'is_flash_sale'         => $p->type === 'flash_sale',
            'products_count'        => $products->count(),
            'products'              => $products->map(fn($prod) => [
                'id'                => $prod->id,
                'name'              => $prod->name,
                'slug'              => $prod->slug,
                'price'             => (float) $prod->price,
                'primary_image_url' => $prod->primary_image_url,
            ])->values(),
            'created_at'            => $p->created_at?->toISOString(),
        ];
    }

    /** 422 naming the products already in a promotion during these dates, or null. */
    private function conflictResponse(array $productIds, $startsAt, $endsAt, ?int $ignoreId = null): ?\Illuminate\Http\JsonResponse
    {
        $conflicts = $this->promoService->conflicts($productIds, $startsAt, $endsAt, $ignoreId);
        if ($conflicts->isEmpty()) {
            return null;
        }
        $names = $conflicts->flatMap(fn ($p) => $p->products->pluck('name'))->unique()->implode(', ');
        return response()->json([
            'success'   => false,
            'code'      => 'promotion_conflict',
            'message'   => __('seller.promotion.product_conflict', ['products' => $names]),
            'conflicts' => $this->promoService->formatConflicts($conflicts),
        ], 422);
    }

    /** Business-rule messages → the form field they belong to. */
    private function fieldErrors(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if (str_contains($msg, 'hour') || str_contains($msg, 'day') || str_contains($msg, 'exceed') || str_contains($msg, 'end date')) {
                $out['ends_at'][] = $msg;
            } elseif (str_contains($msg, 'iscount') || str_contains($msg, '%')) {
                $out['discount_value'][] = $msg;
            } else {
                $out['general'][] = $msg;
            }
        }
        return $out;
    }

    private function syncExpiredStatuses(int $sellerId): void
    {
        Promotion::where('seller_id', $sellerId)
            ->whereIn('status', ['active', 'scheduled'])
            ->where('ends_at', '<=', now())
            ->update(['status' => 'expired']);
    }
}