<?php

namespace App\Services\Catalog;

use App\Services\Gender\GenderMenu;
use App\Services\Gender\GenderResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Round-image subcategory bar shown on every category page (GET /categories/{slug}/subcategory-bar).
 *
 * - Every active parent category; only subcategories with visible products are listed.
 * - ?gender=women|men only counts on a gendered category (Mode & Vêtements): that gender + unisex.
 * - Image: admin upload → main image of the most recent active, in-stock product → null (UI placeholder).
 * - Order: the admin mega-menu order, then subcategories.order.
 *
 * Built with a fixed number of queries (no per-subcategory query), cached 10 min. The cache key
 * carries the mega-menu version (bumped on any category / subcategory / menu / image edit) and
 * this bar's own version, bumped when a product change can alter counts or images (flush()).
 */
class SubcategoryBar
{
    private const TTL         = 600;
    private const VERSION_KEY = 'subcategory_bar:version';

    public function __construct(private GenderMenu $menu, private GenderResolver $resolver) {}

    /** Product side changed (visibility, stock to/from 0, subcategory, gender, images): rebuild every bar. */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    public function get(string $categorySlug, ?string $gender): array
    {
        $cat = $this->category($categorySlug);
        if (!$cat) return [];

        // Gender only applies to gendered categories; anything else is ignored
        $g   = ($cat->is_gendered && $this->resolver->isBrowsable($gender)) ? $gender : '';
        $key = 'subcategory_bar:m' . $this->menu->version() . ':p' . (int) Cache::get(self::VERSION_KEY, 0)
             . ':' . $cat->id . ':' . ($g ?: 'all');

        return Cache::remember($key, self::TTL, fn () => $this->build((int) $cat->id, (bool) $cat->is_gendered, $g));
    }

    private function category(string $slug): ?object
    {
        return DB::table('categories')->where('slug', $slug)->where('is_active', true)
            ->first(['id', 'slug', 'is_gendered']);
    }

    private function build(int $catId, bool $gendered, string $gender): array
    {
        // 1. Active subcategories of this category
        $subs = DB::table('subcategories')
            ->where('category_id', $catId)->where('is_active', true)
            ->orderBy('order')->orderBy('id')
            ->get(['id', 'slug', 'name', 'name_fr', 'name_ar']);
        if ($subs->isEmpty()) return [];
        $ids = $subs->pluck('id')->all();

        // 2. Visible products per subcategory (same rules as the product listing: approved, active, not deleted)
        $visible = function () use ($catId, $ids, $gender) {
            $q = DB::table('products')
                ->whereNull('products.deleted_at')
                ->where('products.is_approved', true)->where('products.is_active', true)
                ->where('products.category_id', $catId)
                ->whereIn('products.subcategory_id', $ids);
            if ($gender) $q->whereIn('products.gender', $this->resolver->matchingValues($gender));
            return $q;
        };

        $counts = $visible()->groupBy('products.subcategory_id')
            ->pluck(DB::raw('COUNT(*) as n'), 'products.subcategory_id');
        $subs = $subs->filter(fn ($s) => ($counts[$s->id] ?? 0) > 0)->values();
        if ($subs->isEmpty()) return [];
        $ids = $subs->pluck('id')->all();

        // 3. Admin images (uploaded per Femme / Homme): the browsed gender first, then any
        $admin = [];
        $rows  = DB::table('subcategory_gender_images')->whereIn('subcategory_id', $ids)
            ->orderByRaw("CASE WHEN gender = ? THEN 0 WHEN gender = 'women' THEN 1 ELSE 2 END", [$gender ?: 'women'])
            ->get(['subcategory_id', 'image_path']);
        foreach ($rows as $r) $admin[$r->subcategory_id] ??= $r->image_path;

        // 4. Fallback: newest active, in-stock product that has an image, per subcategory (2 queries total)
        $need     = array_values(array_diff($ids, array_keys($admin)));
        $fallback = [];
        if ($need) {
            $latest = $visible()
                ->whereIn('products.subcategory_id', $need)
                ->where('products.stock', '>', 0)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_images as pi')->whereColumn('pi.product_id', 'products.id'))
                ->groupBy('products.subcategory_id')
                ->pluck(DB::raw('MAX(products.id) as pid'), 'products.subcategory_id');

            if ($latest->isNotEmpty()) {
                // Main image: the primary one, else the first by order
                $images = DB::table('product_images')
                    ->whereIn('product_id', $latest->values()->all())
                    ->orderByDesc('is_primary')->orderBy('order')->orderBy('id')
                    ->get(['product_id', 'image_path']);
                $byProduct = [];
                foreach ($images as $im) $byProduct[$im->product_id] ??= $im->image_path;
                foreach ($latest as $subId => $pid) {
                    if (isset($byProduct[$pid])) $fallback[$subId] = $byProduct[$pid];
                }
            }
        }

        // 5. Order: admin mega-menu position (browsed gender first), then subcategories.order
        $menuGenders = $gendered ? ($gender ? [$gender] : GenderResolver::BROWSABLE) : [GenderResolver::MENU_ALL];
        $position    = [];
        $menuRows    = DB::table('gender_menu_items as i')
            ->join('gender_menu_groups as g', 'g.id', '=', 'i.group_id')
            ->where('g.category_id', $catId)->whereIn('g.gender', $menuGenders)->whereIn('i.subcategory_id', $ids)
            ->orderByRaw('FIELD(g.gender, ' . implode(',', array_fill(0, count($menuGenders), '?')) . ')', $menuGenders)
            ->orderBy('g.sort_order')->orderBy('g.id')->orderBy('i.sort_order')->orderBy('i.id')
            ->pluck('i.subcategory_id');
        foreach ($menuRows as $n => $subId) $position[$subId] ??= $n;

        $base = count($position);
        $subs = $subs->map(fn ($s, $n) => [$s, $position[$s->id] ?? $base + $n])
            ->sortBy(fn ($p) => $p[1])->map(fn ($p) => $p[0])->values();

        return $subs->map(function ($s) use ($counts, $admin, $fallback) {
            $path   = $admin[$s->id] ?? $fallback[$s->id] ?? null;
            $source = isset($admin[$s->id]) ? 'admin' : (isset($fallback[$s->id]) ? 'product' : null);
            return [
                'id'           => (int) $s->id,
                'slug'         => $s->slug,
                'name'         => $s->name,
                'name_fr'      => $s->name_fr,
                'name_ar'      => $s->name_ar,
                'count'        => (int) $counts[$s->id],
                'image'        => $path ? $this->menu->url($path) : null,
                'image_source' => $source,
            ];
        })->all();
    }
}
