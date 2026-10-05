<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The searchable photo fingerprints, kept in the Laravel cache so a search never reads the whole
 * table: one compact blob per catalog version (vectors stay packed float32 in it), unpacked once
 * per PHP process.
 *
 * Only photos of live products (approved, active, not deleted) of active sellers are in it.
 * Each photo belongs to a "group", the finest category it has (subcategory, else category);
 * every group also gets a centroid (normalized mean of its photos) for category detection.
 *
 * Invalidation is a version number: anything that changes what is searchable calls bump()
 * (photo indexed or removed, product status or category, seller (de)activated, rebuild),
 * which also drops every cached search result.
 */
class FingerprintIndex
{
    const VERSION_KEY = 'image_search:version';
    const HAS_PHOTOS_KEY = 'image_search:has_photos';
    const TTL_HOURS = 24;

    private static ?array $memo = null;

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public function bump(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
        Cache::forget(self::HAS_PHOTOS_KEY);
        self::$memo = null;
    }

    /**
     * @return array{
     *   version: int, model: ?string, count: int,
     *   vectors: array<int, array<int, float>>, image_ids: int[], product_ids: int[], color_option_ids: array<int, ?int>,
     *   groups: string[], category_ids: array<int, ?int>, colors: array<int, ?array>,
     *   centroids: array<string, array<int, float>>, group_meta: array<string, array{category_id: ?int, subcategory_id: ?int, photos: int}>
     * }
     */
    public function get(): array
    {
        $version = $this->version();
        if (self::$memo && self::$memo['version'] === $version) {
            return self::$memo;
        }

        $key = "image_search:index:$version";
        $data = Cache::get($key);
        if (!is_array($data)) {
            $data = $this->build();
            Cache::put($key, $data, now()->addHours(self::TTL_HOURS));
        }

        $vectors = [];
        for ($i = 0; $i < $data['count']; $i++) {
            $vectors[] = Fingerprint::unpack(substr($data['vectors'], $i * Fingerprint::BYTES, Fingerprint::BYTES));
        }
        return self::$memo = ['version' => $version, 'vectors' => $vectors,
            'centroids' => array_map(fn ($b) => Fingerprint::unpack($b), $data['centroids'])] + $data;
    }

    /** Anything searchable (cheap: no index load; remembered until the next bump). */
    public static function hasPhotos(): bool
    {
        return (bool) Cache::remember(self::HAS_PHOTOS_KEY, 300, fn () => self::liveRows()->exists());
    }

    /** One query for the whole catalog; vectors stay packed (2 KB per photo). */
    private function build(): array
    {
        $rows = self::liveRows()
            ->orderBy('f.product_id')->orderByDesc('pi.is_primary')->orderBy('pi.order')->orderBy('pi.id')
            ->get(['f.product_image_id', 'f.product_id', 'f.vector', 'f.color', 'f.model',
                   'pi.color_option_id', 'p.category_id', 'p.subcategory_id']);

        // Vectors of two models can't be compared: keep the model most photos have (a model change
        // is finished by php artisan image-search:rebuild, which re-embeds the others).
        $model = $rows->countBy('model')->sortDesc()->keys()->first();

        $out = ['model' => $model, 'count' => 0, 'vectors' => '', 'image_ids' => [], 'product_ids' => [],
                'color_option_ids' => [], 'groups' => [], 'category_ids' => [], 'colors' => [],
                'centroids' => [], 'group_meta' => []];
        $sums = [];
        foreach ($rows as $r) {
            if ($r->model !== $model || strlen($r->vector) !== Fingerprint::BYTES) {
                continue;
            }
            $group = $r->subcategory_id ? "s{$r->subcategory_id}" : ($r->category_id ? "c{$r->category_id}" : '');
            $out['count']++;
            $out['vectors'] .= $r->vector;
            $out['image_ids'][] = (int) $r->product_image_id;
            $out['product_ids'][] = (int) $r->product_id;
            $out['color_option_ids'][] = $r->color_option_id ? (int) $r->color_option_id : null;
            $out['groups'][] = $group;
            $out['category_ids'][] = $r->category_id ? (int) $r->category_id : null;
            $out['colors'][] = Fingerprint::lab($r->color);

            if ($group !== '') {
                $v = Fingerprint::unpack($r->vector);
                if (!isset($sums[$group])) {
                    $sums[$group] = $v;
                    $out['group_meta'][$group] = ['category_id' => $r->category_id ? (int) $r->category_id : null,
                        'subcategory_id' => $r->subcategory_id ? (int) $r->subcategory_id : null, 'photos' => 0];
                } else {
                    foreach ($v as $k => $x) {
                        $sums[$group][$k] += $x;
                    }
                }
                $out['group_meta'][$group]['photos']++;
            }
        }
        foreach ($sums as $group => $sum) {
            $out['centroids'][$group] = Fingerprint::pack($sum);
        }
        return $out;
    }

    private static function liveRows()
    {
        return DB::table('image_fingerprints as f')
            ->join('product_images as pi', 'pi.id', '=', 'f.product_image_id')
            ->join('products as p', 'p.id', '=', 'f.product_id')
            ->join('users as u', 'u.id', '=', 'p.seller_id')
            ->where('p.is_approved', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->where('u.is_active', 1)
            ->where('pi.image_path', 'NOT LIKE', ImageIndexer::PLACEHOLDERS);
    }
}
