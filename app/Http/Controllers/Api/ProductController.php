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
     */
    public function index(Request $request)
    {
        $query = Product::available()
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'primaryImage',
                'seller:id,name',
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
            $p->primary_image_url = $p->primaryImage
                ? Storage::url($p->primaryImage->image_path)
                : null;
            return $p;
        });

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * GET /api/products/featured
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
     * Returns full product including:
     *   - attribute_data   (product-level attributes)
     *   - has_variants     (bool)
     *   - variants[]       (each with images[], image_urls[], color_option_id)
     *   - selectable_axes[]
     *   - color_images{}   (keyed by color option_id → [url, url, …])
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
                // Variants with their images AND attribute options
                'variants' => fn($q) => $q->where('is_active', true)
                    ->with([
                        'attributeOptions.attribute:id,slug,name,type',
                        'images',                          // variant-specific images
                    ]),
            ])
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $product->incrementViews();

        // ── Product-level attribute data ────────────────────────────────────
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

        // ── Product-level images ────────────────────────────────────────────
        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path)
            : null;

        $product->images->each(function ($img) {
            $img->url = Storage::url($img->image_path);
        });

        // ── Variants + axes + color image map ──────────────────────────────
        $hasVariants    = $product->variants->isNotEmpty();
        $variantsPayload = [];
        $selectableAxes  = [];
        // Map: color_option_id → [url, url, …]
        $colorImages     = [];
        // Map: color_option_id → [primary url]  (first image per color)
        $colorPrimaryImage = [];

        if ($hasVariants) {
            // Collect all product-level image URLs for fallback
            $productImageUrls = $product->images
                ->filter(fn($i) => is_null($i->variant_id) && is_null($i->color_option_id))
                ->map(fn($i) => Storage::url($i->image_path))
                ->values()
                ->toArray();

            // Build color_images map from product_images with color_option_id
            $product->images
                ->filter(fn($i) => $i->color_option_id !== null)
                ->each(function ($img) use (&$colorImages, &$colorPrimaryImage) {
                    $cid = $img->color_option_id;
                    $url = Storage::url($img->image_path);
                    $colorImages[$cid][] = $url;
                    if ($img->is_primary || !isset($colorPrimaryImage[$cid])) {
                        $colorPrimaryImage[$cid] = $url;
                    }
                });

            $variantsPayload = $product->variants->map(function ($v) use (
                $productImageUrls, &$colorImages, &$colorPrimaryImage
            ) {
                // Collect variant-specific image URLs
                $variantImageUrls = $v->images
                    ->map(fn($i) => Storage::url($i->image_path))
                    ->values()
                    ->toArray();

                // Also build the color_images map from variant images
                // (images linked via variant_id, grouped by color)
                $colorOptId = null;
                if ($v->relationLoaded('attributeOptions')) {
                    $colorOpt = $v->attributeOptions->first(
                        fn($o) => $o->attribute->slug === 'color'
                    );
                    if ($colorOpt) {
                        $colorOptId = $colorOpt->id;
                        foreach ($variantImageUrls as $url) {
                            if (!in_array($url, $colorImages[$colorOptId] ?? [])) {
                                $colorImages[$colorOptId][] = $url;
                            }
                        }
                        if ($variantImageUrls && !isset($colorPrimaryImage[$colorOptId])) {
                            $colorPrimaryImage[$colorOptId] = $variantImageUrls[0];
                        }
                    }
                }

                // Decide which images to show for this variant:
                // 1. Variant's own images
                // 2. Color group images
                // 3. Product-level images (fallback)
                $resolvedImages = $variantImageUrls;
                if (empty($resolvedImages) && $colorOptId && !empty($colorImages[$colorOptId])) {
                    $resolvedImages = $colorImages[$colorOptId];
                }
                if (empty($resolvedImages)) {
                    $resolvedImages = $productImageUrls;
                }

                return [
                    'id'              => $v->id,
                    'sku'             => $v->sku,
                    'stock'           => $v->stock,
                    'is_active'       => $v->is_active,
                    'price'           => $v->effective_price,
                    'price_override'  => $v->price_override,
                    'label'           => $v->label,
                    'option_map'      => $v->option_map,
                    'color_option_id' => $colorOptId,
                    // All images for this variant/color
                    'image_urls'      => $resolvedImages,
                    // Convenience: the first image
                    'primary_image_url' => $resolvedImages[0] ?? null,
                ];
            })->values();

            // Build selectable_axes
            $axisMap = [];
            foreach ($product->variants as $variant) {
                foreach ($variant->attributeOptions as $opt) {
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
                        // attach the primary image for this color option in the axis
                        'primary_image' => $colorPrimaryImage[$opt->id] ?? null,
                    ];
                }
            }
            foreach ($axisMap as &$axis) {
                $axis['options'] = array_values($axis['options']);
            }
            unset($axis);
            $selectableAxes = array_values($axisMap);
        }

        $data                    = $product->toArray();
        $data['has_variants']    = $hasVariants;
        $data['variants']        = $variantsPayload;
        $data['selectable_axes'] = $selectableAxes;
        $data['attribute_data']  = $product->attribute_data;
        // Color → images map for instant switching without variant match
        $data['color_images']    = $colorImages;

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/categories/{slug}/filter-attributes
     *
     * Returns filterable attributes for a category, sourcing attribute IDs from
     * BOTH product_attribute_values (non-variant attributes like Brand, Material)
     * AND variant_attribute_values (variant attributes like Color, Size).
     */
    public function filterAttributes($slug)
    {
        // Step 1: Collect all approved+active product IDs in this category
        $productIds = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('c.slug', $slug)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->pluck('p.id');

        if ($productIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Step 2: Attribute IDs from non-variant product_attribute_values
        $nonVariantAttrIds = DB::table('product_attribute_values')
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('attribute_id');

        // Step 3: Attribute IDs from variant_attribute_values
        // Path: product_variants → variant_attribute_values → attribute_options → attribute_id
        $variantAttrIds = DB::table('product_variants as pv')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->whereIn('pv.product_id', $productIds)
            ->distinct()
            ->pluck('ao.attribute_id');

        // Step 4: Union both sets — unique attribute IDs from both systems
        $allAttrIds = $nonVariantAttrIds->merge($variantAttrIds)->unique()->values();

        if ($allAttrIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Step 5: Load filterable attributes with their options
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