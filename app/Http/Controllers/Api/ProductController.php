<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * GET /api/products
     *
     * CHANGES vs. original:
     *  - Added 'variants.attributeOptions.attribute' to eager-loads so we can
     *    extract color swatches without extra queries (single JOIN, not N+1).
     *  - In the transform loop, we extract a `color_swatches` array
     *    [{id, value, color_hex}] for every distinct color option across
     *    the product's active variants.  The frontend card renders these as
     *    small colored circles.
     *  - Everything else is IDENTICAL to the original.
     */
    public function index(Request $request)
    {
        $query = Product::available()
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'primaryImage',
                'seller:id,name',
                // ── NEW: load active variants with their color option data ──
                // Uses a constrained eager-load so only active variants are
                // fetched, keeping the query lean.
                'variants' => fn($q) => $q
                    ->where('is_active', true)
                    ->with([
                        'attributeOptions' => fn($q2) => $q2
                            ->with('attribute:id,slug,type'),
                    ]),
            ]);

        // ── Filters (unchanged) ────────────────────────────────────────────
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
        $sort = $request->query('sort', 'created_at');
        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'views'      => $query->orderByDesc('views'),
            default      => $query->orderByDesc('created_at'),
        };

        $perPage  = (int) $request->query('per_page', 20);
        $products = $query->paginate(min($perPage, 60));

        $products->getCollection()->transform(function ($p) {
            // ── Primary image URL (unchanged) ──────────────────────────────
            $p->primary_image_url = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;

            // ── NEW: color_swatches ────────────────────────────────────────
            // Walk the already-loaded variants → attributeOptions and collect
            // every option whose parent attribute has slug = 'color'.
            // De-duplicate by option id so the same color isn't listed twice
            // (e.g. Red appears in Red/S AND Red/M → one swatch).
            $swatches = [];
            $seen     = [];

            foreach ($p->variants as $variant) {
                foreach ($variant->attributeOptions as $opt) {
                    // Guard: attribute must be the color axis
                    if (
                        $opt->attribute &&
                        $opt->attribute->slug === 'color' &&
                        !in_array($opt->id, $seen, true)
                    ) {
                        $seen[]    = $opt->id;
                        $swatches[] = [
                            'id'        => $opt->id,
                            'value'     => $opt->value,
                            'color_hex' => $opt->color_hex,
                        ];
                    }
                }
            }

            $p->color_swatches = $swatches;

            // Hide the raw variants relation from the JSON response —
            // the card only needs color_swatches, not the full variant tree.
            $p->unsetRelation('variants');

            return $p;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * GET /api/products/featured
     * UNCHANGED
     */
    public function featured()
    {
        $products = Product::available()->featured()->inStock()
            ->with(['category:id,name,slug', 'primaryImage'])
            ->orderByDesc('created_at')
            ->take(12)->get()
            ->map(function ($p) {
                $p->primary_image_url = $p->primaryImage
                    ? Storage::url($p->primaryImage->image_path)
                    : null;
                return $p;
            });

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * GET /api/products/{slug}
     * UNCHANGED — full variant/image logic is already correct here.
     */
   public function show($slug)
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

    // ── Product-level attribute data (unchanged) ────────────────────────────
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

    // ── Product-level images (unchanged) ───────────────────────────────────
    $product->primary_image_url = $product->primaryImage
        ? Storage::url($product->primaryImage->image_path)
        : null;

    $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

    // ── Variants + axes + color image map ──────────────────────────────────
    $hasVariants     = $product->variants->isNotEmpty();
    $variantsPayload = [];
    $selectableAxes  = [];
    $colorImages     = [];      // groupKey  → [url, ...]
    $colorPrimaryImage = [];    // groupKey  → url

    if ($hasVariants) {

        // Product-level images (no color_option_id, no variant_id)
        $productImageUrls = $product->images
            ->filter(fn($i) => is_null($i->variant_id) && is_null($i->color_option_id))
            ->map(fn($i) => Storage::url($i->image_path))
            ->values()
            ->toArray();

        // ── Build colorImages keyed by GROUP KEY ("3|7"), not single opt id ─
        //
        // Images were saved with color_option_id = primaryColorOptionId
        // (the lowest sorted ID in the group).  We need to map them back
        // to the full group key.
        //
        // Step 1: collect all unique color groups from variants
        $colorGroupMap = [];   // primaryOptId (int) → sorted ids array
        foreach ($product->variants as $v) {
            $colorOpts = $v->attributeOptions
                ->filter(fn($o) => $o->attribute->slug === 'color')
                ->sortBy('id')
                ->values();

            if ($colorOpts->isEmpty()) continue;

            $primaryId = $colorOpts->first()->id;
            $allIds    = $colorOpts->pluck('id')->toArray();

            if (!isset($colorGroupMap[$primaryId])) {
                $colorGroupMap[$primaryId] = $allIds;
            }
        }

        // Step 2: load product images that have color_option_id set
        // and map them to their group key
        $product->images
            ->filter(fn($i) => $i->color_option_id !== null)
            ->each(function ($img) use (&$colorImages, &$colorPrimaryImage, $colorGroupMap) {
                $cid      = $img->color_option_id;
                $groupIds = $colorGroupMap[$cid] ?? [$cid];
                $groupKey = implode('|', $groupIds);
                $url      = Storage::url($img->image_path);

                $colorImages[$groupKey][] = $url;
                if ($img->is_primary || !isset($colorPrimaryImage[$groupKey])) {
                    $colorPrimaryImage[$groupKey] = $url;
                }

                // ALSO keep backward-compat entry keyed by single primary opt id
                // so existing frontend code using color_images[colorOptId] still works
                $colorImages[$cid][] = $url;
                if ($img->is_primary || !isset($colorPrimaryImage[$cid])) {
                    $colorPrimaryImage[$cid] = $url;
                }
            });

        // ── Build variants payload ─────────────────────────────────────────
        $variantsPayload = $product->variants->map(function ($v) use (
            $productImageUrls, &$colorImages, &$colorPrimaryImage
        ) {
            $variantImageUrls = $v->images
                ->map(fn($i) => Storage::url($i->image_path))
                ->values()
                ->toArray();

            // Collect all color options for this variant (sorted by id)
            $colorOpts = $v->attributeOptions
                ->filter(fn($o) => $o->attribute->slug === 'color')
                ->sortBy('id')
                ->values();

            $colorOptId = null;
            $groupKey   = null;

            if ($colorOpts->isNotEmpty()) {
                $colorOptId = $colorOpts->first()->id;    // primary (lowest) id
                $groupKey   = $colorOpts->pluck('id')->implode('|');

                // Merge variant's own images into the group bucket
                foreach ($variantImageUrls as $url) {
                    if (!in_array($url, $colorImages[$groupKey] ?? [])) {
                        $colorImages[$groupKey][] = $url;
                    }
                    // backward-compat single-id key
                    if (!in_array($url, $colorImages[$colorOptId] ?? [])) {
                        $colorImages[$colorOptId][] = $url;
                    }
                }
                if ($variantImageUrls) {
                    $colorPrimaryImage[$groupKey]   ??= $variantImageUrls[0];
                    $colorPrimaryImage[$colorOptId] ??= $variantImageUrls[0];
                }
            }

            // Image resolution priority: variant > group > product
            $resolvedImages = $variantImageUrls;
            if (empty($resolvedImages) && $groupKey && !empty($colorImages[$groupKey])) {
                $resolvedImages = array_values(array_unique($colorImages[$groupKey]));
            }
            if (empty($resolvedImages)) {
                $resolvedImages = $productImageUrls;
            }

            return [
                'id'                => $v->id,
                'sku'               => $v->sku,
                'stock'             => $v->stock,
                'is_active'         => $v->is_active,
                'price'             => $v->effective_price,
                'price_override'    => $v->price_override,
                'label'             => $v->label,
                'option_map'        => $v->option_map,   // now includes 'ids' for color
                'color_option_id'   => $colorOptId,      // primary color opt id (backward compat)
                'color_group_key'   => $groupKey,        // e.g. "3|7"
                'image_urls'        => $resolvedImages,
                'primary_image_url' => $resolvedImages[0] ?? null,
            ];
        })->values();

        // ── Build selectable_axes ──────────────────────────────────────────
        //
        // KEY CHANGE: the color axis exposes COLOR GROUPS as options,
        // not individual colors.  Each group option gets:
        //   id        = primaryColorOptId  (lowest id, used as the selection key)
        //   ids       = [3, 7]            (full group, for matching)
        //   value     = "Red+Blue"
        //   color_hex = hex of first color (for the swatch circle)
        //   swatches  = [{id,value,color_hex}, ...]  (for multi-dot display)
        //   primary_image = first image url for this group
        //
        // Non-color axes are unchanged.

        $axisMap      = [];   // slug → axis definition
        $colorGroups  = [];   // groupKey → group option entry (de-duplicated)

        foreach ($product->variants as $variant) {
            $colorOpts = $variant->attributeOptions
                ->filter(fn($o) => $o->attribute->slug === 'color')
                ->sortBy('id')
                ->values();

            $nonColorOpts = $variant->attributeOptions
                ->filter(fn($o) => $o->attribute->slug !== 'color');

            // Register the color axis itself
            if ($colorOpts->isNotEmpty() && !isset($axisMap['color'])) {
                $firstColorAttr = $colorOpts->first()->attribute;
                $axisMap['color'] = [
                    'slug'    => 'color',
                    'name'    => $firstColorAttr->name,
                    'type'    => 'color',
                    'options' => [],   // filled below from $colorGroups
                ];
            }

            // Register this color group as one selectable option
            if ($colorOpts->isNotEmpty()) {
                $groupKey   = $colorOpts->pluck('id')->implode('|');
                $primaryId  = $colorOpts->first()->id;

                if (!isset($colorGroups[$groupKey])) {
                    $colorGroups[$groupKey] = [
                        // 'id' is the value the frontend stores in selectedOptions['color']
                        'id'            => $primaryId,
                        // 'ids' lets the frontend do a containment check for matching
                        'ids'           => $colorOpts->pluck('id')->toArray(),
                        'value'         => $colorOpts->pluck('value')->implode('+'),
                        'color_hex'     => $colorOpts->first()->color_hex,
                        // swatches for rendering multiple dots in the swatch button
                        'swatches'      => $colorOpts->map(fn($o) => [
                            'id'        => $o->id,
                            'value'     => $o->value,
                            'color_hex' => $o->color_hex,
                        ])->toArray(),
                        'primary_image' => $colorPrimaryImage[$groupKey] ?? null,
                    ];
                }
            }

            // Non-color axes: one entry per individual option (unchanged)
            foreach ($nonColorOpts as $opt) {
                $slug = $opt->attribute->slug;
                if (!isset($axisMap[$slug])) {
                    $axisMap[$slug] = [
                        'slug'    => $slug,
                        'name'    => $opt->attribute->name,
                        'type'    => $opt->attribute->type,
                        'options' => [],
                    ];
                }
                $axisMap[$slug]['options'][$opt->id] = [
                    'id'            => $opt->id,
                    'value'         => $opt->value,
                    'color_hex'     => $opt->color_hex,
                    'primary_image' => null,
                ];
            }
        }

        // Attach de-duplicated color group options to the color axis
        if (isset($axisMap['color'])) {
            $axisMap['color']['options'] = array_values($colorGroups);
        }

        // Flatten other axes options
        foreach ($axisMap as $slug => &$axis) {
            if ($slug !== 'color') {
                $axis['options'] = array_values($axis['options']);
            }
        }
        unset($axis);

        $selectableAxes = array_values($axisMap);
    }

    $data                    = $product->toArray();
    $data['has_variants']    = $hasVariants;
    $data['variants']        = $variantsPayload;
    $data['selectable_axes'] = $selectableAxes;
    $data['attribute_data']  = $product->attribute_data;
    $data['color_images']    = $colorImages;

    return response()->json(['success' => true, 'data' => $data]);
}

    /**
     * GET /api/categories/{slug}/filter-attributes
     * UNCHANGED
     */
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
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('attribute_id');

        $variantAttrIds = DB::table('product_variants as pv')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->whereIn('pv.product_id', $productIds)
            ->distinct()
            ->pluck('ao.attribute_id');

        $allAttrIds = $nonVariantAttrIds->merge($variantAttrIds)->unique()->values();

        if ($allAttrIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $attributes = Attribute::whereIn('id', $allAttrIds)
            ->where('is_filterable', true)
            ->with(['options' => fn($q) => $q->orderBy('order')])
            ->orderBy('order')
            ->get()
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
}