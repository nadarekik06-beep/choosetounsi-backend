<?php

namespace App\Services\VisitorInsights;

use App\Services\ProductQualityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What FunnelDiagnosis needs to know about a seller's products besides traffic:
 * price, delivery fee, listing-quality score (reused from Qualité des fiches),
 * approved reviews, stock / variants and how long the product has been listed.
 */
class ProductFacts
{
    public function __construct(private ProductQualityService $quality) {}

    /** @return array<int, array> keyed by product id (live products only) */
    public function forSeller(int $sellerId, ?array $onlyIds = null): array
    {
        $products = DB::table('products as p')
            ->leftJoin('product_images as pi', fn ($j) => $j->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', true)->whereNull('pi.variant_id'))
            ->where('p.seller_id', $sellerId)->whereNull('p.deleted_at')
            ->where('p.is_active', true)->where('p.is_approved', true)
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('p.id', $onlyIds ?: [0]))
            ->groupBy('p.id', 'p.name', 'p.slug', 'p.price', 'p.delivery_fee', 'p.stock', 'p.category_id', 'p.subcategory_id', 'p.created_at')
            ->selectRaw('p.id, p.name, p.slug, p.price, p.delivery_fee, p.stock, p.category_id, p.subcategory_id, p.created_at, MIN(pi.image_path) as image')
            ->get();
        if ($products->isEmpty()) return [];
        $ids = $products->pluck('id')->all();

        $reviews = DB::table('reviews')->whereIn('product_id', $ids)->where('status', 'approved')
            ->selectRaw('product_id, COUNT(*) as n')->groupBy('product_id')->pluck('n', 'product_id');
        $variants = DB::table('product_variants')->whereIn('product_id', $ids)->where('is_active', true)
            ->selectRaw('product_id, COUNT(*) as total, SUM(stock <= 0) as out_of_stock, SUM(GREATEST(stock, 0)) as stock')
            ->groupBy('product_id')->get()->keyBy('product_id');
        $quality = $this->quality->analyze($sellerId, $ids);

        $out = [];
        foreach ($products as $p) {
            $v = $variants[$p->id] ?? null;
            $out[(int) $p->id] = [
                'id'              => (int) $p->id,
                'name'            => $p->name,
                'slug'            => $p->slug,
                'image'           => $p->image ? url(Storage::url($p->image)) : null,
                'category_id'     => $p->category_id ? (int) $p->category_id : null,
                'subcategory_id'  => $p->subcategory_id ? (int) $p->subcategory_id : null,
                'price'           => (float) $p->price,
                'delivery_fee'    => $p->delivery_fee !== null ? (float) $p->delivery_fee : null,
                'quality_score'   => $quality[$p->id]['score'] ?? null,
                'quality_weakest' => $quality[$p->id]['weakest'] ?? null,
                'reviews'         => (int) ($reviews[$p->id] ?? 0),
                'stock'           => $v ? (int) $v->stock : (int) $p->stock,
                'variants'        => (int) ($v->total ?? 0),
                'variants_out'    => (int) ($v->out_of_stock ?? 0),
                'listed_days'     => (int) now()->diffInDays($p->created_at),
            ];
        }
        return $out;
    }
}
