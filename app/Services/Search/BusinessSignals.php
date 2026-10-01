<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/**
 * Live commercial signals for a handful of search candidates: in stock, rating, recent
 * sales, featured. Read from MySQL at query time (≤100 ids, three indexed queries), so the
 * ranking never uses a stale stock level from the index.
 */
class BusinessSignals
{
    const SALES_DAYS = 90;

    /** @return array<int, array{in_stock: bool, rating: float, reviews: int, sold: int, featured: bool}> */
    public function for(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        $products = DB::table('products as p')
            ->leftJoin(DB::raw('(SELECT product_id, SUM(stock) AS stock, COUNT(*) AS n FROM product_variants
                                 WHERE is_active = 1 GROUP BY product_id) v'), 'v.product_id', '=', 'p.id')
            ->whereIn('p.id', $ids)
            ->get(['p.id', 'p.stock', 'p.featured', 'v.stock as variant_stock', 'v.n as variants']);

        $ratings = DB::table('reviews')->where('status', 'approved')->whereIn('product_id', $ids)
            ->groupBy('product_id')->selectRaw('product_id, AVG(rating) AS avg, COUNT(*) AS n')
            ->get()->keyBy('product_id');

        $sold = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.product_id', $ids)
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->where('o.created_at', '>=', now()->subDays(self::SALES_DAYS))
            ->groupBy('oi.product_id')->selectRaw('oi.product_id, SUM(oi.quantity) AS qty')
            ->pluck('qty', 'oi.product_id');

        $out = [];
        foreach ($products as $p) {
            $stock = $p->variants ? (int) $p->variant_stock : (int) $p->stock;
            $out[$p->id] = [
                'in_stock' => $stock > 0,
                'rating'   => (float) ($ratings[$p->id]->avg ?? 0),
                'reviews'  => (int) ($ratings[$p->id]->n ?? 0),
                'sold'     => (int) ($sold[$p->id] ?? 0),
                'featured' => (bool) $p->featured,
            ];
        }
        return $out;
    }

    /** Boost (0 .. sum of config search.boosts) for one product, given the best seller among the candidates. */
    public function boost(array $s, int $maxSold): float
    {
        $w = config('search.boosts');
        $boost = $s['in_stock'] ? $w['in_stock'] : 0.0;
        // Damped: one 5-star review is worth less than twenty 4.6-star ones.
        $boost += $w['rating'] * ($s['rating'] / 5) * min(1, $s['reviews'] / 5);
        $boost += $maxSold > 0 ? $w['sales'] * log1p($s['sold']) / log1p($maxSold) : 0.0;
        $boost += $s['featured'] ? $w['featured'] : 0.0;
        return $boost;
    }
}
