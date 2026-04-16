<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUpdateRequest;
use App\Models\User;
use App\Notifications\ProductUpdateRequestNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * ProductUpdateRequestController  (Seller side)
 *
 * UPDATED: The `store` method now accepts full variant CRUD data inside
 * `proposed_data.variants`.  Each variant row can contain:
 *
 *   Existing variant (has `id`):
 *     - stock, price_override, sku, is_active   ← all editable
 *     - option_ids                               ← structural change (new combo)
 *     - _delete: true                            ← mark for deletion
 *
 *   New variant (no `id`):
 *     - option_ids (required), stock, price_override, sku, is_active
 *
 * The admin's approve() method (in Admin\ProductUpdateRequestController)
 * already handles the full variant apply logic via applyVariants() — no
 * changes needed there.
 *
 * Business rules enforced here:
 *   - Stock-only changes on approved products → still goes through approval
 *     (use the dedicated POST /restock endpoint for that instead)
 *   - Structural variant changes (new variant, delete, option change) → approval
 *   - One pending request per product at a time
 */
class ProductUpdateRequestController extends Controller
{
    /**
     * List the current seller's requests.
     * GET /api/seller/products/{id}/update-requests
     */
    public function index(Request $request, int $productId)
    {
        $seller  = $request->user();
        $product = $seller->products()->findOrFail($productId);

        $requests = ProductUpdateRequest::where('product_id', $product->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * Submit a new update request.
     * POST /api/seller/products/{id}/request-update
     *
     * Accepts full variant CRUD in addition to scalar fields.
     */
    public function store(Request $request, int $productId)
    {
        $seller  = $request->user();
        $product = $seller->products()->findOrFail($productId);

        // Only locked (approved) products need the request flow
        if (!$product->is_approved) {
            return response()->json([
                'success' => false,
                'message' => 'This product is not yet approved. You can edit it directly.',
            ], 422);
        }

        // Prevent duplicate pending requests
        $existing = ProductUpdateRequest::where('product_id', $product->id)
            ->where('seller_id', $seller->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending update request for this product.',
                'data'    => $existing,
            ], 422);
        }

        // ── Validation ────────────────────────────────────────────────────
        $validated = $request->validate([
            // Scalar fields
            'price'          => 'sometimes|numeric|min:0',
            'stock'          => 'sometimes|integer|min:0',
            'category_id'    => 'sometimes|exists:categories,id',
            'subcategory_id' => 'sometimes|nullable|exists:subcategories,id',

            // Full variant CRUD
            'variants'                      => 'sometimes|array',
            'variants.*.id'                 => 'sometimes|nullable|integer',
            'variants.*.option_ids'         => 'sometimes|array',
            'variants.*.option_ids.*'       => 'integer|min:1',
            'variants.*.stock'              => 'sometimes|integer|min:0',
            'variants.*.price_override'     => 'sometimes|nullable|numeric|min:0',
            'variants.*.sku'                => 'sometimes|nullable|string|max:100',
            'variants.*.is_active'          => 'sometimes|boolean',
            'variants.*._delete'            => 'sometimes|boolean',

            'note' => 'sometimes|nullable|string|max:1000',
        ]);

        // ── Build proposed_data ───────────────────────────────────────────
        $proposedData = [];

        // Scalar fields
        foreach (['price', 'stock', 'category_id', 'subcategory_id'] as $field) {
            if ($request->has($field)) {
                $proposedData[$field] = $validated[$field] ?? $request->input($field);
            }
        }

        // Full variant CRUD
        if ($request->has('variants') && is_array($validated['variants'] ?? null)) {
            $proposedData['variants'] = $this->sanitizeVariants($validated['variants']);
        }

        if (empty($proposedData)) {
            return response()->json([
                'success' => false,
                'message' => 'No changes were submitted.',
            ], 422);
        }

        // Attach seller note
        if (!empty($validated['note'])) {
            $proposedData['_note'] = $validated['note'];
        }

        $updateRequest = ProductUpdateRequest::create([
            'product_id'    => $product->id,
            'seller_id'     => $seller->id,
            'proposed_data' => $proposedData,
            'status'        => 'pending',
        ]);

        $this->notifyAdmins($updateRequest, $product, $seller);

        return response()->json([
            'success' => true,
            'message' => 'Your update request has been submitted and is pending admin review.',
            'data'    => $updateRequest,
        ], 201);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Sanitize and normalize variant rows before storing in proposed_data.
     * Ensures consistent types and strips unknown keys.
     */
    private function sanitizeVariants(array $variants): array
    {
        $sanitized = [];

        foreach ($variants as $row) {
            if (!is_array($row)) continue;

            $clean = [];

            // Existing variant ID
            if (isset($row['id']) && $row['id']) {
                $clean['id'] = (int) $row['id'];
            }

            // Deletion flag
            if (!empty($row['_delete'])) {
                $clean['_delete'] = true;
                if (isset($clean['id'])) $sanitized[] = $clean;
                continue;
            }

            // Option IDs (required for new variants, optional for existing)
            if (isset($row['option_ids']) && is_array($row['option_ids'])) {
                $clean['option_ids'] = array_values(array_filter(
                    array_map('intval', $row['option_ids']),
                    fn($id) => $id > 0
                ));
            }

            // Stock
            if (array_key_exists('stock', $row)) {
                $clean['stock'] = (int) $row['stock'];
            }

            // Price override
            if (array_key_exists('price_override', $row)) {
                $clean['price_override'] = ($row['price_override'] !== '' && $row['price_override'] !== null)
                    ? (float) $row['price_override']
                    : null;
            }

            // SKU
            if (array_key_exists('sku', $row)) {
                $clean['sku'] = $row['sku'] ?: null;
            }

            // Active state
            if (array_key_exists('is_active', $row)) {
                $clean['is_active'] = (bool) $row['is_active'];
            }

            // Only add rows that have meaningful content
            if (isset($clean['id']) || (isset($clean['option_ids']) && !empty($clean['option_ids']))) {
                $sanitized[] = $clean;
            }
        }

        return $sanitized;
    }

    private function notifyAdmins(ProductUpdateRequest $updateRequest, Product $product, User $seller): void
    {
        try {
            $admins = User::where('role', 'admin')->where('is_active', true)->get();
            if ($admins->isEmpty()) return;

            Notification::send($admins, new ProductUpdateRequestNotification(
                'submitted',
                $updateRequest->id,
                $product->id,
                $product->name,
                $seller->name,
            ));
        } catch (\Throwable $e) {
            Log::error('[ProductUpdateRequest] Admin notification failed: ' . $e->getMessage());
        }
    }
}