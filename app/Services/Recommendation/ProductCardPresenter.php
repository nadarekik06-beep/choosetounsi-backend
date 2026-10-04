<?php

namespace App\Services\Recommendation;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\PromotionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The storefront product-card payload (image, variant images, promotion
 * pricing), shared by the homepage feed and the legacy recommendation endpoints.
 */
class ProductCardPresenter
{
    public function __construct(private PromotionService $promotions) {}

    /**
     * Load cards for the given ids with all card relations.
     *
     * @return array<int, array> product_id => card
     */
    public function cardsFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $products = Product::whereIn('id', $productIds)
            ->with([
                'category:id,name,name_fr,name_ar,slug',
                'primaryImage',
                'seller:id,name',
                'variants' => fn ($q) => $q->where('is_active', true),
            ])
            ->get();

        // Cards show the shop's name and pepper tier, not the seller account's personal name.
        $shops = \App\Services\ShopOverview::shopsFor($products->pluck('seller_id')->filter()->unique()->all());
        $ratings = \Illuminate\Support\Facades\DB::table('reviews')
            ->whereIn('product_id', $products->pluck('id'))
            ->where('status', 'approved')
            ->groupBy('product_id')
            ->selectRaw('product_id, AVG(rating) as avg, COUNT(*) as n')
            ->get()->keyBy('product_id');
        foreach ($products as $p) {
            if ($p->seller && isset($shops[$p->seller_id])) {
                $p->seller->setAttribute('business_name', $shops[$p->seller_id]['business_name']);
                $p->seller->setAttribute('plan', $shops[$p->seller_id]['plan']);
                $p->seller->setAttribute('avatar', $shops[$p->seller_id]['avatar']);
            }
            $r = $ratings[$p->id] ?? null;
            $p->avg_rating    = $r ? round((float) $r->avg, 1) : null;
            $p->reviews_count = $r ? (int) $r->n : 0;
        }

        return collect($this->present($products))->keyBy('id')->all();
    }

    /** Same shape the old ProductRecommendationController::transformCollection returned. */
    /** Organic cards: never labelled as ads (the ad server adds sponsor_data to the ones it serves). */
    public function present(Collection $products): array
    {
        $colorImages = ProductImage::whereIn('product_id', $products->pluck('id'))
            ->whereNotNull('color_option_id')
            ->select('product_id', 'image_path')
            ->get()
            ->groupBy('product_id');

        $pricing = $this->promotions->priceMany($products);

        return $products->map(function ($p) use ($colorImages, $pricing) {
            $p->primary_image_url  = $p->primaryImage ? Storage::url($p->primaryImage->image_path) : null;
            $p->is_sponsored       = false;
            unset($p->sponsored_priority);

            $variantImages = [];
            foreach ($colorImages->get($p->id, collect()) as $img) {
                $url = Storage::url($img->image_path);
                if (!in_array($url, $variantImages, true)) {
                    $variantImages[] = $url;
                }
            }
            $p->variant_images = $variantImages;

            $this->promotions->attach($p, $pricing[$p->id]);

            if ($p->relationLoaded('variants')) {
                $p->setRelation('variants', $p->variants->map(fn ($v) => ['id' => $v->id, 'stock' => $v->stock])->values());
            }

            return $p;
        })->values()->toArray();
    }
}
