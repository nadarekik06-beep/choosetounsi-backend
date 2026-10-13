<?php

namespace App\Services\Intigo;

use App\Models\IntigoZone;
use Illuminate\Support\Facades\DB;

/**
 * Intigo's "Zone (Délégation/Secteur)" list, built only from Intigo's own data:
 *
 *   intigo_cities / intigo_districts   GET /regions/cities, /regions/cities/{id}/districts
 *   intigo_neighborhoods               GET /regions/districts/{id}/neighborhoods
 *                                      (one call per délégation: throttled, resumable)
 *   intigo_zones                       one row per délégation (DELEGATION) and per
 *                                      quartier (SECTEUR), labelled like Intigo
 *
 * php artisan intigo:sync-zones is the source; database/data/intigo_regions.json
 * (written by that command) is the fallback the seeder loads, never a hand-made list.
 */
class IntigoZones
{
    public const SNAPSHOT = 'database/data/intigo_regions.json';

    public function __construct(private IntigoRegions $regions) {}

    // ── Labels and search ──────────────────────────────────────────────────

    /** "Bab Bhar (Tunis)" · "Aeroport Tunis Carthage, La Soukra (Ariana)" */
    public static function label(string $type, string $name, string $delegation, string $gouvernorat): string
    {
        return $type === IntigoZone::SECTEUR
            ? "{$name}, {$delegation} ({$gouvernorat})"
            : "{$delegation} ({$gouvernorat})";
    }

    /** Lowercase, accents and Arabic diacritics removed, punctuation → spaces. */
    public static function fold(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text;
        }
        $text = preg_replace('/\p{Mn}+/u', '', $text);                  // é → e, harakat
        $text = strtr($text, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', 'ـ' => '']);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Partial, accent-insensitive: every word typed must appear ("tuni" finds
     * "Tunis Belvedere" and "Aeroport Tunis Carthage"). Délégations and names
     * starting with the query first.
     *
     * @return IntigoZone[]
     */
    public static function search(string $query, int $limit = 20): array
    {
        $folded = self::fold($query);
        if ($folded === '') {
            return [];
        }
        $words = array_slice(array_unique(explode(' ', $folded)), 0, 6);
        $limit = max(1, min(50, $limit));

        $q = IntigoZone::query()->where('active', true);
        foreach ($words as $word) {
            $q->where('search_text', 'like', '%' . addcslashes($word, '%_\\') . '%');
        }
        // Rank in PHP (≤ 400 candidates): the zone's own name first, then its délégation, then its governorate
        $rank = function (IntigoZone $z) use ($folded): int {
            $name = self::fold($z->name);
            $deleg = self::fold($z->delegation);
            return match (true) {
                str_starts_with($name, $folded)            => 0,
                str_contains(' ' . $name, ' ' . $folded)   => 1,
                str_contains($name, $folded)               => 2,
                str_starts_with($deleg, $folded)           => 3,
                str_contains(' ' . $deleg, ' ' . $folded)  => 4,
                default                                    => 5,
            };
        };

        return $q->limit(400)->get()
            ->sortBy([
                fn ($a, $b) => $rank($a) <=> $rank($b),
                fn ($a, $b) => ($a->type === IntigoZone::DELEGATION ? 0 : 1) <=> ($b->type === IntigoZone::DELEGATION ? 0 : 1),
                fn ($a, $b) => strcmp($a->display_label, $b->display_label),
            ])
            ->take($limit)->values()->all();
    }

    // ── Sync ───────────────────────────────────────────────────────────────

