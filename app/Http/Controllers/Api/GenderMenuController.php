<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Gender\GenderMenu;
use Illuminate\Http\Request;

/** Public storefront data for gendered categories (Mode & Vêtements → Femme / Homme). */
class GenderMenuController extends Controller
{
    public function __construct(private GenderMenu $menu) {}

    /**
     * GET /api/categories/mega-menu
     * [{ slug, name…, genders: { women: [{ key, name…, items: [{ slug, name…, category_slug }] }], men: [...] } }]
     */
    public function megaMenu()
    {
        return response()->json(['success' => true, 'data' => $this->menu->megaMenu()]);
    }

    /**
     * GET /api/categories/{slug}/gender-bar?gender=women|men
     * Round-thumbnail subcategory bar; empty subcategories are left out.
     */
    public function bar(Request $request, string $slug)
    {
        $gender = (string) $request->query('gender', '');
        return response()->json(['success' => true, 'data' => $this->menu->bar($slug, $gender)]);
    }

    /**
     * GET /api/categories/{slug}/subcategory-bar?gender=women|men
     * Round-image subcategory bar of any category page (gender only counts on gendered categories).
     * [{ id, slug, name, name_fr, name_ar, count, image, image_source: admin|product|null }]
     */
    public function subcategoryBar(Request $request, string $slug, \App\Services\Catalog\SubcategoryBar $bar)
    {
        return response()->json(['success' => true, 'data' => $bar->get($slug, $request->query('gender'))]);
    }
}
