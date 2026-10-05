<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SiteFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET /api/site-features          {wear_tounsi: bool, …}   (public)
 *   GET /api/admin/site-features    same, for the admin panel
 *   PUT /api/admin/site-features    {wear_tounsi: bool}      (known flags only)
 */
class SiteFeaturesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => SiteFeatures::all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (array_keys(SiteFeatures::DEFAULTS) as $name) {
            $rules[$name] = ['sometimes', 'boolean'];
        }
        $flags = $request->validate($rules);

        SiteFeatures::set(array_map('boolval', $flags), $request->user()?->id);

        return response()->json(['success' => true, 'data' => SiteFeatures::all()]);
    }
}
