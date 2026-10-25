<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Gender\GenderMenu;
use App\Services\Gender\GenderResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Admin: products per browsing gender, and a manual fix when the mapping is wrong.
 * The fix is stored in products.gender_override (admin only) and wins over the seller value.
 */
class AdminProductGenderController extends Controller
{
    public function __construct(private GenderResolver $resolver) {}

    /** GET /admin/product-genders?gender=women|men|unisex|kids|none&source=&category_id=&search=&page= */
    public function index(Request $request)
    {
        $base = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->whereNull('p.deleted_at');

        if ($catId = $request->query('category_id')) $base->where('p.category_id', (int) $catId);
        if ($search = trim((string) $request->query('search'))) {
            $base->where(fn ($q) => $q->where('p.name', 'like', "%{$search}%")->orWhere('p.id', (int) $search));
        }

        // Summary before the gender / source filters, so the tabs always show their totals
        $summary = (clone $base)->select('p.gender', DB::raw('COUNT(*) as n'))->groupBy('p.gender')->get()
            ->mapWithKeys(fn ($r) => [$r->gender ?? 'none' => (int) $r->n]);

        $gender = $request->query('gender');
        if ($gender === 'none') $base->whereNull('p.gender');
        elseif (in_array($gender, GenderResolver::ALL, true)) $base->where('p.gender', $gender);

        $source = $request->query('source');
        if ($source === 'none') $base->whereNull('p.gender_source');
        elseif (in_array($source, ['seller', 'inherited', 'admin'], true)) $base->where('p.gender_source', $source);

        $page = $base->orderByDesc('p.id')->paginate(min((int) $request->query('per_page', 25), 100), [
            'p.id', 'p.name', 'p.slug', 'p.is_active', 'p.is_approved', 'p.gender', 'p.gender_source', 'p.gender_override',
            'c.name as category_name', 's.name as subcategory_name',
            DB::raw('(SELECT image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image_path'),
        ]);

        $page->getCollection()->transform(function ($r) {
            $r->image_url = $r->image_path ? Storage::url($r->image_path) : null;
            unset($r->image_path);
            return $r;
        });

        return response()->json(['success' => true, 'summary' => $summary, 'data' => $page]);
    }

    /** PUT /admin/products/{id}/gender  { gender: women|men|unisex|kids|auto }  — "auto" removes the fix */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'gender' => ['required', Rule::in(array_merge(GenderResolver::ALL, ['auto']))],
        ]);

        $exists = DB::table('products')->where('id', $id)->exists();
        abort_unless($exists, 404);

        DB::table('products')->where('id', $id)
            ->update(['gender_override' => $data['gender'] === 'auto' ? null : $data['gender']]);
        $this->resolver->sync($id);
        app(GenderMenu::class)->flush();

        return response()->json(['success' => true, 'data' => DB::table('products')->where('id', $id)
            ->first(['id', 'gender', 'gender_source', 'gender_override'])]);
    }
}
