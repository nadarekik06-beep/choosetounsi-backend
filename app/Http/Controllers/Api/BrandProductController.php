<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Api\BrandProductController  (public, no auth)
 *
 * Serves CHOOSE'Tounsi brand products to the /brand frontend page.
 * Uses the real products table filtered by is_platform_product = true.
 *
 * Routes (PUBLIC):
 *   GET /api/brand-products          → paginated list
 *   GET /api/brand-products/featured → up to 12 featured
 *   GET /api/brand-products/{slug}   → product detail with full variant/image payload
 */
class BrandProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::availableBrand()
            ->with(['category:id,name,slug', 'primaryImage']);

        if ($s = $request->query('search')) {
            $query->where(fn($q) =>
                $q->where('name', 'like', "%{$s}%")
                 ->orWhere('short_description', 'like', "%{$s}%")
            );
        }
        if ($cid = $request->query('category_id')) {
            $query->where('category_id', $cid);
        }
        if (filter_var($request->query('featured'), FILTER_VALIDATE_BOOLEAN)) {
            $query->featured();
        }
        if (filter_var($request->query('in_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->inStock();
        }

        $sort = $request->query('sort', 'created_at');
        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'views'      => $query->orderByDesc('views'),
            default      => $query->orderByDesc('created_at'),
        };

        $products = $query->paginate(min((int) $request->query('per_page', 20), 60));
        $products->getCollection()->transform(fn($p) => $this->transformListItem($p));

        return response()->json(['success' => true, 'data' => $products]);
    }

    public function featured()
    {
        $products = Product::availableBrand()->featured()->inStock()
            ->with(['category:id,name,slug', 'primaryImage'])
            ->orderByDesc('created_at')
            ->take(12)->get()
            ->map(fn($p) => $this->transformListItem($p));

        return response()->json(['success' => true, 'data' => $products]);
    }

    public function show($slug)
    {
        $product = Product::availableBrand()
            ->where('slug', $slug)
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'images',
                'primaryImage',
                'attributeValues.attribute.options',
                'variants' => fn($q) => $q->where('is_active', true)
                    ->with(['attributeOptions.attribute:id,slug,name,type', 'images']),
            ])
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $product->incrementViews();

        $data                    = $this->transformListItem($product);
        $data['description']     = $product->description;
        $data['sku']             = $product->sku;
        $data['images']          = $product->images->map(fn($img) => [
            'id'         => $img->id,
            'url'        => Storage::url($img->image_path),
            'is_primary' => $img->is_primary,
            'order'      => $img->order,
            'variant_id' => $img->variant_id,
        ])->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function transformListItem(Product $p): array
    {
        return [
            'id'                => $p->id,
            'name'              => $p->name,
            'slug'              => $p->slug,
            'short_description' => $p->short_description,
            'price'             => (float) $p->price,
            'stock'             => $p->stock,
            'featured'          => $p->featured,
            'views'             => $p->views,
            'category'          => $p->category
                ? ['id' => $p->category->id, 'name' => $p->category->name, 'slug' => $p->category->slug]
                : null,
            'primary_image_url' => $p->primary_image_url,
        ];
    }
}