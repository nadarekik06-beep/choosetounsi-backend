<?php

namespace App\Services\Recommendation;

use App\Models\Product;
use App\Services\Gender\GenderResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product page blocks "Articles similaires" and "Complétez le look".
 *
 * Similaires: same subcategory first, then the same family (config/related_products.php).
 * Never the rest of the category, never another category.
 * Ranked by: subcategory, exact gender, price window, shared title words, price distance.
 *
 * Complétez le look: only categories with a complement map (fashion). Products from the
 * complementary families, round-robin, never the product's own subcategory.
 *
 * Both: approved, active, in stock, active seller, gender rules of GenderResolver,
 * at most `max_per_seller` per shop unless fewer than `min_results` remain.
 * Only the id list is cached; cards (prices, promos, stock) are rebuilt on every request.
 */
class RelatedProducts
{
    private const VERSION_KEY = 'related_products:version';

    private const STOPWORDS = [
        'avec', 'pour', 'les', 'des', 'une', 'aux', 'sur', 'dans', 'par', 'sans', 'the', 'and', 'for', 'with',
    ];

    /** Any product change that can alter a result list clears every cached list. */
    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /** @return Collection<int,Product> */
    public function similar(string $slug, int $limit): Collection
    {
        return $this->cached('similar', $slug, $limit, fn (object $source) => $this->similarIds($source, $limit));
    }

    /** @return Collection<int,Product> */
    public function complementary(string $slug, int $limit): Collection
    {
        return $this->cached('complementary', $slug, $limit, fn (object $source) => $this->complementaryIds($source, $limit));
    }

    private function cached(string $type, string $slug, int $limit, callable $build): Collection
    {
        $source = $this->source($slug);
        if (!$source) return collect();

        $key = "related:{$type}:v" . self::version() . ":{$source->id}:{$limit}";
        $ids = Cache::remember($key, (int) config('related_products.cache_seconds', 600), fn () => $build($source));

        return $this->hydrate($ids);
    }

    // ── Similaires ──────────────────────────────────────────────────────────

    /** @return int[] */
    public function similarIds(object $source, int $limit): array
    {
        if (!$source->category_id) return [];

        $families  = config("related_products.families.{$source->category_slug}");
        $subFamily = $this->subcategoryFamilies((int) $source->category_id, $families ?? []);
        $srcFamily = $source->subcategory_id ? ($subFamily[$source->subcategory_id] ?? null) : null;

        // Same subcategory or same family; never the rest of the category
        if (!$source->subcategory_id) return [];
        $subIds = [(int) $source->subcategory_id];
        if ($srcFamily) {
            foreach ($subFamily as $id => $family) if ($family === $srcFamily) $subIds[] = (int) $id;
        }
        $query = $this->candidates($source)->whereIn('p.subcategory_id', array_values(array_unique($subIds)));

        $srcPrice  = (float) $source->price;
        $srcTokens = $this->tokens($source->name);
        $bands     = config('related_products.price_bands', [0.5, 1.0]);

        $rows = $query->get()->map(function ($row) use ($source, $srcFamily, $subFamily, $srcPrice, $srcTokens, $bands) {
            $row->tier = (int) $row->subcategory_id === (int) $source->subcategory_id ? 0
                : ($srcFamily !== null && ($subFamily[$row->subcategory_id] ?? null) === $srcFamily ? 1 : 2);
            $row->gender_exact = $row->gender === $source->gender ? 1 : 0;
            $row->distance = $srcPrice > 0 ? abs((float) $row->price - $srcPrice) / $srcPrice : 0.0;
            $row->band = count($bands);
            foreach ($bands as $i => $band) {
                if ($row->distance <= $band) { $row->band = $i; break; }
            }
            $row->similarity = $this->similarity($srcTokens, $this->tokens($row->name));
            return $row;
        })->all();

        usort($rows, fn ($a, $b) => [$a->tier, $b->gender_exact, $a->band, $b->similarity, $a->distance, $b->views, $b->id]
                                <=> [$b->tier, $a->gender_exact, $b->band, $a->similarity, $b->distance, $a->views, $a->id]);

        return $this->pick($rows, $limit);
    }

    // ── Complétez le look ───────────────────────────────────────────────────