    /**
     * Neighborhoods of every délégation not fetched yet (all of them with
     * $fresh), $delayMs apart. Each délégation is marked when done, so an
     * interrupted run resumes where it stopped.
     *
     * @return array{fetched:int, skipped:int, neighborhoods:int, failed:array<int,string>}
     */
    public function syncNeighborhoods(bool $fresh = false, int $delayMs = 300, ?\Closure $progress = null): array
    {
        $districts = DB::table('intigo_districts')->orderBy('id')
            ->when(!$fresh, fn ($q) => $q->whereNull('neighborhoods_synced_at'))
            ->get(['id', 'name']);
        $skipped = DB::table('intigo_districts')->count() - $districts->count();

        $fetched = $count = 0;
        $failed  = [];
        foreach ($districts as $i => $district) {
            if ($i > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
            try {
                $hoods = Shapes::list($this->regions->get("/regions/districts/{$district->id}/neighborhoods"), ['neighborhoods', 'data', 'results']);
            } catch (\Throwable $e) {
                $failed[$district->id] = $e->getMessage();
                $progress && $progress($i + 1, $districts->count(), $district->name, null);
                continue;
            }
            $count += $this->storeNeighborhoods((int) $district->id, $hoods);
            $fetched++;
            $progress && $progress($i + 1, $districts->count(), $district->name, count($hoods));
        }

        return ['fetched' => $fetched, 'skipped' => $skipped, 'neighborhoods' => $count, 'failed' => $failed];
    }

    /** Upsert one délégation's quartiers as Intigo returned them; drop the ones it no longer lists. */
    private function storeNeighborhoods(int $districtId, array $hoods): int
    {
        $now  = now();
        $rows = [];
        foreach ($hoods as $n) {
            if (!is_array($n) || !isset($n['id'], $n['name'])) continue;
            $rows[(int) $n['id']] = [
                'id' => (int) $n['id'], 'district_id' => $districtId, 'name' => mb_substr(trim($n['name']), 0, 120),
                'name_ar' => isset($n['name_ar']) && trim((string) $n['name_ar']) !== '' ? mb_substr(trim($n['name_ar']), 0, 120) : null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::transaction(function () use ($districtId, $rows, $now) {
            foreach (array_chunk(array_values($rows), 300) as $chunk) {
                DB::table('intigo_neighborhoods')->upsert($chunk, ['id'], ['district_id', 'name', 'name_ar', 'updated_at']);
            }
            DB::table('intigo_neighborhoods')->where('district_id', $districtId)->whereNotIn('id', array_keys($rows) ?: [0])->delete();
            DB::table('intigo_districts')->where('id', $districtId)->update(['neighborhoods_synced_at' => $now]);
        });
        return count($rows);
    }

    /**
     * intigo_zones from the three region tables. Idempotent: upsert on
     * (type, intigo_id), cached map centres kept, vanished rows deactivated.
     *
     * @return array{delegations:int, secteurs:int, deactivated:int}
     */
    public function rebuild(): array
    {
        $now    = now();
        $cities = DB::table('intigo_cities')->get(['id', 'name', 'name_ar', 'wilaya'])->keyBy('id');
        $districts = DB::table('intigo_districts')->get(['id', 'city_id', 'name', 'name_ar'])->keyBy('id');

        $rows = [];
        foreach ($districts as $d) {
            $city = $cities[$d->city_id] ?? null;
            if (!$city) continue;
            $rows[] = $this->row(IntigoZone::DELEGATION, (int) $d->id, $d->name, $d->name_ar, $d, $city, $now);
        }
        $delegations = count($rows);

        $secteurs = 0;
        DB::table('intigo_neighborhoods')->orderBy('id')->chunk(1000, function ($hoods) use (&$rows, &$secteurs, $districts, $cities, $now) {
            foreach ($hoods as $n) {
                $d    = $districts[$n->district_id] ?? null;
                $city = $d ? ($cities[$d->city_id] ?? null) : null;
                if (!$city) continue;
                $rows[] = $this->row(IntigoZone::SECTEUR, (int) $n->id, $n->name, $n->name_ar, $d, $city, $now);
                $secteurs++;
            }
        });

        $seen = [];
        DB::transaction(function () use ($rows, &$seen) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('intigo_zones')->upsert($chunk, ['type', 'intigo_id'], [
                    'name', 'name_ar', 'delegation_id', 'delegation', 'gouvernorat_id', 'gouvernorat', 'wilaya',
                    'display_label', 'search_text', 'active', 'updated_at',
                ]);
            }
            foreach ($rows as $r) {
                $seen[$r['type']][] = $r['intigo_id'];
            }
        });

        $deactivated = 0;
        foreach ([IntigoZone::DELEGATION, IntigoZone::SECTEUR] as $type) {
            $deactivated += DB::table('intigo_zones')->where('type', $type)->where('active', true)
                ->whereNotIn('intigo_id', $seen[$type] ?? [0])->update(['active' => false, 'updated_at' => now()]);
        }

        return ['delegations' => $delegations, 'secteurs' => $secteurs, 'deactivated' => $deactivated];
    }

    private function row(string $type, int $intigoId, string $name, ?string $nameAr, object $district, object $city, $now): array
    {
        $label = self::label($type, $name, $district->name, $city->name);
        return [
            'type'           => $type,
            'intigo_id'      => $intigoId,
            'name'           => $name,
            'name_ar'        => $nameAr,
            'delegation_id'  => (int) $district->id,
            'delegation'     => $district->name,
            'gouvernorat_id' => (int) $city->id,
            'gouvernorat'    => $city->name,
            'wilaya'         => $city->wilaya ?: IntigoRegions::wilayaFor($city->name),
            'display_label'  => mb_substr($label, 0, 400),
            'search_text'    => mb_substr(self::fold($label . ' ' . implode(' ', array_filter([$nameAr, $district->name_ar, $city->name_ar]))), 0, 600),
            'active'         => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];
    }

    // ── Snapshot (fallback for the seeder) ─────────────────────────────────

    /** Writes Intigo's regions as synced (cities, districts, neighborhoods) to the snapshot file. */
    public function exportSnapshot(?string $path = null): array
    {
        $data = [
            'source'        => config('intigo.base_url') . '/regions/*',
            'exported_at'   => now()->toIso8601String(),
            'cities'        => DB::table('intigo_cities')->orderBy('id')->get(['id', 'name', 'name_ar'])->map(fn ($r) => (array) $r)->all(),
            'districts'     => DB::table('intigo_districts')->orderBy('id')->get(['id', 'city_id', 'name', 'name_ar'])->map(fn ($r) => (array) $r)->all(),
            'neighborhoods' => DB::table('intigo_neighborhoods')->orderBy('id')->get(['id', 'district_id', 'name', 'name_ar'])->map(fn ($r) => (array) $r)->all(),
        ];
        $path ??= base_path(self::SNAPSHOT);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return ['cities' => count($data['cities']), 'districts' => count($data['districts']), 'neighborhoods' => count($data['neighborhoods'])];
    }

    /** Loads the snapshot into the region tables (upsert), then rebuilds the zones. */
    public function importSnapshot(?string $path = null): array
    {
        $path ??= base_path(self::SNAPSHOT);
        if (!is_file($path)) {
            throw new \RuntimeException("Snapshot not found: {$path}. Run php artisan intigo:sync-zones first.");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || empty($data['cities']) || empty($data['districts'])) {
            throw new \RuntimeException("Snapshot {$path} is empty or invalid.");
        }
        $now   = now();
        $stamp = fn (array $rows, array $extra = []) => array_map(fn ($r) => $r + $extra + ['created_at' => $now, 'updated_at' => $now], $rows);

        DB::transaction(function () use ($data, $stamp, $now) {
            $cities = array_map(fn ($c) => $c + ['wilaya' => IntigoRegions::wilayaFor($c['name'])], $data['cities']);
            DB::table('intigo_cities')->upsert($stamp($cities), ['id'], ['name', 'name_ar', 'wilaya', 'updated_at']);
            foreach (array_chunk($stamp($data['districts']), 300) as $chunk) {
                DB::table('intigo_districts')->upsert($chunk, ['id'], ['city_id', 'name', 'name_ar', 'updated_at']);
            }
            foreach (array_chunk($stamp($data['neighborhoods'] ?? []), 500) as $chunk) {
                DB::table('intigo_neighborhoods')->upsert($chunk, ['id'], ['district_id', 'name', 'name_ar', 'updated_at']);
            }
            $withHoods = array_unique(array_column($data['neighborhoods'] ?? [], 'district_id'));
            if ($withHoods) {
                DB::table('intigo_districts')->whereIn('id', $withHoods)->update(['neighborhoods_synced_at' => $now]);
            }
        });
        IntigoRegions::flush();

        return $this->rebuild();
    }
}
