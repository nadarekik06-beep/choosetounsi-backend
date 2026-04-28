<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Pack;
use App\Models\PackItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SellerPackController extends Controller
{
    // ── Index ──────────────────────────────────────────────────────────────

public function index(Request $request)
{
    $seller = $request->user();

    $packs = Pack::where('seller_id', $seller->id)
        ->with(['items' => fn($q) => $q->with([   // ← 'items' not 'itemsWithDetails'
            'product:id,name,slug,price',
            'product.primaryImage',
            // no variant relation here either
        ])])
        ->orderByDesc('created_at')
        ->paginate((int) $request->input('per_page', 12));

    $packs->getCollection()->transform(fn($pack) => $this->formatPack($pack));

    return response()->json(['success' => true, 'data' => $packs]);
}

    // ── Show ───────────────────────────────────────────────────────────────

public function show(Request $request, int $id)
{
    $pack = Pack::where('seller_id', $request->user()->id)
        ->with([
            'items' => fn($q) => $q->orderBy('order')->with([
                'product:id,name,slug,price,is_active,is_approved',
                'product.primaryImage',
                'product.variants' => fn($q) => $q
                    ->where('is_active', true)
                    ->with(['attributeOptions.attribute:id,slug,name,type']),
            ]),
        ])
        ->findOrFail($id);

    return response()->json(['success' => true, 'data' => $this->formatPack($pack, true)]);
}
    // ── Store ──────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name'        => 'required|string|max:255',
                'pack_price'  => 'required|numeric|min:0',
                'items'       => 'required|array|min:1',
                'items.*.product_id' => 'required|integer',
                'items.*.quantity'   => 'required|integer|min:1',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $seller = $request->user();

        // Verify every product belongs to this seller
        $productIds = collect($request->items)->pluck('product_id')->unique()->values();
        $ownedCount = Product::where('seller_id', $seller->id)
            ->whereIn('id', $productIds)
            ->count();

        if ($ownedCount !== $productIds->count()) {
            return response()->json([
                'success' => false,
                'message' => 'One or more products do not belong to you.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $pack = Pack::create([
                'seller_id'         => $seller->id,
                'name'              => $request->name,
                'slug'              => Pack::uniqueSlug($request->name),
                'description'       => $request->description      ?? null,
                'short_description' => $request->short_description ?? null,
                'pack_price'        => $request->pack_price,
                'original_price'    => 0, // recalculated below
                'is_active'         => filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOLEAN),
                'is_approved'       => true,
            ]);

            // Save image
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('packs', 'public');
                $pack->update(['image_path' => $path]);
            }

            // Save items
            $this->syncItems($pack, $request->items);

            // Recalculate original_price after items exist
            $pack->recalculateOriginalPrice();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pack created! It will be reviewed by an admin.',
                'data' => $this->formatPack($pack->load([
    'items.product.primaryImage',
    'items.product.variants.attributeOptions.attribute',
]), true),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SellerPack::store] ' . $e->getMessage(), [
                'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create pack.',
                'debug'   => $e->getMessage(),
            ], 500);
        }
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function update(Request $request, int $id)
    {
        $pack = Pack::where('seller_id', $request->user()->id)->findOrFail($id);

        DB::beginTransaction();
        try {
            $pack->update([
                'name'              => $request->input('name', $pack->name),
                'slug'              => Pack::uniqueSlug($request->input('name', $pack->name), $pack->id),
                'description'       => $request->input('description',       $pack->description),
                'short_description' => $request->input('short_description', $pack->short_description),
                'pack_price'        => $request->input('pack_price',        $pack->pack_price),
                'is_active'         => $request->has('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : $pack->is_active,
            ]);

            // Replace image if new one uploaded
            if ($request->hasFile('image')) {
                if ($pack->image_path) {
                    Storage::disk('public')->delete($pack->image_path);
                }
                $pack->update(['image_path' => $request->file('image')->store('packs', 'public')]);
            }

            // Re-sync items if provided
            if ($request->has('items')) {
                $productIds = collect($request->items)->pluck('product_id')->unique()->values();
                $ownedCount = Product::where('seller_id', $request->user()->id)
                    ->whereIn('id', $productIds)
                    ->count();

                if ($ownedCount !== $productIds->count()) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more products do not belong to you.',
                    ], 403);
                }

                $this->syncItems($pack, $request->items);
                $pack->recalculateOriginalPrice();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pack updated.',
'data' => $this->formatPack($pack->load([
    'items.product.primaryImage',
    'items.product.variants.attributeOptions.attribute',
]), true),            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SellerPack::update] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Destroy ────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $id)
    {
        $pack = Pack::where('seller_id', $request->user()->id)->findOrFail($id);
        $pack->delete(); // boot() handles image cleanup
        return response()->json(['success' => true, 'message' => 'Pack deleted.']);
    }

    // ── Seller's own products list (for item picker) ───────────────────────

    public function sellerProducts(Request $request)
    {
        $seller   = $request->user();
        $search   = $request->input('search', '');

        $products = Product::where('seller_id', $seller->id)
            ->where('is_active', true)
            ->where('is_approved', true)
            ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%"))
            ->with([
                'primaryImage',
                'variants:id,product_id,stock,price_override,is_active',
                'variants.attributeOptions.attribute:id,slug,name',
            ])
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn($p) => [
                'id'                => $p->id,
                'name'              => $p->name,
                'price'             => (float) $p->price,
                'primary_image_url' => $p->primary_image_url,
                'has_variants'      => $p->variants->isNotEmpty(),
                'variants'          => $p->variants->map(fn($v) => [
                    'id'             => $v->id,
                    'label'          => $v->label,
                    'stock'          => $v->stock,
                    'price_override' => $v->price_override,
                    'is_active'      => $v->is_active,
                    'option_map'     => $v->option_map,
                ]),
            ]);

        return response()->json(['success' => true, 'data' => $products]);
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    public function stats(Request $request)
    {
        $seller = $request->user();
        return response()->json([
            'success' => true,
            'data' => [
                'total'    => Pack::where('seller_id', $seller->id)->count(),
                'active'   => Pack::where('seller_id', $seller->id)->where('is_active', true)->count(),
                'approved' => Pack::where('seller_id', $seller->id)->where('is_approved', true)->count(),
                'pending'  => Pack::where('seller_id', $seller->id)->where('is_approved', false)->count(),
            ],
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Replace all pack items with the new payload.
     * $itemsData = [['product_id' => X, 'variant_id' => Y|null, 'quantity' => N], ...]
     */
    private function syncItems(Pack $pack, array $itemsData): void
{
    $pack->items()->delete();

    foreach ($itemsData as $idx => $row) {
        $product = Product::find((int) $row['product_id']);
        if (!$product) continue;

        // allowed_variant_ids: array of variant IDs seller wants to expose,
        // or null/empty = all active variants
        $allowedIds = null;
        if (!empty($row['allowed_variant_ids']) && is_array($row['allowed_variant_ids'])) {
            $allowedIds = array_values(array_map('intval', $row['allowed_variant_ids']));
        }

        // Snapshot: use lowest available variant price (or product price)
        $unitPrice = (float) $product->price;
        if ($allowedIds) {
            $override = \App\Models\ProductVariant::whereIn('id', $allowedIds)
                ->whereNotNull('price_override')
                ->min('price_override');
            if ($override !== null) $unitPrice = (float) $override;
        }

        PackItem::create([
            'pack_id'              => $pack->id,
            'product_id'           => $product->id,
            'allowed_variant_ids'  => $allowedIds,
            'quantity'             => max(1, (int) ($row['quantity'] ?? 1)),
            'unit_price_snapshot'  => $unitPrice,
            'order'                => $idx,
        ]);
    }
}

    /**
     * Format a pack for the API response.
     */
    private function formatPack(Pack $pack, bool $withItems = false): array
{
    $data = [
        'id'                => $pack->id,
        'name'              => $pack->name,
        'slug'              => $pack->slug,
        'description'       => $pack->description,
        'short_description' => $pack->short_description,
        'image_url'         => $pack->image_url,
        'pack_price'        => (float) $pack->pack_price,
        'original_price'    => (float) $pack->original_price,
        'savings'           => $pack->savings,
        'is_active'         => $pack->is_active,
        'is_approved'       => $pack->is_approved,
        'views'             => $pack->views,
        'created_at'        => $pack->created_at,
    ];

    if ($withItems) {
        $items = $pack->relationLoaded('items')
            ? $pack->items
            : $pack->items()->with([
                'product:id,name,slug,price,is_active,is_approved',
                'product.primaryImage',
                'product.variants' => fn($q) => $q
                    ->where('is_active', true)
                    ->with(['attributeOptions.attribute:id,slug,name,type']),
            ])->orderBy('order')->get();

        $minUnits = PHP_INT_MAX;

        $data['items'] = $items->map(function ($item) use (&$minUnits) {

            $allVariants = $item->product
                ? $item->product->variants
                : collect();

            $allowedIds = $item->allowed_variant_ids;

            $availableVariants = $allVariants
                ->when(
                    !empty($allowedIds),
                    fn($c) => $c->whereIn('id', $allowedIds)
                )
                ->map(fn($v) => [
                    'id'             => $v->id,
                    'label'          => $v->label,
                    'stock'          => $v->stock,
                    'price_override' => $v->price_override,
                    'option_map'     => $v->option_map,
                ])
                ->values();

            $maxStock = $availableVariants->isNotEmpty()
                ? $availableVariants->max('stock')
                : ($item->product?->stock ?? 0);

            $units    = $item->quantity > 0
                ? intdiv((int) $maxStock, (int) $item->quantity)
                : 0;
            $minUnits = min($minUnits, $units);

            return [
                'id'                  => $item->id,
                'product_id'          => $item->product_id,
                'allowed_variant_ids' => $item->allowed_variant_ids,
                'quantity'            => $item->quantity,
                'unit_price_snapshot' => (float) $item->unit_price_snapshot,
                'order'               => $item->order,
                'available_variants'  => $availableVariants,
                'product'             => $item->product ? [
                    'id'                => $item->product->id,
                    'name'              => $item->product->name,
                    'slug'              => $item->product->slug,
                    'price'             => (float) $item->product->price,
                    'primary_image_url' => $item->product->primary_image_url,
                    'has_variants'      => $allVariants->isNotEmpty(),
                ] : null,
            ];
        })->values();

        $data['available_stock'] = $minUnits === PHP_INT_MAX ? 0 : $minUnits;
    }

    return $data;
}
    /**
     * Unique slug generator (also used by Pack model, mirrored here for update slugging).
     */
    public static function uniquePackSlug(string $name, ?int $excludeId = null): string
    {
        $base    = Str::slug($name);
        $slug    = $base;
        $counter = 2;
        while (true) {
            $q = DB::table('packs')->where('slug', $slug);
            if ($excludeId) $q->where('id', '!=', $excludeId);
            if (!$q->exists()) break;
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }
}