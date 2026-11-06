<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Notifications\Sellers\AdminPromotionNotification;
use App\Services\PromotionService;
use App\Services\ShopOverview;
use App\Support\TunisTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promotions from the admin panel: every promotion (seller and admin), and
 * admin promotions on any seller's products. Same tables and pricing as seller
 * promotions (PromotionService): no second system.
 *
 *   GET    /api/admin/promotions/stats
 *   GET    /api/admin/promotions               list (seller_id, status, source, type, search)
 *   GET    /api/admin/promotions/products      product search across sellers (+ current promotion)
 *   POST   /api/admin/promotions/preview       original / final price / % per product and variant
 *   POST   /api/admin/promotions               create (one promotion per seller of the chosen products)
 *   GET    /api/admin/promotions/{id}
 *   PUT    /api/admin/promotions/{id}          edit (admin or seller promotion)
 *   POST   /api/admin/promotions/{id}/pause
 *   POST   /api/admin/promotions/{id}/resume
 *   DELETE /api/admin/promotions/{id}          delete (ended instead when orders use it)
 *
 * Rules: value > 0, percentage ≤ 99, end after start, final price > 0 on every
 * variant, products active + approved. One promotion per product at a time:
 * an overlap answers 409 `promotion_conflict` with the list; sending again with
 * replace_conflicts=true moves the products out of the other promotions.
 * Who pays the discount (AUDIT M-8): funded_by = seller (default; commission on the
 * final price) or platform (the seller is paid as if sold at the original price,
 * the platform absorbs the discount). Flash sales: per_customer_limit (default 2).
 * The seller is notified (bell + e-mail) of every change on their products.
 */
class AdminPromotionController extends Controller
{
    public function __construct(private PromotionService $promos) {}

    // ── Lists ────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $now  = now();
        $live = fn () => Promotion::whereIn('status', Promotion::PRICING_STATUSES)->where('ends_at', '>', $now);

