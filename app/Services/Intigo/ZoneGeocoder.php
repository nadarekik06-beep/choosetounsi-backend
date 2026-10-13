<?php

namespace App\Services\Intigo;

use App\Models\IntigoZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Approximate centre of a zone, to centre the address map (the seller then
 * fine-tunes the pin). OpenStreetMap Nominatim, free: at most one call per
 * second, identified by a User-Agent, results cached on the zone row forever.
 * Tries "secteur, délégation, gouvernorat", then the délégation, then the
 * governorate. Never blocks anything: no answer → null (map shows Tunisia).
 *
 * Also the pin sanity checks shared by the backend validation and the cards.
 */
class ZoneGeocoder
{
    /** Tunisia's bounding box (a pin outside is refused). */
    public const BOUNDS = ['lat_min' => 30.2, 'lat_max' => 37.6, 'lng_min' => 7.5, 'lng_max' => 11.7];

    /** Beyond this distance from the zone centre the pin is flagged (km), per geocode precision. */
    public const WARN_KM = ['secteur' => 4, 'delegation' => 8, 'gouvernorat' => 40];

    public static function inTunisia(?float $lat, ?float $lng): bool
    {
        $b = self::BOUNDS;
        return $lat !== null && $lng !== null
            && $lat >= $b['lat_min'] && $lat <= $b['lat_max'] && $lng >= $b['lng_min'] && $lng <= $b['lng_max'];
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Pin vs zone centre. null when the centre is unknown.
     *
     * @return array{distance_km:float, limit_km:int, far:bool}|null
     */
    public static function check(?IntigoZone $zone, ?float $lat, ?float $lng): ?array
    {
        if (!$zone || $zone->latitude === null || $zone->longitude === null || $lat === null || $lng === null) {
            return null;
        }
        $limit = self::WARN_KM[$zone->geocode_precision ?? ''] ?? self::WARN_KM['delegation'];
        $km    = self::distanceKm($zone->latitude, $zone->longitude, $lat, $lng);
        return ['distance_km' => round($km, 1), 'limit_km' => $limit, 'far' => $km > $limit];
    }

    /** @return array{latitude:float, longitude:float, precision:string}|null */
    public function center(IntigoZone $zone): ?array
    {
        if ($zone->geocoded_at) {
            return $zone->latitude !== null
                ? ['latitude' => $zone->latitude, 'longitude' => $zone->longitude, 'precision' => $zone->geocode_precision]
                : null;
        }

        // A délégation already geocoded answers for its secteurs' fallback, and the other way round
        $attempts = [];
        if ($zone->isSecteur()) {
            $attempts[] = ['secteur', "{$zone->name}, {$zone->delegation}, {$zone->gouvernorat}"];
        }
        $attempts[] = ['delegation', "{$zone->delegation}, {$zone->gouvernorat}"];
        $attempts[] = ['gouvernorat', $zone->gouvernorat];

        foreach ($attempts as [$precision, $query]) {
            if ($precision !== 'secteur' && ($known = $this->known($zone, $precision))) {
                return $this->store($zone, $known['latitude'], $known['longitude'], $precision);
            }
            $hit = $this->nominatim($query);
            if ($hit === false) {
                return null;                 // Nominatim unreachable: retry another time, cache nothing
            }
            if ($hit) {
                return $this->store($zone, $hit[0], $hit[1], $precision);
            }
        }

        $zone->forceFill(['geocoded_at' => now(), 'geocode_precision' => 'none'])->save();
        return null;
    }

    /** Coordinates already found for the same délégation (or governorate). */
    private function known(IntigoZone $zone, string $precision): ?array
    {
        $row = IntigoZone::query()->whereNotNull('latitude')
            ->where('geocode_precision', $precision)
            ->when($precision === 'delegation', fn ($q) => $q->where('delegation_id', $zone->delegation_id))
            ->when($precision === 'gouvernorat', fn ($q) => $q->where('gouvernorat_id', $zone->gouvernorat_id))
            ->first(['latitude', 'longitude']);
        return $row ? ['latitude' => $row->latitude, 'longitude' => $row->longitude] : null;
    }

    private function store(IntigoZone $zone, float $lat, float $lng, string $precision): array
    {
        $zone->forceFill(['latitude' => $lat, 'longitude' => $lng, 'geocode_precision' => $precision, 'geocoded_at' => now()])->save();
        return ['latitude' => $lat, 'longitude' => $lng, 'precision' => $precision];
    }

    /** @return array{0:float,1:float}|null|false  false = unreachable */
    private function nominatim(string $query): array|null|false
    {
        try {
            // Nominatim's policy: max 1 request per second, for the whole app
            $lock = Cache::lock('nominatim-throttle', 2);
            $lock->block(5);
            try {
                $response = Http::withHeaders(['User-Agent' => config('services.nominatim.user_agent')])
                    ->acceptJson()->timeout(8)
                    ->get(config('services.nominatim.url'), [
                        'q' => $query . ', Tunisie', 'format' => 'jsonv2', 'countrycodes' => 'tn', 'limit' => 1,
                        'accept-language' => 'fr',
                    ]);
                usleep(1_000_000);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('[ZoneGeocoder] Nominatim unreachable: ' . $e->getMessage());
            return false;
        }
        if (!$response->successful()) {
            Log::warning('[ZoneGeocoder] Nominatim HTTP ' . $response->status() . " for \"{$query}\"");
            return false;
        }
        $hit = $response->json()[0] ?? null;
        if (!is_array($hit) || !isset($hit['lat'], $hit['lon'])) {
            return null;
        }
        $lat = (float) $hit['lat'];
        $lng = (float) $hit['lon'];
        return self::inTunisia($lat, $lng) ? [$lat, $lng] : null;
    }
}
