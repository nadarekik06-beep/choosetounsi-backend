<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SellerProductController extends Controller
{
    private function sellerId(): int
    {
        return (int) auth()->id();
    }

    // ── GET /api/seller/products ──────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = Product::withoutGlobalScopes()
            ->with(['category:id,name', 'primaryImage'])
            ->where('seller_id', $this->sellerId())
            ->select([
                'id', 'seller_id', 'category_id', 'name', 'slug', 'sku',
                'description', 'short_description', 'price', 'stock',
                'is_approved', 'is_active', 'featured', 'views', 'created_at',
            ]);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('sku', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_approved')) {
            $query->where('is_approved', filter_var($request->is_approved, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        $perPage = (int) $request->get('per_page', 12);
        $products = $query->orderByDesc('created_at')->paginate($perPage);

        // Append primary_image_url to each product
        $products->getCollection()->transform(function ($product) {
            $product->primary_image_url = $product->primaryImage
                ? Storage::url($product->primaryImage->image_path)
                : null;
            return $product;
        });

        return response()->json([
            'success' => true,
            'data'    => $products,
        ]);
    }

    // ── GET /api/seller/products/stats ────────────────────────────────────────
    public function stats()
    {
        $stats = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active   = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active   = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_approval,
                SUM(stock) as total_stock,
                SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as out_of_stock
            ')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    // ── GET /api/seller/products/{id} ─────────────────────────────────────────
    public function show(int $id)
    {
        $product = Product::withoutGlobalScopes()
            ->with(['category:id,name', 'images'])
            ->where('seller_id', $this->sellerId())
            ->findOrFail($id);

        // Append image URLs
        $product->images->each(function ($image) {
            $image->url = Storage::url($image->image_path);
        });

        return response()->json([
            'success' => true,
            'data'    => $product,
        ]);
    }

    // ── POST /api/seller/products ─────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id'       => 'required|integer|exists:categories,id',
            'name'              => 'required|string|max:255',
            'slug'              => 'nullable|string|max:255|unique:products,slug',
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price'             => 'required|numeric|min:0',
            'stock'             => 'required|integer|min:0',
            'sku'               => 'nullable|string|max:100|unique:products,sku',
            'is_active'         => 'sometimes|boolean',
            'images'            => 'nullable|array|max:8',
            'images.*'          => 'image|mimes:jpeg,png,jpg,webp|max:5120', // 5MB per image
        ]);

        // Auto-generate slug
        $slug = $this->generateUniqueSlug($validated['slug'] ?? $validated['name']);

        // Auto-generate SKU if not provided
        $sku = !empty($validated['sku'])
            ? $validated['sku']
            : $this->generateSku($validated['name']);

        DB::beginTransaction();
        try {
            $product = Product::create([
                'seller_id'         => $this->sellerId(),
                'category_id'       => $validated['category_id'],
                'name'              => $validated['name'],
                'slug'              => $slug,
                'description'       => $validated['description'] ?? null,
                'short_description' => $validated['short_description'] ?? null,
                'price'             => $validated['price'],
                'stock'             => $validated['stock'],
                'sku'               => $sku,
                'is_active'         => $validated['is_active'] ?? true,
                'is_approved'       => false, // Always pending — admin must approve
                'featured'          => false,
            ]);

            // Handle image uploads
            if ($request->hasFile('images')) {
                $this->uploadImages($product, $request->file('images'));
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product. Please try again.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product submitted successfully. It will be visible after admin approval.',
            'data'    => $product->load(['category:id,name', 'images']),
        ], 201);
    }

    // ── PUT /api/seller/products/{id} ─────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $product = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->findOrFail($id);

        $validated = $request->validate([
            'category_id'       => 'sometimes|integer|exists:categories,id',
            'name'              => 'sometimes|string|max:255',
            'slug'              => 'nullable|string|max:255|unique:products,slug,' . $id,
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price'             => 'sometimes|numeric|min:0',
            'stock'             => 'sometimes|integer|min:0',
            'sku'               => 'nullable|string|max:100|unique:products,sku,' . $id,
            'is_active'         => 'sometimes|boolean',
            'images'            => 'nullable|array|max:8',
            'images.*'          => 'image|mimes:jpeg,png,jpg,webp|max:5120',
            'delete_image_ids'  => 'nullable|array',
            'delete_image_ids.*'=> 'integer|exists:product_images,id',
        ]);

        // Re-generate slug only if name changed and slug not explicitly provided
        if (isset($validated['name']) && !isset($validated['slug'])) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $id);
        }

        DB::beginTransaction();
        try {
            $product->update($validated);

            // Delete specific images if requested
            if (!empty($validated['delete_image_ids'])) {
                $this->deleteImages($product, $validated['delete_image_ids']);
            }

            // Upload new images
            if ($request->hasFile('images')) {
                $this->uploadImages($product, $request->file('images'));
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        $product->load(['category:id,name', 'images']);
        $product->images->each(function ($image) {
            $image->url = Storage::url($image->image_path);
        });

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data'    => $product,
        ]);
    }

    // ── DELETE /api/seller/products/{id} ──────────────────────────────────────
    public function destroy(int $id)
    {
        $product = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->findOrFail($id);

        // Delete image files from storage
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }

    // ── DELETE /api/seller/products/{id}/images/{imageId} ────────────────────
    public function destroyImage(int $id, int $imageId)
    {
        $product = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->findOrFail($id);

        $image = $product->images()->findOrFail($imageId);

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        // If we deleted the primary image, promote the next one
        if ($image->is_primary) {
            $next = $product->images()->orderBy('order')->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Image deleted.',
        ]);
    }

    // ── PATCH /api/seller/products/{id}/images/{imageId}/primary ─────────────
    public function setPrimaryImage(int $id, int $imageId)
    {
        $product = Product::withoutGlobalScopes()
            ->where('seller_id', $this->sellerId())
            ->findOrFail($id);

        $image = $product->images()->findOrFail($imageId);

        // Remove primary from all images of this product
        $product->images()->update(['is_primary' => false]);
        $image->update(['is_primary' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Primary image updated.',
        ]);
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Upload images and store in product_images table.
     */
    private function uploadImages(Product $product, array $files): void
    {
        $currentMax = $product->images()->max('order') ?? -1;
        $hasPrimary = $product->images()->where('is_primary', true)->exists();

        foreach ($files as $index => $file) {
            $order = $currentMax + $index + 1;
            $isPrimary = !$hasPrimary && $index === 0;

            // Store in storage/app/public/products/{seller_id}/
            $path = $file->store(
                'products/' . $product->seller_id,
                'public'
            );

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $path,
                'order'      => $order,
                'is_primary' => $isPrimary,
            ]);

            if ($isPrimary) {
                $hasPrimary = true;
            }
        }
    }

    /**
     * Delete specific images by ID (only if they belong to this product).
     */
    private function deleteImages(Product $product, array $imageIds): void
    {
        $images = $product->images()->whereIn('id', $imageIds)->get();
        $hadPrimary = false;

        foreach ($images as $image) {
            if ($image->is_primary) {
                $hadPrimary = true;
            }
            Storage::disk('public')->delete($image->image_path);
            $image->delete();
        }

        // Promote next image to primary if we deleted the primary
        if ($hadPrimary) {
            $next = $product->images()->orderBy('order')->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }
    }

    /**
     * Generate a unique slug, appending a counter if needed.
     */
    private function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 1;

        while (true) {
            $query = Product::withoutGlobalScopes()->where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
            if (!$query->exists()) {
                break;
            }
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }

    /**
     * Generate a unique SKU: CT-{CATEGORY}{TIMESTAMP}{RANDOM}
     */
    private function generateSku(string $productName): string
    {
        do {
            $prefix = 'CT-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $productName), 0, 3));
            $sku = $prefix . strtoupper(Str::random(6));
        } while (Product::withoutGlobalScopes()->where('sku', $sku)->exists());

        return $sku;
    }
}