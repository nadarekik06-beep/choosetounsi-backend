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
}
