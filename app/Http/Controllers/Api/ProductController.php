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
     * CHANGES vs previous version:
     *   - Sponsored products are ranked first via orderByDesc('is_sponsored')
     *     then orderByDesc('sponsored_priority') within sponsored group.
     *   - is_sponsored field included in the transformed product payload
     *     so the frontend can render the SponsoredBadge.
     *   - All existing filters, pagination, and transform logic unchanged.
     */
    public function index(Request $request)
    {
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
                    ]),
            ]);

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

        // ── Sorting ────────────────────────────────────────────────────────
        // Sponsored products always float to the top regardless of the
        // user-selected sort. Within each tier (sponsored / non-sponsored),
        // the user-selected sort is applied normally.
        $sort = $request->query('sort', 'created_at');

        // Step 1: sponsored tier always wins
        $query
            ->orderByDesc('is_sponsored')           // sponsored products first
            ->orderByDesc('sponsored_priority');     // higher priority wins within sponsored

        // Step 2: user-selected sort applied within each tier
        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'views'      => $query->orderByDesc('views'),
            default      => $query->orderByDesc('created_at'),
        };

        $perPage  = (int) $request->query('per_page', 20);
        $products = $query->paginate(min($perPage, 60));

        $products->getCollection()->transform(function ($p) {
            $p->primary_image_url = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;

            // ── Expose is_sponsored so the frontend can show the badge ──
            $p->is_sponsored       = (bool) $p->is_sponsored;
            $p->sponsored_priority = (int)  $p->sponsored_priority;

            $swatches = [];
            $seen     = [];
            foreach ($p->variants as $variant) {
                foreach ($variant->attributeOptions as $opt) {
                    if (
                        $opt->attribute &&
                        $opt->attribute->slug === 'color' &&
                        !in_array($opt->id, $seen, true)
                    ) {
                        $seen[]     = $opt->id;
                        $swatches[] = [
                            'id'        => $opt->id,
                            'value'     => $opt->value,
                            'color_hex' => $opt->color_hex,
                        ];
                    }
                }
            }
            $p->color_swatches = $swatches;
            $p->setRelation('variants', $p->variants->map(fn($v) => [
                'id'    => $v->id,
                'stock' => $v->stock,
            ])->values());

            return $p;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * GET /api/products/featured  — UNCHANGED
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
     *
     * ═══════════════════════════════════════════════════════════════════
     * FIX 1 — duplicate React key "color-104"
     * ═══════════════════════════════════════════════════════════════════
     * $colorGroups is keyed by primaryId (int), not groupKey string.
     * First-write wins → only ONE entry per primaryId.
     * array_values() then produces [{id:104,...},{id:105,...}] — unique.
     * We still store `group_key` inside each entry so the frontend can
     * look up color_images[group_key] without any guessing.
     *
     * ═══════════════════════════════════════════════════════════════════
     * FIX 2 — multi-color variant icon shows color circles instead of image
     * ═══════════════════════════════════════════════════════════════════
     * For multi-color variants (e.g. Grey+Silver), images are stored with
     * variant_id only — no color_option_id. The old code only populated
     * $colorPrimaryImage from product_images.color_option_id rows, so
     * multi-color variants always got primary_image = null in the axis
     * options, causing the frontend to fall back to color circles.
     *
     * Fix: build a $variantPrimaryImage map (variant_id → first image URL)
     * during the variants payload loop, then use it as a final fallback
     * when constructing $colorGroups so every option gets a real image.
     * ═══════════════════════════════════════════════════════════════════
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

        // ── Attribute data ─────────────────────────────────────────────────
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

        // ── Primary + all images ───────────────────────────────────────────
        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path)
            : null;

        $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

        // ── Variants + axes + color image map ──────────────────────────────
        $hasVariants          = $product->variants->isNotEmpty();
        $variantsPayload      = [];
        $selectableAxes       = [];
        $colorImages          = [];   // string key → url[]
        $colorPrimaryImage    = [];   // string key → first url
        $variantPrimaryImage  = [];   // int variant_id → first image URL  ← FIX 2

        if ($hasVariants) {

            // Product-level images (no color_option_id, no variant_id)
            $productImageUrls = $product->images
                ->filter(fn($i) => is_null($i->variant_id) && is_null($i->color_option_id))
                ->map(fn($i) => Storage::url($i->image_path))
                ->values()
                ->toArray();

            // ── colorGroupMap: primaryOptId → sorted ids[] ─────────────────
            $colorGroupMap = [];

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

            // ── Map saved images (with color_option_id) to groupKey ────────
            $product->images
                ->filter(fn($i) => $i->color_option_id !== null)
                ->each(function ($img) use (&$colorImages, &$colorPrimaryImage, $colorGroupMap) {
                    $cid      = $img->color_option_id;
                    $groupIds = $colorGroupMap[$cid] ?? [$cid];
                    $groupKey = implode('|', $groupIds);
                    $url      = Storage::url($img->image_path);

                    // Store under full groupKey
                    $colorImages[$groupKey][] = $url;
                    if ($img->is_primary || !isset($colorPrimaryImage[$groupKey])) {
                        $colorPrimaryImage[$groupKey] = $url;
                    }

                    // Also store under plain string id for backward compat
                    $strId = (string) $cid;
                    $colorImages[$strId][] = $url;
                    if ($img->is_primary || !isset($colorPrimaryImage[$strId])) {
                        $colorPrimaryImage[$strId] = $url;
                    }
                });

            // ── Variants payload ───────────────────────────────────────────
            $variantsPayload = $product->variants->map(function ($v) use (
                $productImageUrls, &$colorImages, &$colorPrimaryImage, &$variantPrimaryImage
            ) {
                $variantImageUrls = $v->images
                    ->map(fn($i) => Storage::url($i->image_path))
                    ->values()
                    ->toArray();

                // ── FIX 2: record first image per variant_id ───────────────
                if (!empty($variantImageUrls)) {
                    $variantPrimaryImage[$v->id] = $variantImageUrls[0];
                }

                $colorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')
                    ->values();

                $colorOptId = null;
                $groupKey   = null;

                if ($colorOpts->isNotEmpty()) {
                    $colorOptId = $colorOpts->first()->id;
                    $groupKey   = $colorOpts->pluck('id')->implode('|');
                    $strId      = (string) $colorOptId;

                    foreach ($variantImageUrls as $url) {
                        if (!in_array($url, $colorImages[$groupKey] ?? [])) {
                            $colorImages[$groupKey][] = $url;
                        }
                        if (!in_array($url, $colorImages[$strId] ?? [])) {
                            $colorImages[$strId][] = $url;
                        }
                    }
                    if ($variantImageUrls) {
                        $colorPrimaryImage[$groupKey] ??= $variantImageUrls[0];
                        $colorPrimaryImage[$strId]    ??= $variantImageUrls[0];
                    }
                }

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
                    'option_map'        => $v->option_map,
                    'color_option_id'   => $colorOptId,
                    'color_group_key'   => $groupKey,
                    'image_urls'        => $resolvedImages,
                    'primary_image_url' => $resolvedImages[0] ?? null,
                ];
            })->values();

            // ── selectable_axes ────────────────────────────────────────────
            $axisMap             = [];
            $colorGroups         = [];   // int primaryId → option entry
            $registeredOptionIds = [];   // string axisSlug → int[]

            foreach ($product->variants as $variant) {
                $colorOpts = $variant->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')
                    ->values();

                $nonColorOpts = $variant->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug !== 'color');

                // Color axis shell
                if ($colorOpts->isNotEmpty() && !isset($axisMap['color'])) {
                    $axisMap['color'] = [
                        'slug'    => 'color',
                        'name'    => $colorOpts->first()->attribute->name,
                        'type'    => 'color',
                        'options' => [],
                    ];
                }

                // ── FIX 1: key by primaryId ────────────────────────────────
                if ($colorOpts->isNotEmpty()) {
                    $primaryId = $colorOpts->first()->id;
                    $groupKey  = $colorOpts->pluck('id')->implode('|');

                    if (!isset($colorGroups[$primaryId])) {
                        // ── FIX 2: fallback chain for primary_image ─────────
                        $resolvedPrimaryImage =
                            $colorPrimaryImage[$groupKey]
                            ?? $variantPrimaryImage[$variant->id]
                            ?? null;

                        $colorGroups[$primaryId] = [
                            'id'            => $primaryId,
                            'group_key'     => $groupKey,
                            'ids'           => $colorOpts->pluck('id')->toArray(),
                            'value'         => $colorOpts->pluck('value')->implode('+'),
                            'color_hex'     => $colorOpts->first()->color_hex,
                            'swatches'      => $colorOpts->map(fn($o) => [
                                'id'        => $o->id,
                                'value'     => $o->value,
                                'color_hex' => $o->color_hex,
                            ])->toArray(),
                            'primary_image' => $resolvedPrimaryImage,
                        ];
                    }
                }

                // Non-color axes
                foreach ($nonColorOpts as $opt) {
                    $axisSlug = $opt->attribute->slug;
                    $optId    = $opt->id;

                    if (!isset($axisMap[$axisSlug])) {
                        $axisMap[$axisSlug] = [
                            'slug'    => $axisSlug,
                            'name'    => $opt->attribute->name,
                            'type'    => $opt->attribute->type,
                            'options' => [],
                        ];
                        $registeredOptionIds[$axisSlug] = [];
                    }

                    if (in_array($optId, $registeredOptionIds[$axisSlug], true)) {
                        continue;
                    }

                    $axisMap[$axisSlug]['options'][$optId] = [
                        'id'            => $optId,
                        'value'         => $opt->value,
                        'color_hex'     => $opt->color_hex,
                        'primary_image' => null,
                    ];
                    $registeredOptionIds[$axisSlug][] = $optId;
                }
            }

            // Attach color group options (unique by primaryId)
            if (isset($axisMap['color'])) {
                $axisMap['color']['options'] = array_values($colorGroups);
            }

            // Flatten non-color options to sequential arrays
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
     * GET /api/categories/{slug}/filter-attributes  — UNCHANGED
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

    /**
     * POST /api/products/by-ids  — UNCHANGED
     */
    public function byIds(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|max:50',
            'ids.*' => 'integer|min:1',
        ]);

        $ids  = $request->input('ids');
        $rows = DB::table('products as p')
            ->select([
                'p.id', 'p.name', 'p.slug', 'p.description',
                'p.price', 'p.stock', 'p.views', 'p.featured',
                'c.name  as category_name',
                'c.slug  as category_slug',
                's.name  as subcategory_name',
                's.slug  as subcategory_slug',
                'pi.image_path as primary_image',
            ])
            ->leftJoin('categories as c',    'c.id',  '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id',  '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', function ($join) {
                $join->on('pi.product_id', '=', 'p.id')
                     ->where('pi.is_primary', '=', 1);
            })
            ->whereIn('p.id', $ids)
            ->where('p.is_approved', 1)
            ->where('p.is_active',   1)
            ->whereNull('p.deleted_at')
            ->get();

        $indexed = $rows->keyBy('id');

        $ordered = collect($ids)
            ->map(function ($id) use ($indexed) {
                $p = $indexed->get($id);
                if (!$p) return null;
                return [
                    'id'               => $p->id,
                    'name'             => $p->name,
                    'slug'             => $p->slug,
                    'description'      => $p->description,
                    'price'            => (float) $p->price,
                    'stock'            => (int)   $p->stock,
                    'views'            => (int)   ($p->views    ?? 0),
                    'featured'         => (bool)  ($p->featured ?? false),
                    'category_name'    => $p->category_name,
                    'category_slug'    => $p->category_slug,
                    'subcategory_name' => $p->subcategory_name,
                    'subcategory_slug' => $p->subcategory_slug,
                    'primary_image'    => $p->primary_image
                        ? Storage::url($p->primary_image)
                        : null,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'success'  => true,
            'products' => $ordered,
            'count'    => $ordered->count(),
        ]);
    }
}