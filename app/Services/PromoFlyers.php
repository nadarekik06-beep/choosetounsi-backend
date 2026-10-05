<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Flyer" cards slipped into storefront product grids: one live promotion or
 * flash sale each, with its best product as the picture and a link to the
 * product (single-product offer), the seller's store (several products) or /deals.
 *
 * Built on PromotionService::getActivePromotionsFormatted(), so a flyer never
 * shows a price the cart won't charge. Ranked by relevance to the page (same
 * category, query words in the product names), then flash sales, then discount size.
 */
class PromoFlyers
{
    private const CACHE_SECONDS = 120;

    public function __construct(private PromotionService $promotions) {}

    /**
     * @param int[] $excludeProductIds products already on the page (preferred not to be the picture)
     * @return array<int, array>
     */
    public function forPage(?int $categoryId, ?string $query, array $excludeProductIds = [], int $limit = 3): array
    {
        $all = Cache::remember('promo-flyers:v1:' . app()->getLocale(), self::CACHE_SECONDS, fn () => $this->build());
        if (!$all) return [];

        $exclude = array_flip(array_map('intval', $excludeProductIds));
        $terms   = collect(preg_split('/\s+/u', mb_strtolower(trim((string) $query))))
            ->filter(fn ($t) => mb_strlen($t) >= 3)->values()->all();

        $scored = [];
        foreach ($all as $f) {
            $score = 0;
            if ($categoryId && in_array($categoryId, $f['_category_ids'], true)) $score += 4;
            foreach ($terms as $t) {
                if (str_contains($f['_haystack'], $t)) { $score += 3; break; }
            }
            if ($f['type'] === 'flash_sale') $score += 1;
            $score += min((float) $f['_percent'], 70) / 100;

            // Picture: the first product of the offer that isn't already in the grid; an offer
            // whose products are all on the page already would only repeat them
            $hero = collect($f['_products'])->first(fn ($p) => !isset($exclude[$p['id']]));
            if (!$hero) continue;
            $scored[] = [$score, $this->present($f, $hero)];
        }

        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
        return array_slice(array_column($scored, 1), 0, max(0, $limit));
    }

    private function build(): array
    {
        $promos = $this->promotions->getActivePromotionsFormatted();
        if ($promos->isEmpty()) return [];

        $productIds = $promos->flatMap(fn ($p) => collect($p['products'])->pluck('id'))->unique()->values()->all();
        $categories = DB::table('products')->whereIn('id', $productIds)->pluck('category_id', 'id');
        $sellerIds  = $promos->flatMap(fn ($p) => collect($p['products'])->pluck('seller.id'))->filter()->unique()->all();
        $shops      = ShopOverview::shopsFor($sellerIds);

        $out = [];
        foreach ($promos as $promo) {
            // Products with a picture, most discounted first
            $products = collect($promo['products'])
                ->filter(fn ($p) => !empty($p['primary_image_url']))
                ->sortByDesc(fn ($p) => (float) ($p['discount_percent'] ?? 0))
                ->values()->all();
            if (!$products) continue;

            $sellers = collect($products)->pluck('seller.id')->filter()->unique()->values();
            $sellerId = $sellers->count() === 1 ? (int) $sellers->first() : null;

            $out[] = [
                'id'             => $promo['id'],
                'type'           => $promo['type'] === 'flash_sale' ? 'flash_sale' : 'discount',
                'name'           => $promo['name'],
                'discount_type'  => $promo['discount_type'],
                'discount_value' => (float) $promo['discount_value'],
                'ends_at'        => $promo['ends_at'],
                'products_count' => count($products),
                'images'         => array_slice(array_column($products, 'primary_image_url'), 0, 3),
                'seller'         => $sellerId ? [
                    'id'   => $sellerId,
                    'name' => $shops[$sellerId]['business_name'] ?? ($products[0]['seller']['name'] ?? null),
                ] : null,
                '_products'      => array_map(fn ($p) => [
                    'id'             => (int) $p['id'],
                    'slug'           => $p['slug'],
                    'name'           => $p['name'],
                    'image'          => $p['primary_image_url'],
                    'final_price'    => (float) ($p['final_price'] ?? $p['price']),
                    'original_price' => (float) ($p['original_price'] ?? $p['price']),
                ], $products),
                '_category_ids'  => collect($products)->map(fn ($p) => (int) ($categories[$p['id']] ?? 0))->filter()->unique()->values()->all(),
                '_haystack'      => mb_strtolower($promo['name'] . ' ' . implode(' ', array_column($products, 'name'))),
                '_percent'       => (float) ($products[0]['discount_percent'] ?? 0),
            ];
        }
        return $out;
    }

    private function present(array $f, array $hero): array
    {
        $link = $f['products_count'] === 1
            ? ['type' => 'product', 'href' => '/products/' . $hero['slug']]
            : ($f['seller'] ? ['type' => 'seller', 'href' => '/sellers/' . $f['seller']['id']] : ['type' => 'deals', 'href' => '/deals']);

        $images = array_values(array_unique(array_merge([$hero['image']], $f['images'])));

        return array_merge(array_diff_key($f, array_flip(['_products', '_category_ids', '_haystack', '_percent'])), [
            'product' => $hero,
            'link'    => $link,
            'images'  => array_slice($images, 0, 3),
        ]);
    }
}
