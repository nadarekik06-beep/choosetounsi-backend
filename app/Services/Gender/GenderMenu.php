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

    /**
     * Mega-menu of every active category, in rail order.
     *   gendered:     genders.women / genders.men → columns → subcategories
     *   not gendered: groups → subcategories
     * Every active category, column and subcategory is listed, products or not. With the admin
     * setting "hide empty" (off by default) only subcategories with available products are
     * listed (gender-aware for Femme / Homme) and show_in_menu is false for empty categories.
     * Each item carries its product count; has_products says whether the category has any.
     * Admin edits rebuild it at once (flush); product counts refresh every few minutes.
     */
    public function megaMenu(): array
    {
        $hide = $this->hideEmpty();
        return Cache::remember($this->key('mega:' . (int) $hide), self::MENU_TTL, fn () => $this->buildMegaMenu($hide));
    }

    private const MENU_TTL = 300;

    /** PlatformSetting key of "Masquer les sous-catégories vides dans le mega-menu" (off by default). */
    public const HIDE_EMPTY_SETTING = 'mega_menu_hide_empty';

    public function hideEmpty(): bool
    {
        return (bool) \App\Models\PlatformSetting::getValue(self::HIDE_EMPTY_SETTING, false);
    }

    public function setHideEmpty(bool $on, ?int $userId = null): void
    {
        \App\Models\PlatformSetting::setValue(self::HIDE_EMPTY_SETTING, $on, $userId);
        $this->flush();
    }

    private function buildMegaMenu(bool $hide): array
    {
        $categories = DB::table('categories')
            ->where('is_active', true)
            ->orderBy('order')->orderBy('id')
            ->get(['id', 'slug', 'name', 'name_fr', 'name_ar', 'is_gendered']);

        [$catCounts, $subCounts] = $this->availability();

        $out = [];
        foreach ($categories as $cat) {
            $row = [
                'id'            => $cat->id,
                'slug'          => $cat->slug,
                'name'          => $cat->name,
                'name_fr'       => $cat->name_fr,
                'name_ar'       => $cat->name_ar,
                'is_gendered'   => (bool) $cat->is_gendered,
                'product_count' => (int) ($catCounts[$cat->id] ?? 0),
                'has_products'  => ($catCounts[$cat->id] ?? 0) > 0,
                'show_in_menu'  => !$hide || ($catCounts[$cat->id] ?? 0) > 0,
            ];
            if ($cat->is_gendered) {
                foreach (GenderResolver::BROWSABLE as $gender) {
                    $row['genders'][$gender] = $this->groups($cat->id, $gender, $subCounts, $hide);
                }
            } else {
                $row['groups'] = $this->groups($cat->id, GenderResolver::MENU_ALL, $subCounts, $hide);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Available products per category, and per subcategory × gender. */
    private function availability(): array
    {
        $rows = DB::table('products')
            ->whereNull('deleted_at')->where('is_approved', true)->where('is_active', true)
            ->select('category_id', 'subcategory_id', 'gender', DB::raw('COUNT(*) as n'))
            ->groupBy('category_id', 'subcategory_id', 'gender')
            ->get();

        $cat = $sub = [];
        foreach ($rows as $r) {
            if ($r->category_id) $cat[$r->category_id] = ($cat[$r->category_id] ?? 0) + $r->n;
            if ($r->subcategory_id) {
                $g = $r->gender ?? 'none';
                $sub[$r->subcategory_id][$g] = ($sub[$r->subcategory_id][$g] ?? 0) + $r->n;
            }
        }
        return [$cat, $sub];
    }

    /** Products a menu item leads to: all of them, or that gender + unisex. */
    private function itemCount(array $subCounts, int $subId, string $menuGender): int
    {
        $c = $subCounts[$subId] ?? [];
        if ($menuGender === GenderResolver::MENU_ALL) return (int) array_sum($c);
        return (int) (($c[$menuGender] ?? 0) + ($c[GenderResolver::UNISEX] ?? 0));
    }

    /** Columns of one category × menu gender (women | men | all) with their active subcategories (non-empty only when hiding). */
    private function groups(int $categoryId, string $gender, array $subCounts, bool $hideEmpty = false): array
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
            'items'   => ($items[$g->id] ?? collect())
                ->map(fn ($s) => [
                    'id'            => (int) $s->id,
                    'slug'          => $s->slug,
                    'name'          => $s->name,
                    'name_fr'       => $s->name_fr,
                    'name_ar'       => $s->name_ar,
                    'category_slug' => $s->category_slug,
                    'count'         => $this->itemCount($subCounts, (int) $s->id, $gender),
                ])
                ->filter(fn ($s) => !$hideEmpty || $s['count'] > 0)
                ->values()->all(),
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
