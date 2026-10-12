<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Intigo\IntigoRegions;
use Illuminate\Http\JsonResponse;

/**
 * Governorates and delegations for the checkout / address-book dropdowns
 * (Intigo's place names, synced by intigo:sync-regions). Public.
 *   GET /api/regions/cities
 *   GET /api/regions/cities/{id}/districts
 */
class RegionsController extends Controller
{
    public function cities(): JsonResponse
    {
        return response()->json([
            'success'   => true,
            'available' => IntigoRegions::available(),
            'data'      => IntigoRegions::cities(),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    public function districts(int $cityId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => IntigoRegions::districts($cityId),
        ])->header('Cache-Control', 'public, max-age=3600');
    }
}
