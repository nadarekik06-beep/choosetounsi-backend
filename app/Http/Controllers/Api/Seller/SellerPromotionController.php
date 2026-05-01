<?php
namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerPromotionController extends Controller
{
    public function __construct(private PromotionService $promoService) {}

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

    public function store(Request $request)
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
                'product_ids'    => 'required|array|min:1',
                'product_ids.*'  => 'integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        }

        // Business rule validation
        $errors = $this->promoService->validate($validated, $validated['type']);
        if (!empty($errors)) {
            return response()->json(['success' => false, 'message' => implode(' ', $errors)], 422);
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
                'message' => 'One or more products are invalid or do not belong to you.',
            ], 403);
        }

        // Flash sale: check for overlap (no two flash_sales on same product simultaneously)
        if ($validated['type'] === 'flash_sale') {
            $overlap = $this->detectFlashSaleOverlap($productIds, $validated['starts_at'], $validated['ends_at']);
            if ($overlap) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more products already have an active flash sale in this time range.',
                ], 422);
            }
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
                'status'         => $status,
                'priority'       => $validated['type'] === 'flash_sale' ? 10 : 5,
            ]);

            $promo->products()->attach($productIds);
            $this->promoService->bustCacheForProducts($productIds);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Promotion created successfully.',
                'data'    => $this->format($promo->load(['products:id,name,slug,price', 'products.primaryImage'])
),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function update(Request $request, int $id)
    {
        $promo = Promotion::where('seller_id', $request->user()->id)->findOrFail($id);

        if ($promo->status === 'expired') {
            return response()->json(['success' => false, 'message' => 'Cannot edit an expired promotion.'], 422);
        }

        DB::beginTransaction();
        try {
            $promo->update(array_filter([
                'name'           => $request->input('name',           $promo->name),
                'discount_value' => $request->input('discount_value', $promo->discount_value),
                'ends_at'        => $request->input('ends_at',        $promo->ends_at),
                'flash_stock'    => $request->input('flash_stock',    $promo->flash_stock),
                'status'         => $request->input('status',         $promo->status),
            ], fn($v) => $v !== null));

            if ($request->has('product_ids')) {
                $productIds = array_unique($request->product_ids);
                $promo->products()->sync($productIds);
                $this->promoService->bustCacheForProducts($productIds);
            } else {
                $this->promoService->bustCacheForProducts(
                    $promo->products->pluck('id')->toArray()
                );
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Promotion updated.',
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
        $productIds = $promo->products->pluck('id')->toArray();
        $promo->delete();
        $this->promoService->bustCacheForProducts($productIds);
        return response()->json(['success' => true, 'message' => 'Promotion deleted.']);
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
            'status'                => $p->status,
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

    private function detectFlashSaleOverlap(array $productIds, string $startsAt, string $endsAt): bool
    {
        return Promotion::where('type', 'flash_sale')
            ->whereIn('status', ['active', 'scheduled'])
            ->whereHas('products', fn($q) => $q->whereIn('products.id', $productIds))
            ->where(fn($q) => $q
                ->whereBetween('starts_at', [$startsAt, $endsAt])
                ->orWhereBetween('ends_at',  [$startsAt, $endsAt])
                ->orWhere(fn($q2) => $q2
                    ->where('starts_at', '<=', $startsAt)
                    ->where('ends_at',   '>=', $endsAt))
            )->exists();
    }

    private function syncExpiredStatuses(int $sellerId): void
    {
        Promotion::where('seller_id', $sellerId)
            ->whereIn('status', ['active', 'scheduled'])
            ->where('ends_at', '<=', now())
            ->update(['status' => 'expired']);
    }
}