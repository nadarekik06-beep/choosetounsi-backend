<?php
// app/Http/Controllers/Api/Seller/ProductController.php
// Add the 3 notification calls to your existing file.
// Search for "// ◄ NOTIFICATION" — those are the only additions.

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Product;
use App\Notifications\ProductActionNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ProductController extends Controller
{
    /**
     * GET /api/seller/products
     */
    public function index(Request $request)
    {
        $seller = $request->user();
        $query  = Product::where('seller_id', $seller->id)
            ->with(['category', 'images'])
            ->withCount('orderItems');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('sku',  'like', '%' . $search . '%');
            });
        }

        if (!is_null($request->query('is_active'))) {
            $query->where('is_active', (bool) $request->query('is_active'));
        }

        if (!is_null($request->query('is_approved'))) {
            $query->where('is_approved', (bool) $request->query('is_approved'));
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->orderByDesc('created_at')->paginate($request->query('per_page', 15)),
        ]);
    }

    /**
     * GET /api/seller/products/stats
     */
    public function stats(Request $request)
    {
        $sellerId = $request->user()->id;

        return response()->json([
            'success' => true,
            'data'    => [
                'total'        => Product::where('seller_id', $sellerId)->count(),
                'active'       => Product::where('seller_id', $sellerId)->where('is_active', true)->count(),
                'pending'      => Product::where('seller_id', $sellerId)->where('is_approved', false)->count(),
                'approved'     => Product::where('seller_id', $sellerId)->where('is_approved', true)->count(),
                'out_of_stock' => Product::where('seller_id', $sellerId)->where('stock', 0)->count(),
            ],
        ]);
    }

    /**
     * GET /api/seller/products/{id}
     */
    public function show(Request $request, $id)
    {
        $product = Product::where('seller_id', $request->user()->id)
            ->with(['category', 'images'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $product]);
    }

    /**
     * POST /api/seller/products
     */
    public function store(Request $request)
    {
        $seller = $request->user();

        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string|max:5000',
            'short_description' => 'nullable|string|max:500',
            'price'             => 'required|numeric|min:0',
            'stock'             => 'required|integer|min:0',
            'category_id'       => 'required|integer|exists:categories,id',
            'sku'               => 'nullable|string|max:100',
            'slug'              => 'nullable|string|max:255',
            'is_active'         => 'boolean',
            'images'            => 'nullable|array|max:10',
            'images.*'          => 'image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $product = Product::create([
            'seller_id'         => $seller->id,
            'category_id'       => $validated['category_id'],
            'name'              => $validated['name'],
            'description'       => isset($validated['description']) ? $validated['description'] : null,
            'short_description' => isset($validated['short_description']) ? $validated['short_description'] : null,
            'price'             => $validated['price'],
            'stock'             => $validated['stock'],
            'sku'               => isset($validated['sku']) ? $validated['sku'] : null,
            'slug'              => isset($validated['slug']) ? $validated['slug'] : \Str::slug($validated['name']) . '-' . uniqid(),
            'is_active'         => isset($validated['is_active']) ? $validated['is_active'] : true,
            'is_approved'       => false,
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                $path = $file->store('products', 'public');
                $product->images()->create([
                    'image_path' => $path,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        // ◄ NOTIFICATION — Notify all admins
        Notification::send(
            Admin::getAllActive(),
            new ProductActionNotification(
                'created',
                $product->id,
                $product->name,
                $seller->name,
                $seller->id
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Product created and pending approval.',
            'data'    => $product->load(['category', 'images']),
        ], 201);
    }

    /**
     * PUT /api/seller/products/{id}
     */
    public function update(Request $request, $id)
    {
        $seller  = $request->user();
        $product = Product::where('seller_id', $seller->id)->findOrFail($id);

        $validated = $request->validate([
            'name'               => 'sometimes|required|string|max:255',
            'description'        => 'nullable|string|max:5000',
            'short_description'  => 'nullable|string|max:500',
            'price'              => 'sometimes|required|numeric|min:0',
            'stock'              => 'sometimes|required|integer|min:0',
            'category_id'        => 'sometimes|required|integer|exists:categories,id',
            'sku'                => 'nullable|string|max:100',
            'is_active'          => 'boolean',
            'images'             => 'nullable|array|max:10',
            'images.*'           => 'image|mimes:jpg,jpeg,png,webp|max:4096',
            'delete_image_ids'   => 'nullable|array',
            'delete_image_ids.*' => 'integer',
        ]);

        $product->update($validated);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $path = $file->store('products', 'public');
                $product->images()->create(['image_path' => $path, 'is_primary' => false]);
            }
        }

        if (!empty($validated['delete_image_ids'])) {
            $product->images()->whereIn('id', $validated['delete_image_ids'])->delete();
        }

        // ◄ NOTIFICATION — Notify all admins
        Notification::send(
            Admin::getAllActive(),
            new ProductActionNotification(
                'updated',
                $product->id,
                $product->name,
                $seller->name,
                $seller->id
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Product updated.',
            'data'    => $product->fresh()->load(['category', 'images']),
        ]);
    }

    /**
     * DELETE /api/seller/products/{id}
     */
    public function destroy(Request $request, $id)
    {
        $seller      = $request->user();
        $product     = Product::where('seller_id', $seller->id)->findOrFail($id);
        $productId   = $product->id;
        $productName = $product->name;

        $product->delete();

        // ◄ NOTIFICATION — Notify all admins
        Notification::send(
            Admin::getAllActive(),
            new ProductActionNotification(
                'deleted',
                $productId,
                $productName,
                $seller->name,
                $seller->id
            )
        );

        return response()->json(['success' => true, 'message' => 'Product deleted.']);
    }
}