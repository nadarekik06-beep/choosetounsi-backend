<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use Illuminate\Http\Request;
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
            ->with(['category:id,name,slug', 'subcategory:id,name,slug', 'primaryImage', 'seller:id,name']);

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
                $p->primary_image_url = $p->primaryImage ? Storage::url($p->primaryImage->image_path) : null;
                return $p;
            });

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * GET /api/products/{slug}
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
            ])
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $product->incrementViews();

        // Build human-readable attribute map for the front-end
        $product->attribute_data = $product->attributeValues->map(function ($pav) {
            $attr  = $pav->attribute;
            $value = $attr->decodeValue($pav->value);

            // Resolve option labels for select/multiselect/color
            $label = $value;
            if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
                $ids    = (array) $value;
                $label  = $attr->options
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

        // Attach image URLs
        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path)
            : null;

        $product->images->each(function ($img) {
            $img->url = Storage::url($img->image_path);
        });

        return response()->json(['success' => true, 'data' => $product]);
    }

    /**
     * GET /api/categories/{slug}/filter-attributes
     *
     * Returns filterable attributes that exist among the products of a category.
     * Used to build the sidebar filter panel dynamically.
     */
    public function filterAttributes($slug)
    {
        // Get attribute IDs that actually appear in this category's products
        $attrIds = \DB::table('product_attribute_values as pav')
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