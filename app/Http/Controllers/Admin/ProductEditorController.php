<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AdminProductEditor;
use Illuminate\Http\Request;

/**
 * Full-page admin product editor (admin panel: /products/{id}/edit).
 *
 *   GET  /api/admin/products/{id}/editor  → the whole product as one editable document
 *   POST /api/admin/products/{id}/editor  → saves it back atomically (multipart)
 *
 * The POST body is `data` (JSON string, see AdminProductEditor::validate) plus
 * `uploads[<key>]` files referenced from the image manifest.
 */
class ProductEditorController extends Controller
{
    public function __construct(private AdminProductEditor $editor) {}

    public function show($id)
    {
        $product = Product::findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->editor->payload($product)]);
    }

    public function save(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $data = json_decode((string) $request->input('data'), true);
        if (!is_array($data)) {
            return response()->json(['success' => false, 'message' => 'Malformed editor payload.'], 422);
        }

        // Someone (usually the seller) saved the product after the editor loaded it
        $loadedAt = $data['loaded_updated_at'] ?? null;
        if ($loadedAt && empty($data['force'])
            && optional($product->updated_at)->toISOString() !== $loadedAt) {
            return response()->json([
                'success'  => false,
                'conflict' => true,
                'message'  => 'This product was changed by someone else after you opened it. Reload to see the latest version, or overwrite it with your changes.',
            ], 409);
        }

        $uploads = array_filter((array) $request->file('uploads', []));
        $log     = $this->editor->save($product, $data, $uploads, $request->user());

        $approved = false;
        if (!empty($data['approve'])) {
            $this->editor->approve($product->fresh(), $request->user());
            $approved = true;
        }

        if ($product->seller_id && method_exists(\App\Http\Controllers\Api\Seller\BlackPepperController::class, 'clearSellerCache')) {
            \App\Http\Controllers\Api\Seller\BlackPepperController::clearSellerCache($product->seller_id);
        }

        $message = match (true) {
            $approved && $log => 'Changes saved and product approved.',
            $approved         => 'Product approved.',
            (bool) $log       => 'Changes saved.',
            default           => 'No changes to save.',
        };

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $this->editor->payload($product->fresh()),
        ]);
    }
}
