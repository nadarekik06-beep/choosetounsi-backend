<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ProductQualityService — "Qualité des fiches" (Black Pepper).
 *
 * Is the listing well built? Content only: never traffic or sales.
 * A quality score (0-100) per product, and tips that quote the product's own
 * numbers (how many photos it has, how long its description is, which of its
 * subcategory's required attributes are empty, which variants are out of stock).
 *
 * SCORING RUBRIC (100):
 *   Photos (30)       10 for 1 photo, 20 for 3+, 30 for 5+
 *   Description (25)  10 any text, +10 at 150+ characters, +5 short summary
 *   Title (10)        5 at 15+ characters, +5 at 30+ characters
 *   Attributes (20)   10 category + subcategory, 10 × share of the subcategory's
 *                     required attributes that are filled (any attribute when it has none)
 *   Commerce (15)     5 price, 5 in stock, 5 every active variant in stock
 *
 * Also used by Visitor Insights as one possible cause of a funnel drop
 * (score below config('funnel.quality_low_score')) — through score() / weakest().
 */
class ProductQualityService
{
    public const DESCRIPTION_GOOD = 150;
    public const TITLE_MIN = 15;
    public const TITLE_GOOD = 30;

    /** @var array<int, array> memo of analyze() per seller */
    private array $memo = [];

    public function analyzeAll(int $sellerId): array
    {
        $results = array_values($this->analyze($sellerId));
        // Lowest score first (most need attention)
        usort($results, fn ($a, $b) => $a['score'] <=> $b['score']);
        return $results;
    }

    /** @return array<int, array> keyed by product id (also includes 'weakest' area) */
    public function analyze(int $sellerId, ?array $onlyIds = null): array
    {
        $memoKey = $sellerId . ':' . ($onlyIds ? implode(',', $onlyIds) : '*');
        if (isset($this->memo[$memoKey])) return $this->memo[$memoKey];

        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.seller_id', $sellerId)
            ->whereNull('p.deleted_at')
            ->where('p.is_approved', true)
            ->when($onlyIds, fn ($q) => $q->whereIn('p.id', $onlyIds))
            ->selectRaw("p.id, p.name, p.price, p.stock, p.description, p.short_description,
                         p.category_id, p.subcategory_id, " . \App\Support\Localization::sqlName('c') . " as category_name")
            ->get();
        if ($products->isEmpty()) return $this->memo[$memoKey] = [];

        $ids = $products->pluck('id')->all();

        $imageCounts = DB::table('product_images')->whereIn('product_id', $ids)
            ->selectRaw('product_id, COUNT(*) as cnt')->groupBy('product_id')->pluck('cnt', 'product_id');
        $primaryImages = DB::table('product_images')->whereIn('product_id', $ids)
            ->where('is_primary', true)->whereNull('variant_id')
            ->selectRaw('product_id, MIN(image_path) as image_path')->groupBy('product_id')->pluck('image_path', 'product_id');
        $filled = DB::table('product_attribute_values')->whereIn('product_id', $ids)
            ->whereNotNull('value')->where('value', '!=', '')->where('value', '!=', '[]')
            ->get(['product_id', 'attribute_id'])->groupBy('product_id')
            ->map(fn ($g) => $g->pluck('attribute_id')->map(fn ($x) => (int) $x)->all());
        $variants = DB::table('product_variants')->whereIn('product_id', $ids)->where('is_active', true)
            ->selectRaw('product_id, COUNT(*) as total, SUM(stock <= 0) as out_of_stock, SUM(GREATEST(stock, 0)) as stock')
            ->groupBy('product_id')->get()->keyBy('product_id');

        // Required (non-variant) attributes of each subcategory, with localized names
        $subIds = $products->pluck('subcategory_id')->filter()->unique()->all();
        $required = DB::table('subcategory_attributes as sa')
            ->join('attributes as a', 'a.id', '=', 'sa.attribute_id')
            ->whereIn('sa.subcategory_id', $subIds ?: [0])
            ->where('sa.is_variant', false)
            ->where(fn ($q) => $q->where('sa.is_required', true)->orWhere('a.is_required', true))
            ->orderBy('sa.order')
            ->selectRaw('sa.subcategory_id, a.id, ' . \App\Support\Localization::sqlName('a') . ' as label')
            ->get()->groupBy('subcategory_id');

        $results = [];
        foreach ($products as $p) {
            $v = $variants[$p->id] ?? null;
            [$score, $tips, $weakest] = $this->score($p, [
                'images'      => (int) ($imageCounts[$p->id] ?? 0),
                'filled'      => $filled[$p->id] ?? [],
                'required'    => $p->subcategory_id ? ($required[$p->subcategory_id] ?? collect()) : collect(),
                'variants'    => (int) ($v->total ?? 0),
                'variants_out'=> (int) ($v->out_of_stock ?? 0),
                'stock'       => $v ? (int) $v->stock : (int) $p->stock,
            ]);
            $img = $primaryImages[$p->id] ?? null;
            $results[(int) $p->id] = [
                'product_id'   => (int) $p->id,
                'product_name' => $p->name,
                'category'     => $p->category_name ?? __('seller.common.uncategorized'),
                'image_url'    => $img ? url(Storage::url($img)) : null,
                'score'        => $score,
                'weakest'      => $weakest,
                'tips'         => $tips,
            ];
        }
        return $this->memo[$memoKey] = $results;
    }

    /** @return array{0: int, 1: array, 2: ?string} score, tips, weakest area */
    private function score(object $p, array $f): array
    {
        $edit = fn (string $focus) => "/seller/products?edit={$p->id}&focus={$focus}";
        $tips = [];
        $lost = ['photos' => 0, 'description' => 0, 'title' => 0, 'attributes' => 0, 'stock' => 0];

        // ── Photos (30) ───────────────────────────────────────────────────
        $n = $f['images'];
        $photos = $n >= 5 ? 30 : ($n >= 3 ? 20 : ($n >= 1 ? 10 : 0));
        $lost['photos'] = 30 - $photos;
        if ($n === 0) {
            $tips[] = ['type' => 'images', 'label' => __('seller.black.quality.no_photo'), 'points' => 30, 'action_href' => $edit('photos')];
        } elseif ($n < 5) {
            $target = $n < 3 ? 3 : 5;
            $tips[] = ['type' => 'images', 'points' => 30 - $photos, 'action_href' => $edit('photos'),
                'label' => trans_choice('seller.black.quality.photos', $target - $n, ['have' => $n, 'count' => $target - $n, 'target' => $target])];
        }

        // ── Description (25) ──────────────────────────────────────────────
        $len = mb_strlen(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $p->description))));
        $desc = ($len > 0 ? 10 : 0) + ($len >= self::DESCRIPTION_GOOD ? 10 : 0);
        if ($len === 0) {
            $tips[] = ['type' => 'description', 'label' => __('seller.black.quality.no_description'), 'points' => 20, 'action_href' => $edit('description')];
        } elseif ($len < self::DESCRIPTION_GOOD) {
            $tips[] = ['type' => 'description', 'points' => 10, 'action_href' => $edit('description'),
                'label' => __('seller.black.quality.short_description', ['length' => $len, 'target' => self::DESCRIPTION_GOOD])];
        }
        if (mb_strlen(trim((string) $p->short_description)) > 0) {
            $desc += 5;
        } else {
            $tips[] = ['type' => 'description', 'label' => __('seller.black.quality.short_summary'), 'points' => 5, 'action_href' => $edit('description')];
        }
        $lost['description'] = 25 - $desc;

        // ── Title (10) ────────────────────────────────────────────────────
        $tl = mb_strlen(trim((string) $p->name));
        $title = ($tl >= self::TITLE_MIN ? 5 : 0) + ($tl >= self::TITLE_GOOD ? 5 : 0);
        $lost['title'] = 10 - $title;
        if ($title < 10) {
            $tips[] = ['type' => 'title', 'points' => 10 - $title, 'action_href' => $edit('title'),
                'label' => __('seller.black.quality.title', ['length' => $tl, 'target' => self::TITLE_GOOD])];
        }

        // ── Attributes (20) ───────────────────────────────────────────────
        $attr = 0;
        if ($p->category_id && $p->subcategory_id) {
            $attr += 10;
        } else {
            $tips[] = ['type' => 'attributes', 'points' => 10, 'action_href' => $edit('category'),
                'label' => __($p->category_id ? 'seller.black.quality.subcategory' : 'seller.black.quality.category')];
        }
        $req = $f['required'];
        if ($req->isNotEmpty()) {
            $missing = $req->reject(fn ($a) => in_array((int) $a->id, $f['filled'], true));
            $attr += (int) round(10 * ($req->count() - $missing->count()) / $req->count());
            if ($missing->isNotEmpty()) {
                $tips[] = ['type' => 'attributes', 'points' => (int) round(10 * $missing->count() / $req->count()), 'action_href' => $edit('attributes'),
                    'label' => __('seller.black.quality.missing_attributes', ['list' => $missing->pluck('label')->take(4)->implode(', ')])];
            }
        } elseif ($f['filled']) {
            $attr += 10;
        } else {
            $tips[] = ['type' => 'attributes', 'label' => __('seller.black.quality.attributes'), 'points' => 10, 'action_href' => $edit('attributes')];
        }
        $lost['attributes'] = 20 - $attr;

        // ── Commerce (15) ─────────────────────────────────────────────────
        $commerce = 0;
        if ((float) $p->price > 0) {
            $commerce += 5;
        } else {
            $tips[] = ['type' => 'stock', 'label' => __('seller.black.quality.price'), 'points' => 5, 'action_href' => $edit('price')];
        }
        if ($f['stock'] > 0) {
            $commerce += 5;
        } else {
            $tips[] = ['type' => 'stock', 'label' => __('seller.black.quality.out_of_stock'), 'points' => 5, 'action_href' => "/seller/products?restock={$p->id}"];
        }
        if ($f['variants'] === 0 || $f['variants_out'] === 0) {
            $commerce += 5;
        } elseif ($f['stock'] > 0) {
            $tips[] = ['type' => 'stock', 'points' => 5, 'action_href' => "/seller/products?restock={$p->id}",
                'label' => trans_choice('seller.black.quality.variants_out', $f['variants_out'], ['count' => $f['variants_out'], 'total' => $f['variants']])];
        }
        $lost['stock'] = 15 - $commerce;

        $score = min(100, $photos + $desc + $title + $attr + $commerce);
        usort($tips, fn ($a, $b) => $b['points'] <=> $a['points']);

        // Area to fix first for the funnel diagnosis: what buyers see first, if it's really lacking
        $weakest = match (true) {
            $n < 3                         => 'photos',
            $len < self::DESCRIPTION_GOOD  => 'description',
            $tl < self::TITLE_MIN          => 'title',
            $lost['attributes'] > 0        => 'attributes',
            $lost['stock'] > 0             => 'stock',
            $lost['description'] > 0       => 'description',
            $lost['title'] > 0             => 'title',
            $lost['photos'] > 0            => 'photos',
            default                        => null,
        };

        return [$score, $tips, $weakest];
    }
}
