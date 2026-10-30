<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminProductEditor;
use App\Services\PlanGate;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    // ── Add product on behalf of a seller ────────────────────────────────────

    /**
     * GET /api/admin/products/seller-options?search=
     * Seller picker of the "Add product" page: approved, active sellers only,
     * searchable by name, shop name, e-mail and phone, with plan and product count.
     */
    public function sellerOptions(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $gate   = app(PlanGate::class);

        $sellers = User::sellers()
            ->where('is_active', true)->where('is_approved', true)
            ->with('sellerApplication:id,user_id,business_name,phone_number,status')
            ->withCount('products')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where(fn($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhereHas('sellerApplication', fn($a) => $a->where('business_name', 'like', $like)->orWhere('phone_number', 'like', $like)));
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json(['success' => true, 'data' => $sellers->map(function (User $s) use ($gate) {
            $plan = $gate->planFor($s->id);
            return [
                'id'            => $s->id,
                'name'          => $s->name,
                'email'         => $s->email,
                'phone'         => optional($s->sellerApplication)->phone_number ?: $s->phone,
                'shop_name'     => optional($s->sellerApplication)->business_name,
                'plan'          => ['slug' => $plan->slug, 'name' => $plan->name],
                'product_count' => (int) $s->products_count,
                'product_limit' => $plan->max_products,
            ];
        })->values()]);
    }

    /** GET /api/admin/products/blank-editor?seller_id= → empty editor document */
    public function blank(Request $request)
    {
        $seller = $this->sellerFor((int) $request->query('seller_id'));

        return response()->json(['success' => true, 'data' => $this->editor->blank($seller)]);
    }

    /**
     * POST /api/admin/products  (multipart: seller_id, publish, data = editor JSON, uploads[…])
     * Creates the product in the seller's shop; published or saved as a draft.
     */
    public function create(Request $request)
    {
        $request->validate(['seller_id' => 'required|integer', 'publish' => 'sometimes|boolean']);
        $seller = $this->sellerFor((int) $request->input('seller_id'));

        $data = json_decode((string) $request->input('data'), true);
        if (!is_array($data)) {
            return response()->json(['success' => false, 'message' => 'Malformed editor payload.'], 422);
        }

        $publish = $request->boolean('publish');
        $product = $this->editor->create($seller, $data, array_filter((array) $request->file('uploads', [])), $request->user(), $publish);

        return response()->json([
            'success' => true,
            'message' => $publish ? 'Product created and published in the seller\'s shop.' : 'Product saved as a draft in the seller\'s shop.',
            'data'    => ['id' => $product->id, 'editor' => $this->editor->payload($product)],
        ], 201);
    }

    /**
     * POST /api/admin/products/{id}/split
     * Body: { groups: [{ name, stock, price?, image_ids: [] }], move_images: bool, publish: bool }
     */
    public function split(Request $request, $id)
    {
        $source  = Product::findOrFail($id);
        $created = $this->editor->split(
            $source,
            (array) $request->input('groups', []),
            $request->boolean('move_images', true),
            $request->boolean('publish'),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => count($created) . ' product(s) created for the same seller.',
            'data'    => array_map(fn($p) => ['id' => $p->id, 'name' => $p->getRawOriginal('name')], $created),
        ], 201);
    }

    private function sellerFor(int $id): User
    {
        $seller = User::sellers()->with('sellerApplication')->find($id);
        if (!$seller || !$seller->is_active || !$seller->is_approved) {
            throw ValidationException::withMessages(['seller_id' => ['Choose an approved, active seller.']]);
        }
        return $seller;
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

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
