<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Admin Subcategory CRUD
 * Routes prefix: /api/admin/subcategories
 */
class AdminSubcategoryController extends Controller
{
    // ── List ───────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = Subcategory::with('category:id,name,slug')
            ->orderBy('category_id')
            ->orderBy('order')
            ->orderBy('name');

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $subcategories = $query->withCount('products')->get();

        return response()->json(['success' => true, 'data' => $subcategories]);
    }

    // ── Show ───────────────────────────────────────────────────────────────

    public function show($id)
    {
        $sub = Subcategory::with('category:id,name,slug')
            ->withCount('products')
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $sub]);
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:255',
            'name_ar'     => 'nullable|string|max:255',
            'icon'        => 'nullable|string|max:100',
            'order'       => 'sometimes|integer|min:0',
        ]);

        $sub = Subcategory::create([
            'category_id' => (int) $request->input('category_id'),
            'name'        => $request->input('name'),
            'name_ar'     => $request->input('name_ar') ?: null,
            'slug'        => $this->uniqueSlug(
                                Str::slug($request->input('name')),
                                (int) $request->input('category_id')
                             ),
            'icon'        => $request->input('icon') ?: null,
            'is_active'   => true,   // ← always true on creation, use toggle to deactivate
            'order'       => (int) $request->input('order', 0),
        ]);

        $sub->load('category:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => 'Subcategory created.',
            'data'    => $sub,
        ], 201);
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $sub = Subcategory::findOrFail($id);

        $request->validate([
            'category_id' => 'sometimes|exists:categories,id',
            'name'        => 'sometimes|string|max:255',
            'name_ar'     => 'nullable|string|max:255',
            'icon'        => 'nullable|string|max:100',
            'is_active'   => 'sometimes',   // accept any truthy/falsy value
            'order'       => 'sometimes|integer|min:0',
        ]);

        $data = [];

        if ($request->has('name')) {
            $data['name'] = $request->input('name');
            if ($request->input('name') !== $sub->name) {
                $catId = $request->input('category_id', $sub->category_id);
                $data['slug'] = $this->uniqueSlug(
                    Str::slug($request->input('name')),
                    (int) $catId,
                    $sub->id
                );
            }
        }

        if ($request->has('category_id'))  $data['category_id'] = (int) $request->input('category_id');
        if ($request->has('name_ar'))       $data['name_ar']     = $request->input('name_ar') ?: null;
        if ($request->has('icon'))          $data['icon']        = $request->input('icon') ?: null;
        if ($request->has('order'))         $data['order']       = (int) $request->input('order', 0);

        // Handle is_active explicitly — convert any truthy value to proper boolean
        if ($request->has('is_active')) {
            $val = $request->input('is_active');
            $data['is_active'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        }

        $sub->update($data);
        $sub->load('category:id,name,slug');

        return response()->json([
            'success' => true,
            'message' => 'Subcategory updated.',
            'data'    => $sub,
        ]);
    }

    // ── Delete ─────────────────────────────────────────────────────────────

    public function destroy($id)
    {
        $sub = Subcategory::withCount('products')->findOrFail($id);

        if ($sub->products_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: {$sub->products_count} product(s) are linked to this subcategory.",
            ], 422);
        }

        $sub->delete();

        return response()->json(['success' => true, 'message' => 'Subcategory deleted.']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function uniqueSlug(string $base, int $categoryId, ?int $excludeId = null): string
    {
        $slug     = $base ?: 'subcategory';
        $original = $slug;
        $counter  = 2;

        while (true) {
            $query = DB::table('subcategories')
                ->where('slug', $slug)
                ->where('category_id', $categoryId);

            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }

            if (!$query->exists()) break;

            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}