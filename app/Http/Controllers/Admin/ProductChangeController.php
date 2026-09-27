<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductChangeSet;
use App\Models\User;
use App\Services\ProductChangeTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Admin "Product changes" feed: every seller edit, grouped per save.
 *
 *   GET  /api/admin/product-changes            ?seller_id&product_id&search&group&sensitive&source&from&to&page
 *   GET  /api/admin/product-changes/stats
 *   GET  /api/admin/product-changes/{id}
 *   POST /api/admin/product-changes/{id}/revert  { item_ids?: int[], force?: bool }
 *
 * Deactivating the product uses the existing PATCH /api/admin/products/{id}/disable.
 */
class ProductChangeController extends Controller
{
    public const GROUPS = ['text', 'price', 'stock', 'category', 'images', 'variants', 'attributes', 'settings'];

    public function __construct(private ProductChangeTracker $tracker) {}

    public function index(Request $request)
    {
        $request->validate([
            'seller_id'  => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'search'     => 'nullable|string|max:100',
            'group'      => 'nullable|in:' . implode(',', self::GROUPS),
            'sensitive'  => 'nullable|boolean',
            'source'     => 'nullable|in:seller_edit,restock,images,admin_revert',
            'from'       => 'nullable|date',
            'to'         => 'nullable|date',
            'per_page'   => 'nullable|integer|min:5|max:100',
        ]);

        $query = ProductChangeSet::query()
            ->with([
                'product' => fn($q) => $q->select('id', 'name', 'slug', 'is_active', 'is_approved', 'rejection_reason',
                    'changes_requested_at', 'deleted_by_seller', 'deleted_at', 'seller_id')->with('primaryImage'),
                'seller:id,name,email',
                'reverter:id,name',
                'items',
            ])
            ->when($request->seller_id,  fn($q, $v) => $q->where('seller_id', $v))
            ->when($request->product_id, fn($q, $v) => $q->where('product_id', $v))
            ->when($request->search,     fn($q, $v) => $q->whereHas('product', fn($p) => $p->withTrashed()->where('name', 'like', "%{$v}%")))
            ->when($request->group,      fn($q, $v) => $q->whereJsonContains('groups', $v))
            ->when($request->filled('sensitive'), fn($q) => $q->where('is_sensitive', $request->boolean('sensitive')))
            ->when($request->source,     fn($q, $v) => $q->where('source', $v), fn($q) => $q->where('source', '!=', 'admin_revert'))
            ->when($request->from,       fn($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
            ->when($request->to,         fn($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))
            ->orderByDesc('created_at')->orderByDesc('id');

        $page = $query->paginate((int) $request->input('per_page', 20));
        $page->getCollection()->transform(fn($set) => $this->format($set));

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function show(int $id)
    {
        $set = ProductChangeSet::with(['product.primaryImage', 'seller:id,name,email', 'reverter:id,name', 'items'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->format($set)]);
    }

    public function stats()
    {
        $week = now()->subDays(7);
        $base = fn() => ProductChangeSet::where('source', '!=', 'admin_revert');

        return response()->json(['success' => true, 'data' => [
            'today'          => $base()->where('created_at', '>=', now()->startOfDay())->count(),
            'last_7_days'    => $base()->where('created_at', '>=', $week)->count(),
            'sensitive_7d'   => $base()->where('created_at', '>=', $week)->where('is_sensitive', true)->count(),
            'price_7d'       => $base()->where('created_at', '>=', $week)->whereJsonContains('groups', 'price')->count(),
            'reverted_7d'    => $base()->where('created_at', '>=', $week)->whereNotNull('reverted_at')->count(),
            'sellers'        => User::whereIn('id', $base()->whereNotNull('seller_id')->distinct()->pluck('seller_id'))
                                    ->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    public function revert(Request $request, int $id)
    {
        $request->validate([
            'item_ids'   => 'nullable|array',
            'item_ids.*' => 'integer',
            'force'      => 'nullable|boolean',
        ]);

        $set = ProductChangeSet::findOrFail($id);
        $result = $this->tracker->revert($set, $request->user(), $request->input('item_ids'), $request->boolean('force'));

        $reverted  = count($result['reverted']);
        $conflicts = count($result['conflicts']);

        $message = match (true) {
            $reverted && !$conflicts => "Reverted {$reverted} change" . ($reverted === 1 ? '' : 's') . '.',
            $reverted && $conflicts  => "Reverted {$reverted}; {$conflicts} skipped because they changed again since.",
            $conflicts > 0           => 'Nothing reverted: these values changed again since. Use "Revert anyway" to overwrite.',
            default                  => 'Nothing to revert (images and added/removed variants must be fixed in the product editor).',
        };

        return response()->json([
            'success' => $reverted > 0,
            'message' => $message,
            'data'    => $result + ['set' => $this->format($set->fresh(['product.primaryImage', 'seller:id,name,email', 'reverter:id,name', 'items']))],
        ], $reverted > 0 ? 200 : ($conflicts ? 409 : 422));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function format(ProductChangeSet $set): array
    {
        $p = $set->product;
        $imageUrls = fn($paths) => array_map(fn($path) => url(Storage::url($path)), (array) $paths);

        return [
            'id'                => $set->id,
            'source'            => $set->source,
            'summary'           => $set->summary,
            'groups'            => $set->groups ?? [],
            'is_sensitive'      => $set->is_sensitive,
            'sensitive_reasons' => $set->sensitive_reasons ?? [],
            'stock_only'        => $set->stock_only,
            'notified'          => $set->notified,
            'created_at'        => optional($set->created_at)->toISOString(),
            'reverted_at'       => optional($set->reverted_at)->toISOString(),
            'reverted_by'       => $set->reverter?->only(['id', 'name']),
            'seller'            => $set->seller?->only(['id', 'name', 'email']),
            'product'           => $p ? [
                'id'                => $p->id,
                'name'              => $p->getRawOriginal('name'),
                'slug'              => $p->slug,
                'is_active'         => (bool) $p->is_active,
                'status'            => $p->deleted_at ? 'deleted' : $p->moderationStatus(),
                'primary_image_url' => $p->primaryImage ? url(Storage::url($p->primaryImage->image_path)) : null,
                'storefront_url'    => rtrim(config('app.frontend_url'), '/') . '/products/' . $p->slug,
            ] : null,
            'items' => $set->items->map(fn($i) => [
                'id'           => $i->id,
                'field'        => $i->field,
                'group'        => $i->group,
                'label'        => $i->label,
                'old_display'  => $i->group === 'images' ? null : $this->tracker->display($i->field, $i->old_value),
                'new_display'  => $i->group === 'images' ? null : $this->tracker->display($i->field, $i->new_value),
                'old_images'   => $i->group === 'images' ? $imageUrls($i->old_value) : null,
                'new_images'   => $i->group === 'images' ? $imageUrls($i->new_value) : null,
                'is_long_text' => in_array($i->field, ['description', 'short_description'], true),
                'is_sensitive' => $i->is_sensitive,
                'revertible'   => $i->revertible,
                'reverted_at'  => optional($i->reverted_at)->toISOString(),
            ])->values(),
        ];
    }
}