    /** @return int[] */
    public function complementaryIds(object $source, int $limit): array
    {
        $map = config("related_products.complements.{$source->category_slug}");
        if (!$map || !$source->category_id || !$source->subcategory_id) return [];

        $subFamily = $this->subcategoryFamilies(
            (int) $source->category_id, config("related_products.families.{$source->category_slug}", [])
        );
        $targets = $map[$subFamily[$source->subcategory_id] ?? ''] ?? [];
        if (!$targets) return [];

        $subIds = array_keys(array_filter(
            $subFamily,
            fn ($family, $id) => in_array($family, $targets, true) && $id !== (int) $source->subcategory_id,
            ARRAY_FILTER_USE_BOTH
        ));
        if (!$subIds) return [];

        // One queue per family, best first; then take one from each family in turn
        $queues = [];
        foreach ($this->candidates($source)->whereIn('p.subcategory_id', $subIds)->get() as $row) {
            $row->gender_exact = $row->gender === $source->gender ? 1 : 0;
            $queues[$subFamily[$row->subcategory_id]][] = $row;
        }
        foreach ($queues as &$queue) {
            usort($queue, fn ($a, $b) => [$b->gender_exact, $b->featured, $b->views, $b->id]
                                     <=> [$a->gender_exact, $a->featured, $a->views, $a->id]);
        }
        unset($queue);

        $rows = [];
        $order = array_values(array_filter($targets, fn ($f) => !empty($queues[$f])));
        for ($i = 0; $order; $i++) {
            foreach ($order as $k => $family) {
                if (isset($queues[$family][$i])) $rows[] = $queues[$family][$i];
                else unset($order[$k]);
            }
        }

        return $this->pick($rows, $limit);
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    private function source(string $slug): ?object
    {
        return DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where('p.slug', $slug)
            ->where('p.is_approved', true)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->select('p.id', 'p.seller_id', 'p.category_id', 'p.subcategory_id', 'p.gender', 'p.price', 'p.name',
                     'c.slug as category_slug', 'c.is_gendered', 's.slug as subcategory_slug')
            ->first();
    }

    /** Visible, in-stock products of an active seller, matching the source's gender; not the source. */
    private function candidates(object $source)
    {
        $query = DB::table('products as p')
            ->where('p.is_approved', true)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->where('p.stock', '>', 0)
            ->where('p.id', '!=', $source->id)
            ->where(fn ($q) => $this->whereSellerActive($q, 'p.seller_id'))
            ->select('p.id', 'p.seller_id', 'p.subcategory_id', 'p.gender', 'p.price', 'p.name', 'p.views', 'p.featured')
            ->orderByDesc('p.views')
            ->limit(300);

        [$genders, $withNull] = $this->allowedGenders($source);
        if ($genders) {
            $query->where(function ($q) use ($genders, $withNull) {
                $q->whereIn('p.gender', $genders);
                if ($withNull) $q->orWhereNull('p.gender');
            });
        }

        return $query;
    }

    /**
     * Femme → women + unisex, Homme → men + unisex, unisex → all three, kids → kids.
     * Outside gendered categories, products without a gender match too.
     *
     * @return array{0: string[], 1: bool}
     */
    private function allowedGenders(object $source): array
    {
        $genders = match ($source->gender) {
            GenderResolver::WOMEN  => [GenderResolver::WOMEN, GenderResolver::UNISEX],
            GenderResolver::MEN    => [GenderResolver::MEN, GenderResolver::UNISEX],
            GenderResolver::UNISEX => [GenderResolver::WOMEN, GenderResolver::MEN, GenderResolver::UNISEX],
            GenderResolver::KIDS   => [GenderResolver::KIDS],
            default                => [],
        };

        return [$genders, !$source->is_gendered];
    }

    private function whereSellerActive($query, string $column): void
    {
        $query->whereNull($column)->orWhereExists(fn ($u) => $u->select(DB::raw(1))->from('users')
            ->whereColumn('users.id', $column)->where('users.is_active', true));
    }

    /** @return array<int,string|null> subcategory id → family key (slug suffix "-2" ignored) */
    private function subcategoryFamilies(int $categoryId, array $families): array
    {
        $bySlug = [];
        foreach ($families as $family => $slugs) {
            foreach ($slugs as $slug) $bySlug[$slug] = $family;
        }

        return DB::table('subcategories')->where('category_id', $categoryId)->pluck('slug', 'id')
            ->map(fn ($slug) => $bySlug[preg_replace('/-\d+$/', '', (string) $slug)] ?? null)
            ->all();
    }

    /**
     * Keep the order, at most `max_per_seller` per shop; if that leaves fewer than
     * `min_results`, top up with the skipped products (same shops) in order.
     *
     * @return int[]
     */
    private function pick(array $rows, int $limit): array
    {
        $max = (int) config('related_products.max_per_seller', 2);
        $min = min((int) config('related_products.min_results', 4), $limit);

        $out = [];
        $perSeller = [];
        $skipped = [];
        foreach ($rows as $row) {
            if (count($out) >= $limit) break;
            $seller = (int) $row->seller_id;
            if (($perSeller[$seller] ?? 0) < $max) {
                $out[] = (int) $row->id;
                $perSeller[$seller] = ($perSeller[$seller] ?? 0) + 1;
            } else {
                $skipped[] = (int) $row->id;
            }
        }
        foreach ($skipped as $id) {
            if (count($out) >= $min) break;
            $out[] = $id;
        }

        return $out;
    }

    /** @return string[] */
    private function tokens(?string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower((string) $text)), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(
            $words, fn ($w) => strlen($w) >= 3 && !in_array($w, self::STOPWORDS, true)
        )));
    }

    private function similarity(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;
        return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
    }

    /** Ids → card-ready products, in order, re-checked for visibility and stock. */
    private function hydrate(array $ids): Collection
    {
        if (!$ids) return collect();

        $position = array_flip($ids);

        return Product::available()
            ->where('stock', '>', 0)
            ->where(fn ($q) => $this->whereSellerActive($q, 'products.seller_id'))
            ->whereIn('id', $ids)
            ->with(['category:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name',
                    'variants' => fn ($q) => $q->where('is_active', true),
                    'attributeValues.attribute'])
            ->get()
            ->sortBy(fn ($p) => $position[$p->id] ?? PHP_INT_MAX)
            ->values();
    }
}
