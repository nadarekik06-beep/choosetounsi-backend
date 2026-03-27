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
     * Supports:
     *   search, category_id, category_slug,
     *   subcategory_id, subcategory_slug,
     *   sort (price_asc | price_desc | views | created_at),
     *   in_stock (bool),
     *   price_min, price_max,
     *   attrs[size][]=3&attrs[color][]=1  ← attribute filters
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

        // ── Text search ────────────────────────────────────────────────────
        if ($search = $request->query('search')) {
            $query->where(fn($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%")
            );
        }

        // ── Category filter ────────────────────────────────────────────────
        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        } elseif ($catSlug = $request->query('category_slug')) {
            $query->whereHas('category', fn($q) => $q->where('slug', $catSlug));
        }

        // ── Subcategory filter ─────────────────────────────────────────────
        if ($subId = $request->query('subcategory_id')) {
            $query->where('subcategory_id', $subId);
        } elseif ($subSlug = $request->query('subcategory_slug')) {
            $query->whereHas('subcategory', fn($q) => $q->where('slug', $subSlug));
        }

        // ── Price range ────────────────────────────────────────────────────
        if ($priceMin = $request->query('price_min')) {
            $query->where('price', '>=', (float) $priceMin);
        }
        if ($priceMax = $request->query('price_max')) {
            $query->where('price', '<=', (float) $priceMax);
        }

        // ── Stock filter ───────────────────────────────────────────────────
        if (filter_var($request->query('in_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('stock', '>', 0);
        }

        // ── Dynamic attribute filters ──────────────────────────────────────
        // e.g. GET /api/products?attrs[color][]=1&attrs[size][]=3
        if ($attrs = $request->query('attrs')) {
            foreach ($attrs as $slug => $values) {
                $values = (array) $values;
                $query->hasAttribute($slug, $values);
            }
        }

        // ── Sorting ────────────────────────────────────────────────────────
        $sort = $request->query('sort', 'created_at');
        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'views'      => $query->orderByDesc('views'),
            default      => $query->orderByDesc('created_at'),
        };

        $perPage  = (int) $request->query('per_page', 20);
        $products = $query->paginate(min($perPage, 60));

        // Append image URL
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
            ->take(12)
            ->get()
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
     * Returns the full product including:
     *   - attribute_data  (product-level attributes, same as before)
     *   - has_variants    (bool)
     *   - variants        (array of variant objects when has_variants = true)
     *   - selectable_axes (axes + options to drive the UI selectors)
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
                // Load active variants with full option data
                'variants' => fn($q) => $q->where('is_active', true)
                    ->with(['attributeOptions.attribute:id,slug,name,type']),
            ])
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $product->incrementViews();

        // ── Product-level attribute data (unchanged from original) ──────────
        $product->attribute_data = $product->attributeValues->map(function ($pav) {
            $attr  = $pav->attribute;
            $value = $attr->decodeValue($pav->value);

            $label = $value;
            if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
                $ids   = (array) $value;
                $label = $attr->options
                    ->whereIn('id', $ids)
                    ->pluck('value')
                    ->join(', ');
            }

            return [
                'slug'  => $attr->slug,
                'name'  => $attr->name,
                'type'  => $attr->type,
                'value' => $value,
                'label' => $label,
            ];
        })->keyBy('slug');

        // ── Variants & selectable axes ──────────────────────────────────────
        $hasVariants    = $product->variants->isNotEmpty();
        $variantsPayload = [];
        $selectableAxes  = [];

        if ($hasVariants) {
            // Build the variant list the front-end will consume
            $variantsPayload = $product->variants->map(function ($v) {
                return [
                    'id'             => $v->id,
                    'sku'            => $v->sku,
                    'stock'          => $v->stock,
                    'is_active'      => $v->is_active,
                    'price'          => $v->effective_price,
                    'price_override' => $v->price_override,
                    'label'          => $v->label,
                    'option_map'     => $v->option_map,
                    // option_map example:
                    // { "color": {"id":3,"value":"Red","color_hex":"#FF0000"},
                    //   "size":  {"id":7,"value":"M",  "color_hex":null} }
                ];
            })->values();

            // Derive which axes exist across all variants
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
                    // Deduplicate options by id
                    $axisMap[$slug]['options'][$opt->id] = [
                        'id'        => $opt->id,
                        'value'     => $opt->value,
                        'color_hex' => $opt->color_hex,
                    ];
                }
            }

            // Re-index options as plain arrays
            foreach ($axisMap as &$axis) {
                $axis['options'] = array_values($axis['options']);
            }
            unset($axis);

            $selectableAxes = array_values($axisMap);
        }

        // ── Image URLs ──────────────────────────────────────────────────────
        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path)
            : null;

        $product->images->each(function ($img) {
            $img->url = Storage::url($img->image_path);
        });

        // Merge extra fields into the response
        $data                    = $product->toArray();
        $data['has_variants']    = $hasVariants;
        $data['variants']        = $variantsPayload;
        $data['selectable_axes'] = $selectableAxes;
        $data['attribute_data']  = $product->attribute_data;

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/categories/{slug}/filter-attributes
     *
     * Returns filterable attributes that exist among the products of a category.
     */
    public function filterAttributes($slug)
    {
        $attrIds = DB::table('product_attribute_values as pav')
            ->join('products as p', 'p.id', '=', 'pav.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('c.slug', $slug)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->distinct()
            ->pluck('pav.attribute_id');

        $attributes = Attribute::whereIn('id', $attrIds)
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