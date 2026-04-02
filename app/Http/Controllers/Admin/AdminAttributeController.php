<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Admin Attribute Management
 *
 * Routes (add inside admin middleware group in api.php):
 *   GET    /admin/attributes                              → index (all global attributes)
 *   POST   /admin/attributes                              → store (create new attribute)
 *   PUT    /admin/attributes/{id}                         → update
 *   DELETE /admin/attributes/{id}                         → destroy
 *
 *   POST   /admin/attributes/{id}/options                 → addOption
 *   PUT    /admin/attributes/{id}/options/{optId}         → updateOption
 *   DELETE /admin/attributes/{id}/options/{optId}         → deleteOption
 *
 *   GET    /admin/subcategories/{id}/attributes           → subcategoryAttributes
 *   POST   /admin/subcategories/{id}/attributes           → assignAttribute
 *   PUT    /admin/subcategories/{id}/attributes/{attrId}  → updateAssignment
 *   DELETE /admin/subcategories/{id}/attributes/{attrId}  → removeAttribute
 */
class AdminAttributeController extends Controller
{
    // ── Global Attributes ──────────────────────────────────────────────────

    /**
     * GET /admin/attributes
     * All attributes with their options — for the "assign" dropdown.
     */
    public function index()
    {
        $attributes = Attribute::with('options')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $attributes]);
    }

    /**
     * POST /admin/attributes
     * Create a new global attribute.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'name_ar'       => 'nullable|string|max:255',
            'type'          => 'required|in:select,multiselect,text,number,boolean,color',
            'is_filterable' => 'sometimes|boolean',
            'is_visible'    => 'sometimes|boolean',
            'order'         => 'sometimes|integer|min:0',
        ]);

        // Auto-generate unique slug
        $slug = $this->uniqueSlug(Str::slug($validated['name']));

        $attribute = Attribute::create([
            'name'          => $validated['name'],
            'name_ar'       => $validated['name_ar']       ?? null,
            'slug'          => $slug,
            'type'          => $validated['type'],
            'is_required'   => false,
            'is_filterable' => $validated['is_filterable'] ?? true,
            'is_visible'    => $validated['is_visible']    ?? true,
            'order'         => $validated['order']         ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attribute created.',
            'data'    => $attribute->load('options'),
        ], 201);
    }

    /**
     * PUT /admin/attributes/{id}
     */
    public function update(Request $request, $id)
    {
        $attribute = Attribute::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'name_ar'       => 'nullable|string|max:255',
            'is_filterable' => 'sometimes|boolean',
            'is_visible'    => 'sometimes|boolean',
            'order'         => 'sometimes|integer|min:0',
        ]);

        $attribute->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attribute updated.',
            'data'    => $attribute->fresh('options'),
        ]);
    }

    /**
     * DELETE /admin/attributes/{id}
     * Only allowed if attribute is not assigned to any subcategory.
     */
    public function destroy($id)
    {
        $attribute = Attribute::withCount('subcategories')->findOrFail($id);

        if ($attribute->subcategories_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: this attribute is assigned to {$attribute->subcategories_count} subcategorie(s). Remove it from all subcategories first.",
            ], 422);
        }

        $attribute->delete();

        return response()->json(['success' => true, 'message' => 'Attribute deleted.']);
    }

    // ── Attribute Options ──────────────────────────────────────────────────

    /**
     * POST /admin/attributes/{id}/options
     */
    public function addOption(Request $request, $id)
    {
        $attribute = Attribute::findOrFail($id);

        $validated = $request->validate([
            'value'     => 'required|string|max:255',
            'value_ar'  => 'nullable|string|max:255',
            'color_hex' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'order'     => 'sometimes|integer|min:0',
        ]);

        $option = AttributeOption::create([
            'attribute_id' => $attribute->id,
            'value'        => $validated['value'],
            'value_ar'     => $validated['value_ar']  ?? null,
            'color_hex'    => $validated['color_hex'] ?? null,
            'order'        => $validated['order']     ?? ($attribute->options()->max('order') + 1),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Option added.',
            'data'    => $option,
        ], 201);
    }

    /**
     * PUT /admin/attributes/{id}/options/{optId}
     */
    public function updateOption(Request $request, $id, $optId)
    {
        $option = AttributeOption::where('attribute_id', $id)->findOrFail($optId);

        $validated = $request->validate([
            'value'     => 'sometimes|string|max:255',
            'value_ar'  => 'nullable|string|max:255',
            'color_hex' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'order'     => 'sometimes|integer|min:0',
        ]);

        $option->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Option updated.',
            'data'    => $option,
        ]);
    }

    /**
     * DELETE /admin/attributes/{id}/options/{optId}
     */
    public function deleteOption($id, $optId)
    {
        $option = AttributeOption::where('attribute_id', $id)->findOrFail($optId);

        // Check if this option is used in any variant
        $usedCount = DB::table('variant_attribute_values')
            ->where('attribute_option_id', $option->id)
            ->count();

        if ($usedCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: this option is used by {$usedCount} product variant(s).",
            ], 422);
        }

        $option->delete();

        return response()->json(['success' => true, 'message' => 'Option deleted.']);
    }

    // ── Subcategory ↔ Attribute Assignment ────────────────────────────────

    /**
     * GET /admin/subcategories/{id}/attributes
     * Returns all attributes assigned to this subcategory with pivot data.
     */
    public function subcategoryAttributes($subcategoryId)
    {
        $subcategory = Subcategory::findOrFail($subcategoryId);

        $attributes = $subcategory->attributes()
            ->with('options')
            ->get()
            ->map(fn($attr) => [
                'id'            => $attr->id,
                'name'          => $attr->name,
                'name_ar'       => $attr->name_ar,
                'slug'          => $attr->slug,
                'type'          => $attr->type,
                'is_filterable' => $attr->is_filterable,
                'is_required'   => (bool) $attr->pivot->is_required,
                'is_variant'    => (bool) $attr->pivot->is_variant,
                'order'         => $attr->pivot->order,
                'options'       => $attr->options->map(fn($o) => [
                    'id'        => $o->id,
                    'value'     => $o->value,
                    'value_ar'  => $o->value_ar,
                    'color_hex' => $o->color_hex,
                    'order'     => $o->order,
                ])->values(),
            ]);

        return response()->json(['success' => true, 'data' => $attributes]);
    }

    /**
     * POST /admin/subcategories/{id}/attributes
     * Assign an existing attribute to this subcategory.
     */
    public function assignAttribute(Request $request, $subcategoryId)
    {
        $subcategory = Subcategory::findOrFail($subcategoryId);

        $validated = $request->validate([
            'attribute_id' => 'required|exists:attributes,id',
            'is_variant'   => 'required|boolean',
            'is_required'  => 'sometimes|boolean',
            'order'        => 'sometimes|integer|min:0',
        ]);

        // Check if already assigned — update pivot if so
        $existing = DB::table('subcategory_attributes')
            ->where('subcategory_id', $subcategory->id)
            ->where('attribute_id',   $validated['attribute_id'])
            ->first();

        $pivotData = [
            'is_variant'  => $validated['is_variant'],
            'is_required' => $validated['is_required'] ?? false,
            'order'       => $validated['order'] ?? 0,
            'updated_at'  => now(),
        ];

        if ($existing) {
            DB::table('subcategory_attributes')
                ->where('subcategory_id', $subcategory->id)
                ->where('attribute_id',   $validated['attribute_id'])
                ->update($pivotData);

            $message = 'Attribute assignment updated.';
        } else {
            DB::table('subcategory_attributes')->insert(array_merge($pivotData, [
                'subcategory_id' => $subcategory->id,
                'attribute_id'   => $validated['attribute_id'],
                'created_at'     => now(),
            ]));

            $message = 'Attribute assigned to subcategory.';
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    /**
     * PUT /admin/subcategories/{id}/attributes/{attrId}
     * Update pivot data (is_variant, is_required, order).
     */
    public function updateAssignment(Request $request, $subcategoryId, $attrId)
    {
        $validated = $request->validate([
            'is_variant'  => 'sometimes|boolean',
            'is_required' => 'sometimes|boolean',
            'order'       => 'sometimes|integer|min:0',
        ]);

        $updated = DB::table('subcategory_attributes')
            ->where('subcategory_id', $subcategoryId)
            ->where('attribute_id',   $attrId)
            ->update(array_merge($validated, ['updated_at' => now()]));

        if (!$updated) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Assignment updated.']);
    }

    /**
     * DELETE /admin/subcategories/{id}/attributes/{attrId}
     * Remove an attribute from this subcategory (does NOT delete the attribute globally).
     */
    public function removeAttribute($subcategoryId, $attrId)
    {
        DB::table('subcategory_attributes')
            ->where('subcategory_id', $subcategoryId)
            ->where('attribute_id',   $attrId)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Attribute removed from subcategory.']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function uniqueSlug(string $base): string
    {
        $slug     = $base;
        $original = $slug;
        $counter  = 2;

        while (DB::table('attributes')->where('slug', $slug)->exists()) {
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}