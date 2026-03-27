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
     *
     * Returns all active subcategories for a category.
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
     * Returns all attributes linked to this subcategory via subcategory_attributes pivot,
     * with their full options list.
     *
     * Used by:
     *   1. The seller ProductModal to build the DynamicAttributeSection
     *   2. The seller ProductModal to determine available variant axes
     *      (attributes of type 'select', 'color', 'multiselect' with options)
     */
    public function attributes($id)
    {
        $subcategory = Subcategory::findOrFail($id);

        // Load attributes through the pivot table, ordered by pivot order then attribute order
        $attributes = $subcategory->attributes()
            ->orderBy('subcategory_attributes.order')
            ->orderBy('attributes.order')
            ->get()
            ->map(function ($attr) use ($subcategory) {
                // Get is_required from the pivot
                $pivot = $attr->pivot ?? null;

                return [
                    'id'            => $attr->id,
                    'slug'          => $attr->slug,
                    'name'          => $attr->name,
                    'name_ar'       => $attr->name_ar,
                    'type'          => $attr->type,
                    'is_required'   => $pivot ? (bool) $pivot->is_required : (bool) $attr->is_required,
                    'is_filterable' => (bool) $attr->is_filterable,
                    'order'         => $pivot ? $pivot->order : $attr->order,
                    // Include all options — essential for the VariantBuilder dropdowns
                    'options'       => $attr->options->map(fn($opt) => [
                        'id'        => $opt->id,
                        'value'     => $opt->value,
                        'value_ar'  => $opt->value_ar,
                        'color_hex' => $opt->color_hex,
                        'order'     => $opt->order,
                    ])->values(),
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $attributes,
        ]);
    }
}