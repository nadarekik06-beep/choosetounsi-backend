<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Http\Request;

class SubcategoryController extends Controller
{
    /**
     * GET /api/categories/{slug}/subcategories
     */
    public function index($slug)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $subcategories = Subcategory::where('category_id', $category->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'name_ar', 'slug', 'icon', 'order']);

        return response()->json([
            'success' => true,
            'data'    => $subcategories,
        ]);
    }

    /**
     * GET /api/subcategories/{id}/attributes
     *
     * Returns ALL attributes for this subcategory, split into two groups:
     *
     *  variant_attributes  — is_variant=true  → used to generate variant combinations
     *  info_attributes     — is_variant=false → simple product-level fields
     *
     * Response shape:
     * {
     *   "success": true,
     *   "data": {
     *     "variant_attributes": [
     *       { "id": 1, "slug": "color", "name": "Color", "type": "color",
     *         "is_required": false, "is_variant": true,
     *         "options": [{ "id": 1, "value": "Red", "color_hex": "#DC2626" }, ...] }
     *     ],
     *     "info_attributes": [
     *       { "id": 3, "slug": "material", "name": "Material", "type": "select",
     *         "is_required": false, "is_variant": false,
     *         "options": [...] }
     *     ]
     *   }
     * }
     */
    public function attributes($id)
    {
        $subcategory = Subcategory::findOrFail($id);

        // Load all attributes with pivot data
        $all = $subcategory->attributes()->get();

        $variantAttrs = [];
        $infoAttrs    = [];

        foreach ($all as $attr) {
            $pivot     = $attr->pivot;
            $isVariant = (bool) ($pivot->is_variant ?? false);

            $formatted = [
                'id'            => $attr->id,
                'slug'          => $attr->slug,
                'name'          => $attr->name,
                'name_ar'       => $attr->name_ar,
                'type'          => $attr->type,
                'is_required'   => (bool) ($pivot->is_required ?? $attr->is_required),
                'is_variant'    => $isVariant,
                'is_filterable' => (bool) $attr->is_filterable,
                'order'         => $pivot->order ?? $attr->order,
                'options'       => $attr->options->map(fn($opt) => [
                    'id'        => $opt->id,
                    'value'     => $opt->value,
                    'value_ar'  => $opt->value_ar,
                    'color_hex' => $opt->color_hex,
                    'order'     => $opt->order,
                ])->values(),
            ];

            if ($isVariant) {
                $variantAttrs[] = $formatted;
            } else {
                $infoAttrs[] = $formatted;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'variant_attributes' => $variantAttrs,
                'info_attributes'    => $infoAttrs,
            ],
        ]);
    }
}