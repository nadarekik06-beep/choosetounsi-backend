<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subcategory;
use Illuminate\Http\Request;

class SubcategoryController extends Controller
{
    /**
     * GET /api/categories/{categorySlug}/subcategories
     *
     * Returns all active subcategories for a category.
     */
    public function index($categorySlug)
    {
        $subcategories = Subcategory::whereHas('category', fn($q) => $q->where('slug', $categorySlug))
            ->active()
            ->ordered()
            ->select(['id', 'category_id', 'name', 'name_ar', 'slug', 'icon'])
            ->get();

        return response()->json(['success' => true, 'data' => $subcategories]);
    }

    /**
     * GET /api/subcategories/{id}/attributes
     *
     * Returns all attributes (with options) for a subcategory.
     * Used by the dynamic product form.
     */
    public function attributes($id)
    {
        $subcategory = Subcategory::with([
            'attributes' => function ($q) {
                $q->where('is_visible', true)
                  ->with(['options' => fn($o) => $o->orderBy('order')])
                  ->orderBy('subcategory_attributes.order');
            },
        ])->findOrFail($id);

        $attrs = $subcategory->attributes->map(function ($attr) {
            return [
                'id'            => $attr->id,
                'slug'          => $attr->slug,
                'name'          => $attr->name,
                'name_ar'       => $attr->name_ar,
                'type'          => $attr->type,
                'is_required'   => (bool) $attr->pivot->is_required,
                'is_filterable' => $attr->is_filterable,
                'options'       => $attr->options->map(fn($o) => [
                    'id'        => $o->id,
                    'value'     => $o->value,
                    'value_ar'  => $o->value_ar,
                    'color_hex' => $o->color_hex,
                ]),
            ];
        });

        return response()->json([
            'success'      => true,
            'subcategory'  => ['id' => $subcategory->id, 'name' => $subcategory->name],
            'attributes'   => $attrs,
        ]);
    }
}