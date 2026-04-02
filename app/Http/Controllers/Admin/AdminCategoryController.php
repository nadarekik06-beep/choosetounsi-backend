<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AdminCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::withCount(['products', 'subcategories'])
            ->orderBy('order')
            ->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($request->query('with_subcategories')) {
            $query->with('subcategories:id,category_id,name,slug,is_active,order');
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function show($id)
    {
        $category = Category::withCount(['products', 'subcategories'])
            ->with('subcategories:id,category_id,name,name_ar,slug,icon,is_active,order')
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:categories,name',
            'name_ar'     => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:100',
            'image'       => 'nullable|string|max:500',
            'is_active'   => 'sometimes|boolean',
            'order'       => 'sometimes|integer|min:0',
        ]);

        $category = Category::create([
            'name'        => $validated['name'],
            'name_ar'     => $validated['name_ar']     ?? null,
            'slug'        => $this->uniqueSlug(Str::slug($validated['name'])),
            'description' => $validated['description'] ?? null,
            'icon'        => $validated['icon']        ?? null,
            'image'       => $validated['image']       ?? null,
            'is_active'   => $validated['is_active']   ?? true,  // explicit default = true
            'order'       => $validated['order']       ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created.',
            'data'    => $category,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255|unique:categories,name,' . $id,
            'name_ar'     => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:100',
            'image'       => 'nullable|string|max:500',
            'is_active'   => 'sometimes|boolean',
            'order'       => 'sometimes|integer|min:0',
        ]);

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = $this->uniqueSlug(Str::slug($validated['name']), $id);
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated.',
            'data'    => $category->fresh(),
        ]);
    }

    public function toggle($id)
    {
        $category = Category::findOrFail($id);
        $category->update(['is_active' => !$category->is_active]);

        return response()->json([
            'success'   => true,
            'message'   => 'Category ' . ($category->is_active ? 'activated' : 'deactivated') . '.',
            'is_active' => $category->is_active,
        ]);
    }

    public function destroy($id)
    {
        $category = Category::withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: {$category->products_count} product(s) are linked to this category.",
            ], 422);
        }

        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted.']);
    }

    private function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug     = $base;
        $original = $slug;
        $counter  = 2;

        while (true) {
            $query = DB::table('categories')->where('slug', $slug);
            if ($excludeId) $query->where('id', '!=', $excludeId);
            if (!$query->exists()) break;
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}