<?php

namespace App\Services\Gender;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Storefront data for gendered categories:
 *   - megaMenu(): Femme / Homme → columns → subcategories (cached until an admin edit)
 *   - bar():      round-thumbnail subcategory bar for a category × gender
 *
 * Every admin change to categories, subcategories or the gender menu calls flush(),
 * which bumps a version number so all cached menu and bar data is rebuilt at once.
 */
class GenderMenu
{
    private const VERSION_KEY = 'gender_menu:version';
    private const BAR_TTL     = 600; // product counts / fallback images refresh every 10 min

    public function __construct(private GenderResolver $resolver) {}

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    private function key(string $suffix): string
    {
        return 'gender_menu:v' . (int) Cache::get(self::VERSION_KEY, 0) . ':' . $suffix;
    }

    /** Gendered categories with their Femme / Homme columns. */
    public function megaMenu(): array
    {
        return Cache::rememberForever($this->key('mega'), fn () => $this->buildMegaMenu());
    }

    private function buildMegaMenu(): array
    {
        $categories = DB::table('categories')
            ->where('is_gendered', true)->where('is_active', true)
            ->orderBy('order')->orderBy('id')
            ->get(['id', 'slug', 'name', 'name_fr', 'name_ar']);

        $out = [];
        foreach ($categories as $cat) {
            $genders = [];
            foreach (GenderResolver::BROWSABLE as $gender) {
                $genders[$gender] = $this->groups($cat->id, $gender);
            }
            $out[] = [
                'id'      => $cat->id,
                'slug'    => $cat->slug,
                'name'    => $cat->name,
                'name_fr' => $cat->name_fr,
                'name_ar' => $cat->name_ar,
                'genders' => $genders,
            ];
        }
        return $out;
    }

    /** Columns of one category × gender, each with its active subcategories. */
    private function groups(int $categoryId, string $gender): array
    {
        $groups = DB::table('gender_menu_groups')
            ->where('category_id', $categoryId)->where('gender', $gender)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'key', 'name', 'name_fr', 'name_ar']);
        if ($groups->isEmpty()) return [];

        $items = DB::table('gender_menu_items as i')
            ->join('subcategories as s', 's.id', '=', 'i.subcategory_id')
            ->join('categories as c', 'c.id', '=', 's.category_id')
            ->whereIn('i.group_id', $groups->pluck('id'))
            ->where('s.is_active', true)->where('c.is_active', true)
            ->orderBy('i.sort_order')->orderBy('i.id')
            ->get(['i.group_id', 's.id', 's.slug', 's.name', 's.name_fr', 's.name_ar', 'c.slug as category_slug'])
            ->groupBy('group_id');

        return $groups->map(fn ($g) => [
            'key'     => $g->key,
            'name'    => $g->name,
            'name_fr' => $g->name_fr,
            'name_ar' => $g->name_ar,
            'items'   => ($items[$g->id] ?? collect())->map(fn ($s) => [
                'id'            => (int) $s->id,
                'slug'          => $s->slug,
                'name'          => $s->name,
                'name_fr'       => $s->name_fr,
                'name_ar'       => $s->name_ar,
                'category_slug' => $s->category_slug,
            ])->values()->all(),
        ])->filter(fn ($g) => count($g['items']) > 0)->values()->all();
    }

    /**
     * Visual bar for a gendered category × gender: distinct subcategories in menu order,
     * with product count and image. Empty subcategories are hidden.
     * Image: admin upload → main image of a real available product → null (UI placeholder).
     */
    public function bar(string $categorySlug, string $gender): array
    {
        if (!$this->resolver->isBrowsable($gender)) return [];

        return Cache::remember($this->key("bar:{$categorySlug}:{$gender}"), self::BAR_TTL,
            fn () => $this->buildBar($categorySlug, $gender));
    }

    private function buildBar(string $categorySlug, string $gender): array
    {
        $catId = DB::table('categories')->where('slug', $categorySlug)
            ->where('is_gendered', true)->where('is_active', true)->value('id');
        if (!$catId) return [];

        $subs = DB::table('gender_menu_items as i')
            ->join('gender_menu_groups as g', 'g.id', '=', 'i.group_id')
            ->join('subcategories as s', 's.id', '=', 'i.subcategory_id')
            ->join('categories as c', 'c.id', '=', 's.category_id')
            ->where('g.category_id', $catId)->where('g.gender', $gender)
            ->where('s.is_active', true)->where('c.is_active', true)
            ->orderBy('g.sort_order')->orderBy('g.id')->orderBy('i.sort_order')->orderBy('i.id')
            ->get(['s.id', 's.slug', 's.name', 's.name_fr', 's.name_ar', 'c.slug as category_slug'])
            ->unique('id')->values();
        if ($subs->isEmpty()) return [];

        $ids     = $subs->pluck('id')->all();
        $matches = $this->resolver->matchingValues($gender);

        $available = fn () => DB::table('products')
            ->whereNull('products.deleted_at')
            ->where('products.is_approved', true)->where('products.is_active', true)
            ->whereIn('products.gender', $matches);

        $counts = $available()->whereIn('products.subcategory_id', $ids)
            ->groupBy('products.subcategory_id')
            ->pluck(DB::raw('COUNT(*) as n'), 'products.subcategory_id');

        $adminImages = DB::table('subcategory_gender_images')
            ->whereIn('subcategory_id', $ids)->where('gender', $gender)
            ->pluck('image_path', 'subcategory_id');

        $out = [];
        foreach ($subs as $s) {
            $count = (int) ($counts[$s->id] ?? 0);
            if ($count === 0) continue;

            $source = null;
            $path   = $adminImages[$s->id] ?? null;
            if ($path) {
                $source = 'admin';
            } else {
                $path = $available()
                    ->join('product_images as pi', 'pi.product_id', '=', 'products.id')
                    ->where('products.subcategory_id', $s->id)
                    ->where('pi.is_primary', true)
                    ->orderByDesc('products.views')->orderByDesc('products.id')
                    ->value('pi.image_path');
                if ($path) $source = 'product';
            }

            $out[] = [
                'id'            => (int) $s->id,
                'slug'          => $s->slug,
                'name'          => $s->name,
                'name_fr'       => $s->name_fr,
                'name_ar'       => $s->name_ar,
                'category_slug' => $s->category_slug,
                'count'         => $count,
                'image'         => $path ? $this->url($path) : null,
                'image_source'  => $source,
            ];
        }
        return $out;
    }

    public function url(string $path): string
    {
        return preg_match('#^https?://#', $path) ? $path : Storage::url($path);
    }
}
