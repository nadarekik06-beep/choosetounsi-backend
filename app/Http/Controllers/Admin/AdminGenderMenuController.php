<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\GenderMenuGroup;
use App\Models\GenderMenuItem;
use App\Models\Subcategory;
use App\Models\SubcategoryGenderImage;
use App\Services\Gender\GenderMenu;
use App\Services\Gender\GenderResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin: gender menu of a gendered category.
 *   - columns (groups) per gender, with FR/EN/AR names and order
 *   - which subcategories sit in which column (their genders), with order
 *   - one image per subcategory × gender for the storefront bar
 *
 * Every change rebuilds the storefront caches and re-derives the inherited gender of
 * the products in the subcategories it touched.
 */
class AdminGenderMenuController extends Controller
{
    public function __construct(private GenderMenu $menu, private GenderResolver $resolver) {}

    /** GET /admin/gender-menu/{categoryId} */
    public function show(int $categoryId)
    {
        $category = Category::findOrFail($categoryId);

        $groups = GenderMenuGroup::where('category_id', $categoryId)
            ->with(['items.subcategory:id,category_id,name,name_fr,name_ar,slug,is_active'])
            ->orderBy('sort_order')->orderBy('id')->get();

        // Subcategories the admin can place: this category's first, then the others
        $subs = Subcategory::query()
            ->join('categories as c', 'c.id', '=', 'subcategories.category_id')
            ->orderByRaw('subcategories.category_id = ? DESC', [$categoryId])
            ->orderBy('c.order')->orderBy('subcategories.order')->orderBy('subcategories.id')
            ->get(['subcategories.id', 'subcategories.category_id', 'subcategories.name', 'subcategories.name_fr',
                   'subcategories.name_ar', 'subcategories.slug', 'subcategories.is_active', 'c.name as category_name']);

        $genders = DB::table('gender_menu_items as i')
            ->join('gender_menu_groups as g', 'g.id', '=', 'i.group_id')
            ->where('g.category_id', $categoryId)
            ->select('i.subcategory_id', 'g.gender')->distinct()->get()
            ->groupBy('subcategory_id')->map(fn ($r) => $r->pluck('gender')->values());

        $images = SubcategoryGenderImage::whereIn('subcategory_id', $subs->pluck('id'))->get()
            ->groupBy('subcategory_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->gender => $this->menu->url($r->image_path)]));

        $counts = DB::table('products')->whereNull('deleted_at')
            ->whereIn('subcategory_id', $subs->pluck('id'))
            ->select('subcategory_id', 'gender', DB::raw('COUNT(*) as n'))
            ->groupBy('subcategory_id', 'gender')->get()
            ->groupBy('subcategory_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->gender ?? 'none' => (int) $r->n]));

        return response()->json(['success' => true, 'data' => [
            'category' => $category->only(['id', 'name', 'name_fr', 'name_ar', 'slug', 'is_gendered']),
            'groups'   => [
                'women' => $groups->where('gender', 'women')->values(),
                'men'   => $groups->where('gender', 'men')->values(),
            ],
            'subcategories' => $subs->map(fn ($s) => [
                'id'            => $s->id,
                'category_id'   => $s->category_id,
                'category_name' => $s->category_name,
                'name'          => $s->name,
                'name_fr'       => $s->name_fr,
                'name_ar'       => $s->name_ar,
                'slug'          => $s->slug,
                'is_active'     => (bool) $s->is_active,
                'genders'       => $genders[$s->id] ?? [],
                'images'        => $images[$s->id] ?? (object) [],
                'counts'        => $counts[$s->id] ?? (object) [],
            ])->values(),
        ]]);
    }

    /** POST /admin/gender-menu/{categoryId}/groups  { gender, name, name_fr?, name_ar? } */
    public function storeGroup(Request $request, int $categoryId)
    {
        Category::findOrFail($categoryId);
        $data = $request->validate([
            'gender'  => ['required', Rule::in(GenderResolver::BROWSABLE)],
            'name'    => 'required|string|max:120',
            'name_fr' => 'nullable|string|max:120',
            'name_ar' => 'nullable|string|max:120',
        ]);

        $key  = Str::slug($data['name']) ?: 'group';
        $base = $key; $n = 2;
        while (GenderMenuGroup::where(['category_id' => $categoryId, 'gender' => $data['gender'], 'key' => $key])->exists()) {
            $key = $base . '-' . $n++;
        }

        $group = GenderMenuGroup::create($data + [
            'category_id' => $categoryId,
            'key'         => $key,
            'sort_order'  => (int) GenderMenuGroup::where(['category_id' => $categoryId, 'gender' => $data['gender']])->max('sort_order') + 1,
        ]);

        return response()->json(['success' => true, 'data' => $group->load('items')], 201);
    }

    /** PUT /admin/gender-menu/groups/{id}  { name?, name_fr?, name_ar?, sort_order? } */
    public function updateGroup(Request $request, int $id)
    {
        $group = GenderMenuGroup::findOrFail($id);
        $group->update($request->validate([
            'name'       => 'sometimes|required|string|max:120',
            'name_fr'    => 'nullable|string|max:120',
            'name_ar'    => 'nullable|string|max:120',
            'sort_order' => 'sometimes|integer|min:0',
        ]));

        return response()->json(['success' => true, 'data' => $group->fresh('items')]);
    }

    /** DELETE /admin/gender-menu/groups/{id} */
    public function destroyGroup(int $id)
    {
        $group  = GenderMenuGroup::findOrFail($id);
        $subIds = $group->items()->pluck('subcategory_id')->all();

        DB::transaction(function () use ($group) {
            $group->items()->delete();
            $group->delete();
        });

        $this->changed($subIds);
        return response()->json(['success' => true]);
    }

    /** POST /admin/gender-menu/groups/{id}/items  { subcategory_id } */
    public function storeItem(Request $request, int $groupId)
    {
        $group = GenderMenuGroup::findOrFail($groupId);
        $data  = $request->validate(['subcategory_id' => 'required|integer|exists:subcategories,id']);

        $item = GenderMenuItem::firstOrCreate(
            ['group_id' => $group->id, 'subcategory_id' => $data['subcategory_id']],
            ['sort_order' => (int) $group->items()->max('sort_order') + 1]
        );

        $this->changed([$data['subcategory_id']]);
        return response()->json(['success' => true, 'data' => $item->load('subcategory')], 201);
    }

    /** DELETE /admin/gender-menu/items/{id} */
    public function destroyItem(int $id)
    {
        $item  = GenderMenuItem::findOrFail($id);
        $subId = $item->subcategory_id;
        $item->delete();

        $this->changed([$subId]);
        return response()->json(['success' => true]);
    }

    /**
     * PUT /admin/gender-menu/{categoryId}/order
     * { gender, groups: [{ id, items: [itemId, …] }, …] } — the new order, top to bottom.
     */
    public function reorder(Request $request, int $categoryId)
    {
        $data = $request->validate([
            'gender'          => ['required', Rule::in(GenderResolver::BROWSABLE)],
            'groups'          => 'required|array',
            'groups.*.id'     => 'required|integer',
            'groups.*.items'  => 'sometimes|array',
            'groups.*.items.*'=> 'integer',
        ]);

        $own = GenderMenuGroup::where(['category_id' => $categoryId, 'gender' => $data['gender']])->pluck('id')->all();

        DB::transaction(function () use ($data, $own) {
            foreach ($data['groups'] as $gi => $g) {
                if (!in_array((int) $g['id'], $own, true)) continue;
                GenderMenuGroup::where('id', $g['id'])->update(['sort_order' => $gi]);
                foreach ($g['items'] ?? [] as $ii => $itemId) {
                    GenderMenuItem::where('id', $itemId)->where('group_id', $g['id'])->update(['sort_order' => $ii]);
                }
            }
        });

        $this->menu->flush();
        return response()->json(['success' => true]);
    }

    /**
     * PUT /admin/gender-menu/{categoryId}/subcategories/{subId}/genders  { genders: ["women","men"] }
     * The Femme / Homme checkboxes. A newly ticked gender puts the subcategory in the column with
     * the same key as in the other gender, else the first column (created if there is none).
     */
    public function setSubcategoryGenders(Request $request, int $categoryId, int $subId)
    {
        Category::findOrFail($categoryId);
        Subcategory::findOrFail($subId);
        $data = $request->validate([
            'genders'   => 'present|array',
            'genders.*' => [Rule::in(GenderResolver::BROWSABLE)],
        ]);
        $wanted = array_values(array_unique($data['genders']));

        DB::transaction(function () use ($categoryId, $subId, $wanted) {
            foreach (GenderResolver::BROWSABLE as $gender) {
                $groupIds = GenderMenuGroup::where(['category_id' => $categoryId, 'gender' => $gender])->pluck('id');
                $existing = GenderMenuItem::whereIn('group_id', $groupIds)->where('subcategory_id', $subId);

                if (!in_array($gender, $wanted, true)) {
                    $existing->get()->each->delete();
                    continue;
                }
                if ($existing->exists()) continue;

                $group = $this->targetGroup($categoryId, $subId, $gender);
                GenderMenuItem::create([
                    'group_id'       => $group->id,
                    'subcategory_id' => $subId,
                    'sort_order'     => (int) $group->items()->max('sort_order') + 1,
                ]);
            }
        });

        $this->changed([$subId]);
        return response()->json(['success' => true]);
    }

    /** POST /admin/subcategories/{subId}/gender-image  (multipart: gender, image) */
    public function uploadImage(Request $request, int $subId)
    {
        Subcategory::findOrFail($subId);
        $data = $request->validate([
            'gender' => ['required', Rule::in(GenderResolver::BROWSABLE)],
            'image'  => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $row = SubcategoryGenderImage::firstOrNew(['subcategory_id' => $subId, 'gender' => $data['gender']]);
        $old = $row->image_path;

        $row->image_path = $request->file('image')->store('subcategory-genders', 'public');
        $row->save();

        if ($old && !preg_match('#^https?://#', $old)) Storage::disk('public')->delete($old);

        return response()->json(['success' => true, 'data' => ['image' => $this->menu->url($row->image_path)]]);
    }

    /** DELETE /admin/subcategories/{subId}/gender-image?gender=women */
    public function destroyImage(Request $request, int $subId)
    {
        $gender = $request->validate(['gender' => ['required', Rule::in(GenderResolver::BROWSABLE)]])['gender'];
        $row    = SubcategoryGenderImage::where(['subcategory_id' => $subId, 'gender' => $gender])->first();

        if ($row) {
            if (!preg_match('#^https?://#', $row->image_path)) Storage::disk('public')->delete($row->image_path);
            $row->delete();
        }
        return response()->json(['success' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function targetGroup(int $categoryId, int $subId, string $gender): GenderMenuGroup
    {
        $other = $gender === 'women' ? 'men' : 'women';
        $key   = DB::table('gender_menu_items as i')
            ->join('gender_menu_groups as g', 'g.id', '=', 'i.group_id')
            ->where(['g.category_id' => $categoryId, 'g.gender' => $other, 'i.subcategory_id' => $subId])
            ->value('g.key');

        $q = GenderMenuGroup::where(['category_id' => $categoryId, 'gender' => $gender]);
        if ($key && ($same = (clone $q)->where('key', $key)->first())) return $same;
        if ($first = (clone $q)->orderBy('sort_order')->orderBy('id')->first()) return $first;

        return GenderMenuGroup::create([
            'category_id' => $categoryId, 'gender' => $gender, 'key' => 'other',
            'name' => 'Other', 'name_fr' => 'Autres', 'name_ar' => 'أخرى', 'sort_order' => 0,
        ]);
    }

    /** Re-derive inherited genders and rebuild storefront caches. */
    private function changed(array $subIds): void
    {
        $this->resolver->syncSubcategories($subIds);
        $this->menu->flush();
    }
}
