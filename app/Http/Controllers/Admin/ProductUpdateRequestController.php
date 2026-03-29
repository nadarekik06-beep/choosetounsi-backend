<?php
// app/Http/Controllers/Admin/ProductUpdateRequestController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttributeOption;
use App\Models\Product;
use App\Models\ProductUpdateRequest;
use App\Models\ProductVariant;
use App\Notifications\ProductUpdateRequestNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductUpdateRequestController extends Controller
{
    /**
     * GET /api/admin/product-update-requests
     */
    public function index(Request $request)
    {
        $query = ProductUpdateRequest::with([
            'product:id,name,slug,price,stock,category_id,is_approved,is_active',
            'product.category:id,name',
            'product.primaryImage',
            'seller:id,name,email',
        ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'pending');
        }

        if ($search = $request->query('search')) {
            $query->whereHas('product', fn($q) => $q->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('seller', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $requests = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        // Attach primary image URL + old values snapshot
        $requests->getCollection()->transform(function ($r) {
            if ($r->product && $r->product->primaryImage) {
                $r->product->primary_image_url = \Storage::url($r->product->primaryImage->image_path);
            } else if ($r->product) {
                $r->product->primary_image_url = null;
            }
            return $r;
        });

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * GET /api/admin/product-update-requests/{id}
     */
    public function show(int $id)
    {
        $updateRequest = ProductUpdateRequest::with([
            'product.category:id,name',
            'product.subcategory:id,name',
            'product.images',
            'product.variants.attributeOptions.attribute',
            'seller:id,name,email',
        ])->findOrFail($id);

        // Build "current values" snapshot for comparison in admin UI
        $product = $updateRequest->product;
        $updateRequest->current_data = [
            'price'          => $product->price,
            'stock'          => $product->stock,
            'category_id'    => $product->category_id,
            'category_name'  => $product->category?->name,
            'subcategory_id' => $product->subcategory_id,
            'subcategory_name' => $product->subcategory?->name,
            'variants'       => $product->variants->map(fn($v) => [
                'id'             => $v->id,
                'label'          => $v->label,
                'stock'          => $v->stock,
                'price_override' => $v->price_override,
                'sku'            => $v->sku,
                'is_active'      => $v->is_active,
                'option_ids'     => $v->attributeOptions->pluck('id')->toArray(),
            ])->toArray(),
        ];

        if ($product->primaryImage) {
            $updateRequest->product->primary_image_url = \Storage::url($product->primaryImage->image_path);
        } else {
            $updateRequest->product->primary_image_url = null;
        }

        return response()->json(['success' => true, 'data' => $updateRequest]);
    }

    /**
     * POST /api/admin/product-update-requests/{id}/approve
     * Applies proposed_data onto the product.
     */
    public function approve(int $id)
    {
        $updateRequest = ProductUpdateRequest::with(['product', 'seller'])->findOrFail($id);

        if (!$updateRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been ' . $updateRequest->status . '.',
            ], 422);
        }

        DB::transaction(function () use ($updateRequest) {
            $product      = $updateRequest->product;
            $proposedData = $updateRequest->proposed_data;

            // Apply scalar fields (only those present in proposed_data)
            $scalarFields = ['price', 'stock', 'category_id', 'subcategory_id'];
            $toUpdate = [];
            foreach ($scalarFields as $field) {
                if (array_key_exists($field, $proposedData)) {
                    $toUpdate[$field] = $proposedData[$field];
                }
            }
            if (!empty($toUpdate)) {
                $product->update($toUpdate);
            }

            // Apply variants if present
            if (array_key_exists('variants', $proposedData) && is_array($proposedData['variants'])) {
                $this->applyVariants($product, $proposedData['variants']);
            }

            // Mark request approved
            $updateRequest->update(['status' => 'approved']);
        });

        // Notify seller
        $this->notifySeller($updateRequest, 'approved');

        return response()->json([
            'success' => true,
            'message' => 'Update request approved and applied to the product.',
        ]);
    }

    /**
     * POST /api/admin/product-update-requests/{id}/reject
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'admin_comment' => 'nullable|string|max:1000',
        ]);

        $updateRequest = ProductUpdateRequest::with(['product', 'seller'])->findOrFail($id);

        if (!$updateRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been ' . $updateRequest->status . '.',
            ], 422);
        }

        $updateRequest->update([
            'status'        => 'rejected',
            'admin_comment' => $request->admin_comment,
        ]);

        // Notify seller
        $this->notifySeller($updateRequest, 'rejected', $request->admin_comment);

        return response()->json([
            'success' => true,
            'message' => 'Update request rejected.',
        ]);
    }

    /**
     * GET /api/admin/product-update-requests/stats
     */
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'pending'  => ProductUpdateRequest::pending()->count(),
                'approved' => ProductUpdateRequest::approved()->count(),
                'rejected' => ProductUpdateRequest::rejected()->count(),
                'total'    => ProductUpdateRequest::count(),
            ],
        ]);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function applyVariants(Product $product, array $variantsData): void
    {
        $keepIds = [];

        foreach ($variantsData as $row) {
            if (!is_array($row)) continue;

            $optionIds = array_values(array_filter(
                array_map('intval', (array) ($row['option_ids'] ?? [])),
                fn($id) => $id > 0
            ));

            $existingId = isset($row['id']) && $row['id'] ? (int) $row['id'] : null;
            $variant    = $existingId
                ? ProductVariant::where('product_id', $product->id)->find($existingId)
                : null;

            if ($variant) {
                $fields = [];
                if (array_key_exists('stock', $row))          $fields['stock']          = (int) $row['stock'];
                if (array_key_exists('price_override', $row)) $fields['price_override'] = $row['price_override'] !== '' && $row['price_override'] !== null ? (float) $row['price_override'] : null;
                if (array_key_exists('sku', $row))            $fields['sku']            = $row['sku'] ?: null;
                if (array_key_exists('is_active', $row))      $fields['is_active']      = (bool) $row['is_active'];
                if (!empty($fields)) $variant->update($fields);
            } else {
                $variant = ProductVariant::create([
                    'product_id'     => $product->id,
                    'stock'          => (int) ($row['stock'] ?? 0),
                    'price_override' => isset($row['price_override']) && $row['price_override'] !== '' ? (float) $row['price_override'] : null,
                    'sku'            => $row['sku'] ?? null,
                    'is_active'      => (bool) ($row['is_active'] ?? true),
                ]);
            }

            if (!empty($optionIds)) {
                $variant->attributeOptions()->sync($optionIds);
            }
            $keepIds[] = $variant->id;
        }

        if (!empty($keepIds)) {
            $product->variants()->whereNotIn('id', $keepIds)->delete();
        }
    }

    private function notifySeller(ProductUpdateRequest $updateRequest, string $action, ?string $adminComment = null): void
    {
        try {
            $seller = $updateRequest->seller;
            if (!$seller) return;

            $seller->notify(new ProductUpdateRequestNotification(
                $action,
                $updateRequest->id,
                $updateRequest->product_id,
                $updateRequest->product?->name ?? 'Product',
                'Admin',
                $adminComment,
            ));
        } catch (\Throwable $e) {
            Log::error('[ProductUpdateRequest] Seller notification failed: ' . $e->getMessage());
        }
    }
}