<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntigoZone;
use App\Services\Intigo\IntigoZones;
use App\Services\Intigo\ZoneGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The pickup-address zone combobox (Intigo's "Zone (Délégation/Secteur)"). Public.
 *
 *   GET /api/intigo-zones?q=tuni&limit=20   accent-insensitive partial search
 *   GET /api/intigo-zones/{id}              one zone
 *   GET /api/intigo-zones/{id}/center       approximate centre for the map (Nominatim, cached)
 */
class IntigoZonesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'     => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);
        $zones = IntigoZones::search((string) ($data['q'] ?? ''), (int) ($data['limit'] ?? 20));

        return response()->json([
            'success'   => true,
            'available' => IntigoZone::where('active', true)->exists(),
            'data'      => array_map(fn (IntigoZone $z) => $z->toOption(), $zones),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function show(int $id): JsonResponse
    {
        $zone = IntigoZone::findOrFail($id);
        return response()->json(['success' => true, 'data' => $zone->toOption() + ['active' => $zone->active]]);
    }

    public function center(int $id, ZoneGeocoder $geocoder): JsonResponse
    {
        $zone   = IntigoZone::findOrFail($id);
        $center = $geocoder->center($zone);

        return response()->json([
            'success' => true,
            'data'    => $center ? $center + ['warn_km' => ZoneGeocoder::WARN_KM[$center['precision']] ?? ZoneGeocoder::WARN_KM['delegation']] : null,
        ]);
    }
}
