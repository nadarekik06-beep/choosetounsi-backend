<?php
// app/Http/Controllers/Api/ProductController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Services\PromotionService;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function __construct(
        private ProductScoringService $scoringService,
        private UserPreferenceService $preferenceService,
        private PromotionService      $promoService,
    ) {}

    public function index(Request $request)
    {
        $sort = $request->query('sort', 'created_at');
        $user = $request->user();

        // Scoring applies on the default sort when user is authenticated
        $applyScoring = ($sort === 'created_at') && ($user !== null);

        $query = Product::available()
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'primaryImage',
                'seller:id,name',
                'variants' => fn($q) => $q
    ->where('is_active', true)
    ->with([
        'attributeOptions' => fn($q2) => $q2
            ->with('attribute:id,slug,type'),
        'images',           // ← CORRECT: sibling of attributeOptions, not nested inside it
    ]),
            ]);

        // attributeValues needed for brand + gender scoring
        if ($applyScoring) {
            $query->with(['attributeValues.attribute']);
        }

        // ── Filters ──────────────────────────────────────────────────────────
        if ($search = $request->query('search')) {
            $query->where(fn($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%")
            );
        }
        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        } elseif ($catSlug = $request->query('category_slug')) {
            $query->whereHas('category', fn($q) => $q->where('slug', $catSlug));
        }
        if ($subId = $request->query('subcategory_id')) {
            $query->where('subcategory_id', $subId);
        } elseif ($subSlug = $request->query('subcategory_slug')) {
            $query->whereHas('subcategory', fn($q) => $q->where('slug', $subSlug));
        }
        if ($priceMin = $request->query('price_min')) {
            $query->where('price', '>=', (float) $priceMin);
        }
        if ($priceMax = $request->query('price_max')) {
            $query->where('price', '<=', (float) $priceMax);
        }
        if (filter_var($request->query('in_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('stock', '>', 0);
        }
        if ($attrs = $request->query('attrs')) {
            foreach ($attrs as $slug => $values) {
                $query->hasAttribute($slug, (array) $values);
            }
        }
        if ($request->filled('is_pack')) {
            $query->where('is_pack', (int) $request->query('is_pack'));
        }
        if ($request->filled('is_platform_product')) {
            $query->where('is_platform_product', (bool) $request->boolean('is_platform_product'));
        }

        // ── Scoring path (authenticated user, default sort) ───────────────
        if ($applyScoring) {
            $perPage = min((int) $request->query('per_page', 20), 60);
            $page    = max((int) $request->query('page', 1), 1);

            $allProducts = $query
                ->orderByDesc('is_sponsored')
                ->orderByDesc('sponsored_priority')
                ->limit(200)
                ->get();

            $prefs           = $this->preferenceService->getCombinedPreferences($user->id);
            $activityWeights = $this->preferenceService->getActivityWeights($user->id);

            $sorted = $this->scoringService->scoreAndSort($allProducts, $prefs, $activityWeights);

            if ($sorted->isEmpty()) {
                $sorted = $this->buildFallbackProducts($request, $user, $prefs, $activityWeights);
            }

            $total  = $sorted->count();
            $offset = ($page - 1) * $perPage;

            return response()->json(['success' => true, 'data' => [
                'current_page' => $page,
                'data'         => $this->transformProductCollection(
                    $sorted->slice($offset, $perPage)->values()
                ),
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
                'from'         => $offset + 1,
                'to'           => min($offset + $perPage, $total),
            ]]);
        }

        // ── Non-scoring path (guest, or explicit sort) ────────────────────
        $query->orderByDesc('is_sponsored')->orderByDesc('sponsored_priority');
        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'views'      => $query->orderByDesc('views'),
            default      => $query->orderByDesc('created_at'),
        };
        $perPage  = (int) $request->query('per_page', 20);
        $products = $query->paginate(min($perPage, 60));
        $products->getCollection()->transform(fn($p) => $this->transformProductItem($p));

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * Fallback: when a filtered query returns 0 results, return similar
     * products from the same category or popular products globally.
     */
    private function buildFallbackProducts(
        Request $request,
        $user,
        $prefs,
        array $activityWeights
    ) {
        $catSlug = $request->query('category_slug');
        $catId   = $request->query('category_id');

        if ($catSlug || $catId) {
            $fallback = Product::available()
                ->with(['category:id,name,slug', 'subcategory:id,name,slug', 'primaryImage', 'seller:id,name', 'variants' => fn($q) => $q->where('is_active', true)->with(['attributeOptions' => fn($q2) => $q2->with('attribute:id,slug,type')]), 'attributeValues.attribute'])
                ->when($catSlug, fn($q) => $q->whereHas('category', fn($q2) => $q2->where('slug', $catSlug)))
                ->when(!$catSlug && $catId, fn($q) => $q->where('category_id', $catId))
                ->orderByDesc('is_sponsored')
                ->limit(60)
                ->get();

            if ($fallback->isNotEmpty()) {
                return $this->scoringService->scoreAndSort($fallback, $prefs, $activityWeights);
            }
        }

        $popular = Product::available()
            ->with(['category:id,name,slug', 'subcategory:id,name,slug', 'primaryImage', 'seller:id,name', 'variants' => fn($q) => $q->where('is_active', true)->with(['attributeOptions' => fn($q2) => $q2->with('attribute:id,slug,type')]), 'attributeValues.attribute'])
            ->orderByDesc('is_sponsored')
            ->orderByDesc('views')
            ->limit(60)
            ->get();

        return $this->scoringService->scoreAndSort($popular, $prefs, $activityWeights);
    }

    public function featured()
    {
        $products = Product::available()->featured()->inStock()
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name'])
            ->orderByDesc('created_at')
            ->take(12)
            ->get()
            ->map(function ($p) {
                return $this->transformProductItem($p);
            });

        return response()->json(['success' => true, 'data' => $products]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  show() — FULLY RESTORED
    //  The entire variant-building block was missing (replaced by a comment).
    //  This is the complete, correct implementation.
    // ═══════════════════════════════════════════════════════════════════════

    public function show(Request $request, $slug)
    {
        $product = Product::where('slug', $slug)
            ->available()
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'seller:id,name',
                'images',
                'primaryImage',
                'attributeValues.attribute.options',
                'variants' => fn($q) => $q->where('is_active', true)
                    ->with([
                        'attributeOptions.attribute:id,slug,name,type',
                        'images',
                    ]),
            ])
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $product->incrementViews();

        $user = $request->user();
        if ($user) {
            $this->preferenceService->logActivity(
                userId:     $user->id,
                productId:  $product->id,
                categoryId: $product->category_id,
                action:     'view',
                sessionId: $this->safeSessionId($request)
            );
        }

        // ── Attribute data (non-variant informational attributes) ──────────
        $product->attribute_data = $product->attributeValues->map(function ($pav) {
            $attr  = $pav->attribute;
            $value = $attr->decodeValue($pav->value);
            $label = $value;
            if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
                $ids   = (array) $value;
                $label = $attr->options->whereIn('id', $ids)->pluck('value')->join(', ');
            }
            return [
                'slug'  => $attr->slug,
                'name'  => $attr->name,
                'type'  => $attr->type,
                'value' => $value,
                'label' => $label,
            ];
        })->keyBy('slug');

        // ── Primary image URL ──────────────────────────────────────────────
        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path) : null;

        $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

        // ── Variant system ─────────────────────────────────────────────────
        $hasVariants         = $product->variants->isNotEmpty();
        $variantsPayload     = [];
        $selectableAxes      = [];
        $colorImages         = [];          // string key → url[]
        $colorPrimaryImage   = [];          // string key → first url
        $variantPrimaryImage = [];          // variant_id → first image url

        if ($hasVariants) {

            // Product-level images: no color_option_id AND no variant_id
            $productImageUrls = $product->images
                ->filter(fn($i) => is_null($i->variant_id) && is_null($i->color_option_id))
                ->map(fn($i) => Storage::url($i->image_path))
                ->values()
                ->toArray();

            
//
// FIX: The old colorGroupMap used first-write-wins, which caused images to be
// assigned to the wrong group when the same color ID appeared in multiple groups.
// (e.g. Black=8 in group [5,7,8] AND in solo group [8] — Black's solo images
// would be registered under "5|7|8" instead of "8".)
//
// New approach: build an authoritative map from variant definitions (each variant
// knows its exact color group), then resolve each image path to the correct group
// by exact match first, then smallest-containing-subset fallback.

// Build authoritative variant group registry: groupKey → groupKey (identity)
$variantGroupRegistry = [];  // e.g. "5|7|8" => "5|7|8", "8" => "8"

foreach ($product->variants as $v) {
    $colorIds = $v->attributeOptions
        ->filter(fn($o) => $o->attribute->slug === 'color')
        ->sortBy('id')
        ->pluck('id')
        ->toArray();

    if (empty($colorIds)) continue;

    sort($colorIds);
    $key = implode('|', $colorIds);
    $variantGroupRegistry[$key] = $key;
}

// Collect color images grouped by image_path (handles duplicate rows from old save format)
$pathToColorIds = [];

$product->images
    ->filter(fn($i) => $i->color_option_id !== null)
    ->each(function ($img) use (&$pathToColorIds) {
        $pathToColorIds[$img->image_path][] = (int) $img->color_option_id;
    });

foreach ($pathToColorIds as $path => $cids) {
    $storedIds = array_values(array_unique($cids));
    sort($storedIds);
    $storedKey = implode('|', $storedIds);

    // Exact match: new format stores primary ID only, or all IDs for the group
    if (isset($variantGroupRegistry[$storedKey])) {
        $groupKey = $storedKey;
    } else {
        // Subset match: old format may store only one ID from a multi-color group.
        // Find the smallest registered group that contains ALL stored IDs.
        $groupKey = null;
        foreach ($variantGroupRegistry as $vKey) {
            $vIds = array_map('intval', explode('|', $vKey));
            if (count(array_intersect($storedIds, $vIds)) === count($storedIds)) {
                if ($groupKey === null) {
                    $groupKey = $vKey;
                } else {
                    // Prefer the more specific (smaller) group
                    $curLen = substr_count($groupKey, '|') + 1;
                    $newLen = substr_count($vKey, '|') + 1;
                    if ($newLen < $curLen) $groupKey = $vKey;
                }
            }
        }
        // Last resort: use stored key as-is
        if ($groupKey === null) $groupKey = $storedKey;
    }

    $url = Storage::url($path);

    if (!in_array($url, $colorImages[$groupKey] ?? [])) {
        $colorImages[$groupKey][] = $url;
    }
    if (!isset($colorPrimaryImage[$groupKey])) {
        $colorPrimaryImage[$groupKey] = $url;
    }

    // Backward compat: also register under each individual color ID string
    foreach (array_map('intval', explode('|', $groupKey)) as $gid) {
        $strId = (string) $gid;
        if (!in_array($url, $colorImages[$strId] ?? [])) {
            $colorImages[$strId][] = $url;
        }
        if (!isset($colorPrimaryImage[$strId])) {
            $colorPrimaryImage[$strId] = $url;
        }
    }
}
            // ── Step 3: Build variantPrimaryImage from variant-linked images ─
            //
            // Some images are stored with variant_id (no color_option_id).
            // These are the per-variant images uploaded via VariantImageManager.
            // We expose them so the frontend can resolve image_urls per variant.
            foreach ($product->variants as $v) {
                if ($v->images->isEmpty()) continue;

                $primary = $v->images->firstWhere('is_primary', true)
                        ?? $v->images->sortBy('order')->first();

                if ($primary) {
                    $variantPrimaryImage[$v->id] = Storage::url($primary->image_path);
                }
            }

            // ── Step 4: Build variants payload ────────────────────────────────
// ── Step 3b: Bridge variant images → color group images ───────────────────
foreach ($product->variants as $v) {
    if (!isset($variantPrimaryImage[$v->id])) continue;

    $colorOpts = $v->attributeOptions
        ->filter(fn($o) => $o->attribute->slug === 'color')
        ->sortBy('id')
        ->values();

    if ($colorOpts->isEmpty()) continue;

    $colorIds = $colorOpts->pluck('id')->toArray();
    sort($colorIds);
    $groupKey = implode('|', $colorIds);

    $vImgUrls = $v->images
        ->map(fn($i) => Storage::url($i->image_path))
        ->values()
        ->toArray();

    if (empty($colorImages[$groupKey])) {
        $colorImages[$groupKey]      = $vImgUrls;
        $colorPrimaryImage[$groupKey] = $variantPrimaryImage[$v->id];

        foreach ($colorIds as $cid) {
            $strId = (string) $cid;
            if (empty($colorImages[$strId])) {
                $colorImages[$strId]      = $vImgUrls;
                $colorPrimaryImage[$strId] = $variantPrimaryImage[$v->id];
            }
        }
    }
}
            $variantsPayload = $product->variants->map(function ($v) use (
                $productImageUrls, $colorImages, $colorPrimaryImage, $variantPrimaryImage, $product
            ) {
                // Resolve image_urls for this variant:
                // Priority: variant's own images → color-group images → product images
                $variantImageUrls = $v->images
                    ->map(fn($i) => Storage::url($i->image_path))
                    ->values()
                    ->toArray();

                // Build the color group key for this variant
                $colorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')
                    ->values();

                $colorGroupKey = null;

                if ($colorOpts->isNotEmpty()) {
                    $colorIds      = $colorOpts->pluck('id')->toArray();
                    sort($colorIds);
                    $colorGroupKey = implode('|', $colorIds);

                    // If variant has no own images, fall back to color-group images
                    if (empty($variantImageUrls) && isset($colorImages[$colorGroupKey])) {
                        $variantImageUrls = $colorImages[$colorGroupKey];
                    }
                }

                // Final fallback: product-level images
                if (empty($variantImageUrls)) {
                    $variantImageUrls = $productImageUrls;
                }

                // Primary image for this variant
                $primaryImageUrl = $variantPrimaryImage[$v->id]
                    ?? ($colorGroupKey ? ($colorPrimaryImage[$colorGroupKey] ?? null) : null)
                    ?? $productImageUrls[0]
                    ?? null;

                $productBasePrice = (float) $product->price;
                $effectiveBase    = $v->price_override !== null
                    ? (float) $v->price_override
                    : $productBasePrice;

                return [
                    'id'              => $v->id,
                    'sku'             => $v->sku,
                    'stock'           => $v->stock,
                    'is_active'       => $v->is_active,
                    // Effective price = variant override OR product base price
                    'price'           => $effectiveBase,
                    'original_price'  => $productBasePrice,
                    'price_override'  => $v->price_override !== null ? (float) $v->price_override : null,
                    'label'           => $v->label,
                    // option_map is the accessor on ProductVariant — handles color group grouping
                    'option_map'      => $v->option_map,
                    // color_group_key: pipe-joined sorted color option IDs, e.g. "104|105"
                    // The frontend uses this to match selectedOptions['color'] against variants
                    'color_group_key' => $colorGroupKey,
                    // color_option_id: primary (lowest) color ID — for backward compat
                    'color_option_id' => $v->color_option_id,
                    // image_urls: ordered list of image URLs for this variant
                    'image_urls'      => $variantImageUrls,
                    'primary_image_url' => $primaryImageUrl,
                ];
            })->values()->toArray();

            // ── Step 5: Build selectable_axes ─────────────────────────────────
            //
            // selectable_axes tells the frontend which attribute axes to show
            // as selectors (color swatches, size buttons, etc.) and what options
            // are available for each axis — deduped across all variants.
            //
            // For the color axis: options are keyed by groupKey (e.g. "104|105")
            // so that multi-color variants appear as a single swatch entry.
            //
            // For non-color axes: options are keyed by attribute_option_id.

            $axesMap = [];   // slug → axis definition

            foreach ($product->variants as $v) {
                foreach ($v->attributeOptions as $opt) {
                    $attr = $opt->attribute;
                    $slug = $attr->slug;

                    if (!isset($axesMap[$slug])) {
                        $axesMap[$slug] = [
                            'slug'    => $slug,
                            'name'    => $attr->name,
                            'type'    => $slug === 'color' ? 'color' : 'select',
                            'options' => [],  // keyed by selectionKey
                        ];
                    }
                }
            }

            // Populate options for each axis from variants
            foreach ($product->variants as $v) {
                // ── Color axis ──────────────────────────────────────────────
                $colorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')
                    ->values();

                if ($colorOpts->isNotEmpty() && isset($axesMap['color'])) {
                    $colorIds  = $colorOpts->pluck('id')->toArray();
                    sort($colorIds);
                    $groupKey  = implode('|', $colorIds);

                    if (!isset($axesMap['color']['options'][$groupKey])) {
                        $primaryOpt = $colorOpts->first();

                        // primary_image: color-group image → variant primary image → null
                        $primaryImage = $colorPrimaryImage[$groupKey]
                            ?? $variantPrimaryImage[$v->id]
                            ?? null;

                        $axesMap['color']['options'][$groupKey] = [
                            'id'            => $primaryOpt->id,
                            'group_key'     => $groupKey,
                            'ids'           => $colorIds,
                            'value'         => $colorOpts->pluck('value')->join('+'),
                            'color_hex'     => $primaryOpt->color_hex,
                            'primary_image' => $primaryImage,
                            // swatches: individual color entries for rendering split circles
                            'swatches'      => $colorOpts->map(fn($o) => [
                                'id'        => $o->id,
                                'value'     => $o->value,
                                'color_hex' => $o->color_hex,
                            ])->toArray(),
                        ];
                    }
                }

                // ── Non-color axes ──────────────────────────────────────────
                $nonColorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug !== 'color');

                foreach ($nonColorOpts as $opt) {
                    $slug = $opt->attribute->slug;
                    if (!isset($axesMap[$slug])) continue;

                    $optionKey = (string) $opt->id;
                    if (!isset($axesMap[$slug]['options'][$optionKey])) {
                        $axesMap[$slug]['options'][$optionKey] = [
                            'id'            => $opt->id,
                            'value'         => $opt->value,
                            'color_hex'     => $opt->color_hex ?? null,
                            'primary_image' => null,
                        ];
                    }
                }
            }

            // Convert options maps to sequential arrays
            foreach ($axesMap as $slug => &$axis) {
                $axis['options'] = array_values($axis['options']);
            }
            unset($axis);

            // Only axes that have at least one option become selectable
            $selectableAxes = array_values(array_filter(
                $axesMap,
                fn($axis) => count($axis['options']) > 0
            ));
        }

        // ── Promotion data ─────────────────────────────────────────────────
        $promoData = $this->promoService->getEffectivePrice($product);

        // ── Assemble response ──────────────────────────────────────────────
        $data                    = $product->toArray();
        $data['has_variants']    = $hasVariants;
        $data['variants']        = $variantsPayload;
        $data['selectable_axes'] = $selectableAxes;
        $data['attribute_data']  = $product->attribute_data;
        $data['color_images']    = $colorImages;
        $data['effective_price'] = $promoData['effective_price'];
        $data['discount_amount'] = $promoData['discount_amount'];
        $data['promotion']       = $promoData['promotion'];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function filterAttributes($slug)
    {
        $productIds = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('c.slug', $slug)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->pluck('p.id');

        if ($productIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $nonVariantAttrIds = DB::table('product_attribute_values')
            ->whereIn('product_id', $productIds)->distinct()->pluck('attribute_id');
        $variantAttrIds = DB::table('product_variants as pv')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->whereIn('pv.product_id', $productIds)->distinct()->pluck('ao.attribute_id');

        $allAttrIds = $nonVariantAttrIds->merge($variantAttrIds)->unique()->values();
        if ($allAttrIds->isEmpty()) return response()->json(['success' => true, 'data' => []]);

        $attributes = Attribute::whereIn('id', $allAttrIds)
            ->where('is_filterable', true)
            ->with(['options' => fn($q) => $q->orderBy('order')])
            ->orderBy('order')->get()
            ->map(fn($a) => [
                'id'      => $a->id,
                'slug'    => $a->slug,
                'name'    => $a->name,
                'type'    => $a->type,
                'options' => $a->options->map(fn($o) => [
                    'id'        => $o->id,
                    'value'     => $o->value,
                    'color_hex' => $o->color_hex,
                ]),
            ]);

        return response()->json(['success' => true, 'data' => $attributes]);
    }

    public function byIds(\Illuminate\Http\Request $request)
    {
        $request->validate(['ids' => 'required|array|max:50', 'ids.*' => 'integer|min:1']);
        $ids  = $request->input('ids');
        $rows = DB::table('products as p')
            ->select([
                'p.id','p.name','p.slug','p.description','p.price','p.stock',
                'p.views','p.featured',
                'c.name as category_name','c.slug as category_slug',
                's.name as subcategory_name','s.slug as subcategory_slug',
                'pi.image_path as primary_image',
            ])
            ->leftJoin('categories as c',     'c.id',  '=', 'p.category_id')
            ->leftJoin('subcategories as s',   's.id',  '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', fn($join) =>
                $join->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', '=', 1)
            )
            ->whereIn('p.id', $ids)
            ->where('p.is_approved', 1)
            ->where('p.is_active', 1)
            ->whereNull('p.deleted_at')
            ->get();

        $indexed = $rows->keyBy('id');
        $ordered = collect($ids)->map(function ($id) use ($indexed) {
            $p = $indexed->get($id);
            if (!$p) return null;
            return [
                'id'               => $p->id,
                'name'             => $p->name,
                'slug'             => $p->slug,
                'description'      => $p->description,
                'price'            => (float) $p->price,
                'stock'            => (int) $p->stock,
                'views'            => (int) ($p->views ?? 0),
                'featured'         => (bool) ($p->featured ?? false),
                'category_name'    => $p->category_name,
                'category_slug'    => $p->category_slug,
                'subcategory_name' => $p->subcategory_name,
                'subcategory_slug' => $p->subcategory_slug,
                'primary_image'    => $p->primary_image ? Storage::url($p->primary_image) : null,
            ];
        })->filter()->values();

        return response()->json(['success' => true, 'products' => $ordered, 'count' => $ordered->count()]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

 
private function transformProductCollection($products): array
{
    $productIds = $products->pluck('id')->toArray();
    $colorImagesMap = $this->batchLoadColorImages($productIds);

    return $products->map(fn($p) => $this->transformProductItem($p, $colorImagesMap))
        ->values()
        ->toArray();
}
private function safeSessionId(Request $request): ?string
{
    try {
        return $request->session()->getId();
    } catch (\Throwable $e) {
        return null; // API routes may not have session — that's fine
    }
}

// AFTER — add variant_images collection before stripping:
private function transformProductItem($p, ?\Illuminate\Support\Collection $colorImagesMap = null): mixed
{
    $p->primary_image_url  = $p->primaryImage ? Storage::url($p->primaryImage->image_path) : null;
    $p->is_sponsored       = (bool) $p->is_sponsored;
    $p->sponsored_priority = (int)  $p->sponsored_priority;

    $swatches = []; $seen = [];
    foreach ($p->variants as $variant) {
        foreach ($variant->attributeOptions as $opt) {
            if ($opt->attribute && $opt->attribute->slug === 'color'
                && !in_array($opt->id, $seen, true)
            ) {
                $seen[]     = $opt->id;
                $swatches[] = ['id' => $opt->id, 'value' => $opt->value, 'color_hex' => $opt->color_hex];
            }
        }
    }
    $p->color_swatches = $swatches;

    // Load color-keyed images directly — these have color_option_id set, NOT variant_id
// AFTER (uses pre-loaded map, falls back to single query if map not provided):
$variantImages = [];
$colorImgs = $colorImagesMap
    ? $colorImagesMap->get($p->id, collect())
    : \App\Models\ProductImage::where('product_id', $p->id)
        ->whereNotNull('color_option_id')
        ->select('image_path')
        ->get();
foreach ($colorImgs as $img) {
    $url = Storage::url($img->image_path);
    if (!in_array($url, $variantImages, true)) {
        $variantImages[] = $url;
    }
}
$p->variant_images = $variantImages;
    $p->setRelation('variants', $p->variants->map(fn($v) => ['id' => $v->id, 'stock' => $v->stock])->values());

    $promoData          = $this->promoService->getEffectivePrice($p);
    $p->effective_price = $promoData['effective_price'];
    $p->discount_amount = $promoData['discount_amount'];
    $p->promotion       = $promoData['promotion'];

    return $p;
}
// Add this NEW private method to ProductController:
private function batchLoadColorImages(array $productIds): \Illuminate\Support\Collection
{
    return \App\Models\ProductImage::whereIn('product_id', $productIds)
        ->whereNotNull('color_option_id')
        ->select('product_id', 'image_path')
        ->get()
        ->groupBy('product_id');
}
}