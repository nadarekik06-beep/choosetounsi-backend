<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCommissionOverride as Override;
use App\Services\ProductCommissionOverrideService;
use App\Services\ProductCommissionPreview;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Special Commissions": a deliberate, temporary commission on chosen products of
 * chosen sellers. All numbers shown come from ProductCommissionPreview (CommissionService);
 * writes go through ProductCommissionOverrideService (lock + audit + notices).
 *
 * Existing orders are never touched: they carry their own commission snapshot.
 */
class AdminSpecialCommissionController extends Controller
{
    public function __construct(
        private ProductCommissionOverrideService $service,
        private ProductCommissionPreview $preview,
    ) {}

    // ── Pickers ───────────────────────────────────────────────────────────────

    /** Approved sellers, searchable. */
    public function sellers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $rows = DB::table('seller_applications as sa')
            ->join('users as u', 'u.id', '=', 'sa.user_id')
            ->where('sa.status', 'approved')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('sa.business_name', 'like', "%{$q}%")->orWhere('u.name', 'like', "%{$q}%")))
            ->orderBy('sa.business_name')->limit(50)
            ->get(['u.id', 'u.name', 'sa.business_name', 'sa.plan']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** One seller's products, each with the special commission live/scheduled on it. */
    public function sellerProducts(Request $request, int $sellerId): JsonResponse
    {
        $q   = trim((string) $request->query('q', ''));
        $now = Carbon::now();
        $products = Product::where('seller_id', $sellerId)
            ->when($q !== '', fn ($w) => $w->where('name', 'like', "%{$q}%"))
            ->orderBy('name')->limit(200)->get(['id', 'seller_id', 'name', 'price', 'is_active', 'is_approved']);

        $offers = Override::whereIn('product_id', $products->pluck('id'))->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->get()->groupBy('product_id');

        return response()->json(['success' => true, 'data' => $products->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price,
            'is_active' => (bool) $p->is_active, 'is_approved' => (bool) $p->is_approved,
            'offer' => optional($offers->get($p->id))->first() ? $this->row($offers->get($p->id)->first(), $now) : null,
        ])->values()]);
    }

    // ── Preview (nothing is saved) ────────────────────────────────────────────

    /** The real figures for each selected product at its CURRENT price. */
    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate([
            'product_ids'   => 'required|array|min:1|max:100',
            'product_ids.*' => 'integer',
            'type'          => 'nullable|in:fixed_rate,reduction_points',
            'value'         => 'nullable|numeric|min:0|max:100',
        ]);
        $products = Product::whereIn('id', $d['product_ids'])->get();
        $rows = $products->map(fn (Product $p) => $this->preview->forProduct(
            $p, $d['type'] ?? null, isset($d['value']) ? (float) $d['value'] : null
        ))->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Admin forms that have a seller but maybe no saved product yet ("add product on behalf",
     * product review): what that seller earns at a price, the live special commission included.
     */
    public function previewPrice(Request $request): JsonResponse
    {
        $d = $request->validate([
            'seller_id'  => 'required|integer',
            'product_id' => 'nullable|integer',
            'price'      => 'required|numeric|min:0',
            'tier_price' => 'nullable|numeric|min:0',
            'quantity'   => 'nullable|integer|min:1|max:9999',
        ]);
        $price = (float) $d['price'];
        $tier  = isset($d['tier_price']) ? max((float) $d['tier_price'], $price) : null;
        if (!empty($d['product_id']) && !Product::where('id', $d['product_id'])->where('seller_id', $d['seller_id'])->exists()) {
            throw ValidationException::withMessages(['product_id' => 'This product does not belong to that seller.']);
        }

        return response()->json(['success' => true, 'data' => $this->preview->forPrice(
            (int) $d['seller_id'], $d['product_id'] ?? null, $price, $tier, (int) ($d['quantity'] ?? 1)
        )]);
    }

    // ── List / read ───────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $now    = Carbon::now();
        $status = $request->query('status', 'all');
        $q      = trim((string) $request->query('q', ''));

        $query = Override::query()->with(['product:id,name,price,seller_id', 'seller:id,name', 'creator:id,name'])
            ->when($request->filled('seller_id'), fn ($w) => $w->where('seller_id', (int) $request->query('seller_id')))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%"))
                ->orWhereHas('seller', fn ($s) => $s->where('name', 'like', "%{$q}%"))
                ->orWhere('note', 'like', "%{$q}%")));

        match ($status) {
            'active'    => $query->liveAt($now),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
            'expired'   => $query->where(fn ($w) => $w->where('is_active', false)->orWhere(fn ($x) => $x->whereNotNull('ends_at')->where('ends_at', '<=', $now))),
            default     => null,
        };

        $page = $query->orderByDesc('id')->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (Override $o) => $this->row($o, $now))->values(),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts'  => $this->counts($now),
        ]);
    }

    /** The product's current special commission + its history (admin product page badge). */
    public function forProduct(int $productId): JsonResponse
    {
        $now = Carbon::now();
        $all = Override::where('product_id', $productId)->orderByDesc('id')->limit(20)->get();
        $live = $all->first(fn (Override $o) => $o->isLiveAt($now));
        $next = $all->first(fn (Override $o) => $o->statusAt($now) === 'scheduled');

        return response()->json(['success' => true, 'data' => [
            'active'    => $live ? $this->row($live, $now) : null,
            'scheduled' => $next ? $this->row($next, $now) : null,
            'history'   => $all->map(fn ($o) => $this->row($o, $now))->values(),
        ]]);
    }

    public function logs(Request $request): JsonResponse
    {
        $rows = DB::table('product_commission_override_logs as l')
            ->leftJoin('users as a', 'a.id', '=', 'l.actor_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->when($request->filled('product_id'), fn ($w) => $w->where('l.product_id', (int) $request->query('product_id')))
            ->when($request->filled('override_id'), fn ($w) => $w->where('l.override_id', (int) $request->query('override_id')))
            ->when($request->filled('seller_id'), fn ($w) => $w->where('l.seller_id', (int) $request->query('seller_id')))
            ->orderByDesc('l.id')->limit(200)
            ->get(['l.id', 'l.override_id', 'l.product_id', 'p.name as product_name', 'l.seller_id', 'l.action', 'l.actor_role',
                   'a.name as actor_name', 'l.before', 'l.after', 'l.created_at'])
            ->map(function ($r) {
                $r->before = $r->before ? json_decode($r->before, true) : null;
                $r->after  = $r->after ? json_decode($r->after, true) : null;
                return $r;
            });

        return response()->json(['success' => true, 'data' => $rows]);
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /** Bulk: same offer on several products. Each product is validated and saved on its own; the answer lists both outcomes. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'product_ids'   => 'required|array|min:1|max:100',
            'product_ids.*' => 'integer',
            'type'          => 'required|in:fixed_rate,reduction_points',
            'value'         => 'required|numeric|min:0|max:100',
            'starts_at'     => 'nullable|date',
            'ends_at'       => 'nullable|date',
            'note'          => 'nullable|string|max:1000',
        ]);

        $created = [];
        $failed  = [];
        $now     = Carbon::now();
        foreach (array_unique($d['product_ids']) as $productId) {
            try {
                $o = $this->service->create(['product_id' => $productId] + $d, $request->user());
                $created[] = $this->row($o->load(['product:id,name,price,seller_id', 'seller:id,name', 'creator:id,name']), $now);
            } catch (ValidationException $e) {
                $failed[] = ['product_id' => $productId, 'errors' => $e->errors()];
            }
        }

        // Nothing saved at all → a plain validation error
        if (!$created) {
            return response()->json(['success' => false, 'message' => 'No special commission was saved.', 'errors' => $failed], 422);
        }
        return response()->json(['success' => true, 'message' => count($created) . ' special commission(s) saved. It applies to new orders only.',
            'data' => $created, 'failed' => $failed], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'type' => 'sometimes|in:fixed_rate,reduction_points', 'value' => 'sometimes|numeric|min:0|max:100',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'note' => 'nullable|string|max:1000',
        ]);
        $o = $this->service->update(Override::findOrFail($id), array_intersect_key($d, array_flip(array_keys($request->all()))), $request->user());

        return response()->json(['success' => true, 'message' => 'Saved. It applies to new orders only.',
            'data' => $this->row($o->load(['product:id,name,price,seller_id', 'seller:id,name', 'creator:id,name']), Carbon::now())]);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $o = $this->service->endNow(Override::findOrFail($id), $request->user());
        return response()->json(['success' => true, 'message' => 'Ended. Existing orders keep their commission.',
            'data' => $this->row($o->load(['product:id,name,price,seller_id', 'seller:id,name', 'creator:id,name']), Carbon::now())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete(Override::findOrFail($id), $request->user());
        return response()->json(['success' => true, 'message' => 'Deleted. Existing orders keep their commission.']);
    }

    // ── Shape ─────────────────────────────────────────────────────────────────

    private function counts(Carbon $now): array
    {
        return [
            'active'    => Override::liveAt($now)->count(),
            'scheduled' => Override::where('is_active', true)->where('starts_at', '>', $now)->count(),
            'expired'   => Override::where(fn ($w) => $w->where('is_active', false)->orWhere(fn ($x) => $x->whereNotNull('ends_at')->where('ends_at', '<=', $now)))->count(),
        ];
    }

    private function row(Override $o, Carbon $now): array
    {
        return [
            'id'            => $o->id,
            'product_id'    => $o->product_id,
            'product_name'  => $o->relationLoaded('product') ? optional($o->product)->name : Product::whereKey($o->product_id)->value('name'),
            'product_price' => $o->relationLoaded('product') && $o->product ? (float) $o->product->price : null,
            'seller_id'     => $o->seller_id,
            'seller_name'   => $o->relationLoaded('seller') ? optional($o->seller)->name : null,
            'type'          => $o->type,
            'value'         => $o->value,
            'label'         => $o->valueLabel(),
            'starts_at'     => optional($o->starts_at)->toIso8601String(),
            'ends_at'       => optional($o->ends_at)->toIso8601String(),
            'is_active'     => (bool) $o->is_active,
            'status'        => $o->statusAt($now),
            'note'          => $o->note,                      // admin screens only
            'created_by'    => $o->relationLoaded('creator') ? optional($o->creator)->name : null,
            'created_at'    => optional($o->created_at)->toIso8601String(),
        ];
    }
}
