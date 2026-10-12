<?php

namespace App\Services\Intigo;

use App\Support\Wilayas;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Intigo's governorates (cities), delegations (districts) and neighborhoods:
 * fetched from the public /regions endpoints (no API key, read-only, both
 * modes) into intigo_cities / intigo_districts / intigo_neighborhoods, which
 * feed the checkout and address-book dropdowns.
 */
class IntigoRegions
{
    public const CACHE_KEY = 'intigo.regions.v1';

    /** Intigo spellings our Wilayas::normalize() doesn't know. */
    private const WILAYA_ALIASES = ['Mannouba' => 'La Manouba', 'Manouba' => 'La Manouba'];

    public function __construct(private IntigoLogger $logger) {}

    /** Regions synced: checkout then requires a city and district picked from them. */
    public static function available(): bool
    {
        return Cache::remember(self::CACHE_KEY . '.available', 600, fn () => DB::table('intigo_districts')->exists());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY . '.available');
        Cache::forget(self::CACHE_KEY . '.cities');
    }

    /** @return array<int, array{id:int, name:string, name_ar:?string, wilaya:?string}> */
    public static function cities(): array
    {
        return Cache::remember(self::CACHE_KEY . '.cities', 3600, fn () => DB::table('intigo_cities')->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'wilaya'])->map(fn ($c) => (array) $c)->all());
    }

    /** @return array<int, array{id:int, name:string, name_ar:?string}> */
    public static function districts(int $cityId): array
    {
        return DB::table('intigo_districts')->where('city_id', $cityId)->orderBy('name')
            ->get(['id', 'name', 'name_ar'])->map(fn ($d) => (array) $d)->all();
    }

    public static function wilayaFor(string $intigoName): ?string
    {
        return self::WILAYA_ALIASES[$intigoName] ?? Wilayas::normalize($intigoName);
    }

    /** Intigo city name for one of our wilayas (old orders without city_name). */
    public static function cityNameForWilaya(?string $wilaya): ?string
    {
        $wilaya = Wilayas::normalize($wilaya);
        return $wilaya ? DB::table('intigo_cities')->where('wilaya', $wilaya)->value('name') : null;
    }

    /**
     * The picked city + district, validated together.
     *
     * @return array{city_id:int, district_id:int, city_name:string, district_name:string, wilaya:string}|null
     */
    public static function resolve(?int $cityId, ?int $districtId): ?array
    {
        if (!$cityId || !$districtId) {
            return null;
        }
        $row = DB::table('intigo_districts as d')->join('intigo_cities as c', 'c.id', '=', 'd.city_id')
            ->where('d.id', $districtId)->where('c.id', $cityId)
            ->first(['c.id as city_id', 'd.id as district_id', 'c.name as city_name', 'd.name as district_name', 'c.wilaya']);
        if (!$row) {
            return null;
        }
        return [
            'city_id'       => (int) $row->city_id,
            'district_id'   => (int) $row->district_id,
            'city_name'     => $row->city_name,
            'district_name' => $row->district_name,
            'wilaya'        => $row->wilaya ?: (self::wilayaFor($row->city_name) ?? $row->city_name),
        ];
    }

    // ── Sync (php artisan intigo:sync-regions) ─────────────────────────────

    /** @return array{cities:int, districts:int, neighborhoods:int} */
    public function sync(bool $withNeighborhoods = false, ?\Closure $progress = null): array
    {
        $now    = now();
        $cities = Shapes::list($this->get('/regions/cities'), ['cities', 'data', 'results']);
        if (!$cities) {
            throw new IntigoException('Intigo returned no cities');
        }

        $cityRows = [];
        foreach ($cities as $c) {
            if (!isset($c['id'], $c['name'])) continue;
            $cityRows[] = [
                'id' => (int) $c['id'], 'name' => mb_substr($c['name'], 0, 120), 'name_ar' => isset($c['name_ar']) ? mb_substr($c['name_ar'], 0, 120) : null,
                'wilaya' => self::wilayaFor($c['name']), 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('intigo_cities')->upsert($cityRows, ['id'], ['name', 'name_ar', 'wilaya', 'updated_at']);

        $districtCount = $neighborhoodCount = 0;
        foreach ($cityRows as $city) {
            $districts = Shapes::list($this->get("/regions/cities/{$city['id']}/districts"), ['districts', 'data', 'results']);
            $rows = [];
            foreach ($districts as $d) {
                if (!isset($d['id'], $d['name'])) continue;
                $rows[] = [
                    'id' => (int) $d['id'], 'city_id' => $city['id'], 'name' => mb_substr($d['name'], 0, 120),
                    'name_ar' => isset($d['name_ar']) ? mb_substr($d['name_ar'], 0, 120) : null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            if ($rows) {
                DB::table('intigo_districts')->upsert($rows, ['id'], ['city_id', 'name', 'name_ar', 'updated_at']);
            }
            $districtCount += count($rows);
            $progress && $progress($city['name'], count($rows));

            if ($withNeighborhoods) {
                foreach ($rows as $district) {
                    $hoods = Shapes::list($this->get("/regions/districts/{$district['id']}/neighborhoods"), ['neighborhoods', 'data', 'results']);
                    $hoodRows = [];
                    foreach ($hoods as $n) {
                        if (!isset($n['id'], $n['name'])) continue;
                        $hoodRows[] = [
                            'id' => (int) $n['id'], 'district_id' => $district['id'], 'name' => mb_substr($n['name'], 0, 120),
                            'name_ar' => isset($n['name_ar']) ? mb_substr($n['name_ar'], 0, 120) : null, 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                    if ($hoodRows) {
                        DB::table('intigo_neighborhoods')->upsert($hoodRows, ['id'], ['district_id', 'name', 'name_ar', 'updated_at']);
                    }
                    $neighborhoodCount += count($hoodRows);
                }
            }
        }

        self::flush();
        return ['cities' => count($cityRows), 'districts' => $districtCount, 'neighborhoods' => $neighborhoodCount];
    }

    /** Public endpoint (no key). */
    private function get(string $path): mixed
    {
        $started = microtime(true);
        try {
            $response = Http::acceptJson()->timeout(config('intigo.http.timeout', 20))->get(config('intigo.base_url') . $path);
        } catch (\Throwable $e) {
            $this->logger->log('live', 'GET', $path, null, null, null, (int) ((microtime(true) - $started) * 1000), null, $e->getMessage());
            throw IntigoException::connection($e->getMessage());
        }
        $body = $response->json();
        // Region answers are big and public: log the status only
        $this->logger->log('live', 'GET', $path, null, $response->status(), $response->successful() ? ['count' => is_array($body) ? count($body, COUNT_RECURSIVE) : 0] : $body, (int) ((microtime(true) - $started) * 1000), null);
        if (!$response->successful()) {
            throw IntigoException::fromResponse($response->status(), $body);
        }
        return $body;
    }
}