        return response()->json(['success' => true, 'data' => [
            'total'     => Promotion::count(),
            'active'    => $live()->where('starts_at', '<=', $now)->count(),
            'scheduled' => $live()->where('starts_at', '>', $now)->count(),
            'paused'    => Promotion::where('status', 'paused')->where('ends_at', '>', $now)->count(),
            'admin'     => Promotion::byAdmin()->count(),
            'seller'    => Promotion::bySeller()->count(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $now = now();
        $query = Promotion::query()
            ->with(['products:id,name,slug,price,seller_id', 'products.primaryImage', 'seller:id,name,email', 'createdByAdmin:id,name'])
            ->withCount('orderItems')
            ->when($request->filled('seller_id'), fn ($q) => $q->where('seller_id', (int) $request->seller_id))
            ->when(in_array($request->source, ['seller', 'admin'], true), fn ($q) => $q->where('created_by', $request->source))
            ->when(in_array($request->type, ['flash_sale', 'discount'], true), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%' . trim((string) $request->search) . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $s)
                    ->orWhereHas('products', fn ($p) => $p->where('products.name', 'like', $s)));
            });

        match ($request->status) {
            'active'    => $query->whereIn('status', Promotion::PRICING_STATUSES)->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            'scheduled' => $query->whereIn('status', Promotion::PRICING_STATUSES)->where('starts_at', '>', $now)->where('ends_at', '>', $now),
            'paused'    => $query->where('status', 'paused')->where('ends_at', '>', $now),
            'expired'   => $query->where(fn ($q) => $q->where('status', 'expired')->orWhere('ends_at', '<=', $now)),
            default     => null,
        };

        $page  = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->input('per_page', 20))));
        $shops = ShopOverview::storeNames($page->getCollection()->pluck('seller_id')->filter()->unique()->all());
        $page->getCollection()->transform(fn (Promotion $p) => $this->format($p, $shops));

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function show(int $id): JsonResponse
    {
        $promo = Promotion::with(['products:id,name,slug,price,seller_id', 'products.primaryImage', 'seller:id,name,email', 'createdByAdmin:id,name'])
            ->withCount('orderItems')->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->format($promo, ShopOverview::storeNames([$promo->seller_id]))]);
    }

    /** Products of every seller, with their price and the promotion pricing them now. */
    public function products(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('is_approved', true)->where('is_active', true)->whereNotNull('seller_id')
            ->when($request->filled('seller_id'), fn ($q) => $q->where('seller_id', (int) $request->seller_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = trim((string) $request->search);
                $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%")
                    ->when(ctype_digit($s), fn ($w2) => $w2->orWhere('id', (int) $s)));
            })
            ->with(['primaryImage', 'seller:id,name'])
            ->withCount(['variants' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('id')
            ->limit(min(50, max(1, (int) $request->input('limit', 20))))
            ->get(['id', 'name', 'slug', 'price', 'stock', 'seller_id', 'sku']);

        $pricing = $this->promos->priceMany($products);
        $active  = $this->promos->activePromotionsFor($products->pluck('id')->all());
        $shops   = ShopOverview::storeNames($products->pluck('seller_id')->unique()->all());

        return response()->json(['success' => true, 'data' => $products->map(fn (Product $p) => [
            'id'                => $p->id,
            'name'              => $p->name,
            'slug'              => $p->slug,
            'sku'               => $p->sku,
            'price'             => (float) $p->price,
            'stock'             => (int) $p->stock,
            'variants_count'    => (int) $p->variants_count,
            'primary_image_url' => $p->primary_image_url,
            'seller'            => ['id' => $p->seller_id, 'name' => $shops[(int) $p->seller_id] ?? $p->seller?->name],
            'final_price'       => $pricing[$p->id]['final_price'],
            'discount_percent'  => $pricing[$p->id]['discount_percent'],
            'current_promotion' => isset($active[$p->id]) ? [
                'id'     => $active[$p->id]->id,
                'name'   => $active[$p->id]->name,
                'source' => $active[$p->id]->created_by ?? Promotion::SOURCE_SELLER,
                'ends_at'=> $active[$p->id]->ends_at?->toISOString(),
            ] : null,
        ])->values()]);
    }

    /** Live preview with the exact checkout rules (30-day reference price, rounding). */
    public function preview(Request $request): JsonResponse
    {
        $v = $request->validate([
            'product_ids'    => 'required|array|min:1|max:100',
            'product_ids.*'  => 'integer',
            'discount_type'  => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
        ]);

        return response()->json(['success' => true, 'data' => $this->promos->preview(
            array_map('intval', $v['product_ids']), $v['discount_type'], (float) $v['discount_value'],
        )]);
    }

    // ── Create / edit ────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        try {
            $v = $request->validate([
                'name'              => 'required|string|max:255',
                'type'              => 'required|in:flash_sale,discount',
                'discount_type'     => 'required|in:percentage,fixed',
                'discount_value'    => 'required|numeric|gt:0',
                'starts_at'         => 'required|date',
                'ends_at'           => 'required|date|after:starts_at',
                'flash_stock'       => 'nullable|integer|min:1',
                'per_customer_limit'=> 'nullable|integer|min:1|max:100',
                'funded_by'         => 'sometimes|in:seller,platform',
                'product_ids'       => 'required|array|min:1|max:200',
                'product_ids.*'     => 'integer|distinct',
                'replace_conflicts' => 'sometimes|boolean',
            ]);
            // Typed in Tunis time, stored in UTC (AUDIT L-3)
            $v['starts_at'] = TunisTime::toUtcString($v['starts_at']);
            $v['ends_at']   = TunisTime::toUtcString($v['ends_at']);
            $productIds = array_values(array_unique(array_map('intval', $v['product_ids'])));
            $products   = $this->validProducts($productIds);
            $this->businessRules($v, $productIds);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        $conflicts = $this->promos->conflicts($productIds, $v['starts_at'], $v['ends_at']);
        if ($conflicts->isNotEmpty() && !$request->boolean('replace_conflicts')) {
            return $this->conflictResponse($conflicts);
        }

        $notices = [];
        $created = DB::transaction(function () use ($v, $products, $conflicts, $request, &$notices) {
            $notices = $this->replace($conflicts);

            // promotions.seller_id is the product owner: one promotion per seller
            return $products->groupBy('seller_id')->map(function (Collection $own, $sellerId) use ($v, $request) {
                $promo = Promotion::create([
                    'seller_id'           => (int) $sellerId,
                    'created_by'          => Promotion::SOURCE_ADMIN,
                    'created_by_admin_id' => $request->user()->id,
                    // Who pays the discount (AUDIT M-8): seller (default) or platform
                    'funded_by'           => $v['funded_by'] ?? Promotion::FUNDED_SELLER,
                    'name'                => $v['name'],
                    'type'                => $v['type'],
                    'discount_type'       => $v['discount_type'],
                    'discount_value'      => $v['discount_value'],
                    'starts_at'           => $v['starts_at'],
                    'ends_at'             => $v['ends_at'],
                    'flash_stock'         => $v['type'] === 'flash_sale' ? ($v['flash_stock'] ?? null) : null,
                    'per_customer_limit'  => $v['type'] === 'flash_sale' ? ($v['per_customer_limit'] ?? Promotion::DEFAULT_PER_CUSTOMER_LIMIT) : null,
                    'status'              => now()->lessThan($v['starts_at']) ? 'scheduled' : 'active',
                    'priority'            => $v['type'] === 'flash_sale' ? 10 : 5,
                ]);
                $promo->products()->attach($own->pluck('id')->all());
                return $promo;
            })->values();
        });

        foreach ($created as $promo) {
            $this->notify($promo, 'created');
        }
        $this->sendNotices($notices);

        return response()->json([
            'success' => true,
            'message' => count($created) > 1 ? count($created) . ' promotions created (one per seller).' : 'Promotion created.',
            'data'    => $created->map(fn ($p) => $this->fresh($p))->values(),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $promo = Promotion::with('products:id,name')->findOrFail($id);
        if ($promo->displayStatus() === 'expired') {
            return response()->json(['success' => false, 'message' => 'An ended promotion cannot be edited.'], 422);
        }
        $started = $promo->starts_at <= now();

        try {
            $request->validate([
                'name'              => 'sometimes|required|string|max:255',
                'type'              => 'sometimes|required|in:flash_sale,discount',
                'discount_type'     => 'sometimes|required|in:percentage,fixed',
                'discount_value'    => 'sometimes|required|numeric|gt:0',
                'starts_at'         => 'sometimes|required|date',
                'ends_at'           => 'sometimes|required|date',
                'flash_stock'       => 'nullable|integer|min:1',
                'per_customer_limit'=> 'nullable|integer|min:1|max:100',
                'funded_by'         => 'sometimes|in:seller,platform',
                'product_ids'       => 'sometimes|required|array|min:1|max:200',
                'product_ids.*'     => 'integer|distinct',
                'replace_conflicts' => 'sometimes|boolean',
            ]);
            $v = [
                'name'           => $request->input('name', $promo->name),
                // Type and start are fixed once the promotion has started (orders may use it)
                'type'           => $started ? $promo->type : $request->input('type', $promo->type),
                'discount_type'  => $request->input('discount_type', $promo->discount_type),
                'discount_value' => (float) $request->input('discount_value', $promo->discount_value),
                'starts_at'      => $started || !$request->filled('starts_at') ? $promo->starts_at->toDateTimeString() : TunisTime::toUtcString($request->starts_at),
                'ends_at'        => $request->filled('ends_at') ? TunisTime::toUtcString($request->ends_at) : $promo->ends_at->toDateTimeString(),
            ];
            // Only Choose'Tounsi's own promotions can be platform-funded
            if ($request->input('funded_by') === Promotion::FUNDED_PLATFORM && !$promo->isAdminPromotion()) {
                throw ValidationException::withMessages(['funded_by' => 'Only admin promotions can be funded by the platform.']);
            }
            if (Carbon::parse($v['ends_at']) <= now()) {
                throw ValidationException::withMessages(['ends_at' => 'The end date must be in the future.']);
            }
            $productIds = $request->has('product_ids')
                ? array_values(array_unique(array_map('intval', $request->product_ids)))
                : $promo->products->pluck('id')->map(fn ($i) => (int) $i)->all();
            $products = $this->validProducts($productIds);
            if ($products->pluck('seller_id')->unique()->count() !== 1 || (int) $products->first()->seller_id !== (int) $promo->seller_id) {
                throw ValidationException::withMessages(['product_ids' => "Products must all belong to this promotion's seller."]);
            }
            $this->businessRules($v, $productIds);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        $conflicts = $promo->status === 'paused' ? collect()
            : $this->promos->conflicts($productIds, $v['starts_at'], $v['ends_at'], $promo->id);
        if ($conflicts->isNotEmpty() && !$request->boolean('replace_conflicts')) {
            return $this->conflictResponse($conflicts);
        }

        $notices = [];
        DB::transaction(function () use ($promo, $v, $productIds, $conflicts, $request, &$notices) {
            $notices = $this->replace($conflicts);
            $promo->update([
                'name'           => $v['name'],
                'type'           => $v['type'],
                'discount_type'  => $v['discount_type'],
                'discount_value' => $v['discount_value'],
                'starts_at'      => $v['starts_at'],
                'ends_at'        => $v['ends_at'],
                'flash_stock'    => $v['type'] === 'flash_sale' ? $request->input('flash_stock', $promo->flash_stock) : null,
                'per_customer_limit' => $v['type'] === 'flash_sale'
                    ? (int) $request->input('per_customer_limit', $promo->per_customer_limit ?? Promotion::DEFAULT_PER_CUSTOMER_LIMIT)
                    : null,
                // Snapshotted on each order line: a change only affects orders placed afterwards
                'funded_by'      => $promo->isAdminPromotion() ? $request->input('funded_by', $promo->funded_by ?? Promotion::FUNDED_SELLER) : Promotion::FUNDED_SELLER,
                'priority'       => $v['type'] === 'flash_sale' ? 10 : 5,
                'status'         => $promo->status === 'paused' ? 'paused' : (now()->lessThan($v['starts_at']) ? 'scheduled' : 'active'),
            ]);
            $promo->products()->sync($productIds);
        });

        $this->notify($promo->fresh(), 'updated');
        $this->sendNotices($notices);

        return response()->json(['success' => true, 'message' => 'Promotion updated.', 'data' => $this->fresh($promo)]);
    }

    public function pause(int $id): JsonResponse
    {
        $promo = Promotion::findOrFail($id);
        if ($promo->displayStatus() === 'expired') {
            return response()->json(['success' => false, 'message' => 'This promotion has already ended.'], 422);
        }
        if ($promo->status !== 'paused') {
            $promo->update(['status' => 'paused']);
            $this->notify($promo, 'paused');
        }
        return response()->json(['success' => true, 'message' => 'Promotion paused.', 'data' => $this->fresh($promo)]);
    }

    /** Back on: same one-promotion-per-product check as a new one. */
    public function resume(Request $request, int $id): JsonResponse
    {
        $promo = Promotion::with('products:id')->findOrFail($id);
        if ($promo->displayStatus() === 'expired') {
            return response()->json(['success' => false, 'message' => 'This promotion has already ended.'], 422);
        }
        if ($promo->status !== 'paused') {
            return response()->json(['success' => true, 'message' => 'Promotion is already running.', 'data' => $this->fresh($promo)]);
        }

        $productIds = $promo->products->pluck('id')->map(fn ($i) => (int) $i)->all();
        $conflicts  = $this->promos->conflicts($productIds, $promo->starts_at, $promo->ends_at, $promo->id);
        if ($conflicts->isNotEmpty() && !$request->boolean('replace_conflicts')) {
            return $this->conflictResponse($conflicts);
        }

        $notices = [];
        DB::transaction(function () use ($promo, $conflicts, &$notices) {
            $notices = $this->replace($conflicts);
            $promo->update(['status' => now()->lessThan($promo->starts_at) ? 'scheduled' : 'active']);
        });
        $this->notify($promo, 'resumed');
        $this->sendNotices($notices);

        return response()->json(['success' => true, 'message' => 'Promotion resumed.', 'data' => $this->fresh($promo)]);
    }

    /** Deleted, or ended when orders point at it (they keep their promotion and prices). */
    public function destroy(int $id): JsonResponse
    {
        $promo = Promotion::with('products:id,name')->findOrFail($id);
        $wasLive = $promo->displayStatus() !== 'expired';
        $names   = $promo->products->pluck('name')->all();

        if ($promo->orderItems()->exists()) {
            $promo->update(['status' => 'expired']);
            $message = 'Promotion ended (kept because orders use it).';
        } else {
            $promo->delete();
            $message = 'Promotion deleted.';
        }
        if ($wasLive) {
            $this->notify($promo, 'ended', $names);
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Products exist, are active + approved and belong to a seller. */
    private function validProducts(array $productIds): Collection
    {
        $products = Product::whereIn('id', $productIds)->get(['id', 'name', 'seller_id', 'is_active', 'is_approved', 'price']);
        $errors = [];
        foreach ($productIds as $pid) {
            $p = $products->firstWhere('id', $pid);
            if (!$p) {
                $errors[] = "Product #{$pid} does not exist.";
            } elseif (!$p->is_active || !$p->is_approved) {
                $errors[] = "\"{$p->name}\" is not active / approved.";
            } elseif ($p->seller_id === null) {
                $errors[] = "\"{$p->name}\" has no seller.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['product_ids' => $errors]);
        }
        return $products;
    }

    private function businessRules(array $v, array $productIds): void
    {
        if ($errors = $this->promos->validate($v, $v['type'], Promotion::SOURCE_ADMIN)) {
            $byField = [];
            foreach ($errors as $msg) {
                $byField[str_contains($msg, 'end date') ? 'ends_at' : 'discount_value'][] = $msg;
            }
            throw ValidationException::withMessages($byField);
        }
        if ($zero = $this->promos->zeroPriceProducts($productIds, $v['discount_type'], (float) $v['discount_value'])) {
            throw ValidationException::withMessages(['discount_value' => ['The final price would be 0 DT or less for: ' . implode(', ', $zero) . '.']]);
        }
    }

    private function conflictResponse(Collection $conflicts): JsonResponse
    {
        $names = $conflicts->flatMap(fn ($p) => $p->products->pluck('name'))->unique()->implode(', ');
        return response()->json([
            'success'   => false,
            'code'      => 'promotion_conflict',
            'message'   => "Already in another promotion during these dates: {$names}. Replace it or cancel.",
            'conflicts' => $this->promos->formatConflicts($conflicts),
        ], 409);
    }

    /** Takes the products out of the conflicting promotions; returns what to tell their sellers. */
    private function replace(Collection $conflicts): array
    {
        if ($conflicts->isEmpty()) return [];
        return array_map(fn ($r) => [
            'promotion' => $r['promotion'],
            'event'     => !$r['promotion']->isAdminPromotion() ? 'replaced' : ($r['ended'] ? 'ended' : 'updated'),
            'products'  => $r['promotion']->products->pluck('name')->all(),
        ], $this->promos->removeProductsFromConflicts($conflicts));
    }

    private function sendNotices(array $notices): void
    {
        foreach ($notices as $n) {
            $this->notify($n['promotion'], $n['event'], $n['products']);
        }
    }

    private function notify(Promotion $promo, string $event, ?array $productNames = null): void
    {
        $seller = User::find($promo->seller_id);
        if (!$seller) return;

        $names = $productNames ?? $promo->products()->pluck('products.name')->all();
        $seller->notify(new AdminPromotionNotification($event, [
            'id'             => $promo->id,
            'name'           => $promo->name,
            'discount_type'  => $promo->discount_type,
            'discount_value' => (float) $promo->discount_value,
            'starts_at'      => $promo->starts_at?->toISOString(),
            'ends_at'        => $promo->ends_at?->toISOString(),
        ], $names));
    }

    private function fresh(Promotion $promo): array
    {
        $promo = $promo->fresh(['products:id,name,slug,price,seller_id', 'products.primaryImage', 'seller:id,name,email', 'createdByAdmin:id,name']);
        $promo->loadCount('orderItems');
        return $this->format($promo, ShopOverview::storeNames([$promo->seller_id]));
    }

    private function format(Promotion $p, array $shops): array
    {
        $products = $p->relationLoaded('products') ? $p->products : collect();
        $pricing  = $this->promos->priceMany($products);

        return [
            'id'                    => $p->id,
            'name'                  => $p->name,
            'type'                  => $p->type,
            'source'                => $p->created_by ?? Promotion::SOURCE_SELLER,
            'created_by_admin'      => $p->createdByAdmin ? ['id' => $p->createdByAdmin->id, 'name' => $p->createdByAdmin->name] : null,
            'funded_by'             => $p->funded_by ?? 'seller',
            'per_customer_limit'    => $p->per_customer_limit,
            'seller'                => [
                'id'    => $p->seller_id,
                'name'  => $shops[(int) $p->seller_id] ?? $p->seller?->name,
                'email' => $p->seller?->email,
            ],
            'discount_type'         => $p->discount_type,
            'discount_value'        => (float) $p->discount_value,
            'discount_label'        => $p->discount_type === 'percentage'
                ? rtrim(rtrim(number_format((float) $p->discount_value, 2, '.', ''), '0'), '.') . '%'
                : number_format((float) $p->discount_value, 3, '.', '') . ' DT',
            'starts_at'             => $p->starts_at?->toISOString(),
            'ends_at'               => $p->ends_at?->toISOString(),
            'status'                => $p->status,
            'display_status'        => $p->displayStatus(),
            'flash_stock'           => $p->flash_stock,
            'flash_stock_used'      => $p->flash_stock_used,
            'flash_stock_remaining' => $p->flashStockRemaining(),
            'order_items_count'     => (int) ($p->order_items_count ?? 0),
            'products_count'        => $products->count(),
            'products'              => $products->map(fn ($prod) => [
                'id'                => $prod->id,
                'name'              => $prod->name,
                'slug'              => $prod->slug,
                'price'             => (float) $prod->price,
                'final_price'       => $pricing[$prod->id]['final_price'] ?? (float) $prod->price,
                'priced_by_this'    => ($pricing[$prod->id]['promotion']['id'] ?? null) === $p->id,
                'primary_image_url' => $prod->primary_image_url,
            ])->values(),
            'created_at'            => $p->created_at?->toISOString(),
        ];
    }
}
