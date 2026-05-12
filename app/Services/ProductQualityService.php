<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ProductQualityService
 *
 * Computes a quality score (0-100) for each seller product.
 * Entirely PHP-based -- no AI call needed. Fast, deterministic, cacheable.
 *
 * SCORING RUBRIC:
 *   Images (30 pts):
 *     +10  at least 1 image
 *     +10  3+ images
 *     +10  5+ images
 *
 *   Description (25 pts):
 *     +10  has description > 0 chars
 *     +10  description > 100 chars
 *     +5   has short_description
 *
 *   Title (10 pts):
 *     +5   name > 10 chars
 *     +5   name > 30 chars
 *
 *   Attributes (20 pts):
 *     +10  has category set
 *     +10  has at least 1 attribute value set
 *
 *   Commerce (15 pts):
 *     +5   price > 0
 *     +5   has SKU
 *     +5   has stock > 0
 *
 * Tips are sorted by points DESC (biggest wins shown first).
 * Tips link to the product edit page with the right section focused.
 */
class ProductQualityService
{
    public function analyzeAll(int $sellerId): array
    {
        $sellerCol = $this->sellerCol();

        // ── Fetch all active approved products ────────────────────────────
        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where("p.{$sellerCol}", $sellerId)
            ->whereNull('p.deleted_at')
            ->where('p.is_approved', true)
            ->selectRaw("p.id, p.name, p.price, p.stock, p.sku, p.description,
                         p.short_description, p.category_id, c.name as category_name")
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $productIds = $products->pluck('id')->toArray();

        // ── Batch fetch image counts ──────────────────────────────────────
        $imageCounts = DB::table('product_images')
            ->whereIn('product_id', $productIds)
            ->whereNull('variant_id')
            ->selectRaw("product_id, COUNT(*) as cnt")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // ── Batch fetch primary images for display ────────────────────────
        $primaryImages = DB::table('product_images')
            ->whereIn('product_id', $productIds)
            ->where('is_primary', true)
            ->whereNull('variant_id')
            ->selectRaw("product_id, MIN(image_path) as image_path")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // ── Batch fetch attribute value counts ────────────────────────────
        $attrCounts = DB::table('product_attribute_values')
            ->whereIn('product_id', $productIds)
            ->selectRaw("product_id, COUNT(*) as cnt")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // ── Score each product ────────────────────────────────────────────
        $results = [];
        foreach ($products as $product) {
            $imageCount = (int) ($imageCounts[$product->id]->cnt ?? 0);
            $attrCount  = (int) ($attrCounts[$product->id]->cnt  ?? 0);
            $imagePath  = $primaryImages[$product->id]->image_path ?? null;
            $descLen    = strlen($product->description ?? '');
            $shortLen   = strlen($product->short_description ?? '');
            $nameLen    = strlen($product->name ?? '');

            [$score, $tips] = $this->computeScore(
                $product, $imageCount, $attrCount, $descLen, $shortLen, $nameLen
            );

            $results[] = [
                'product_id'   => (int) $product->id,
                'product_name' => $product->name,
                'category'     => $product->category_name ?? 'Uncategorized',
                'image_url'    => $imagePath
                    ? url(Storage::url($imagePath))
                    : null,
                'score'        => $score,
                'tips'         => $tips,
            ];
        }

        // Sort: lowest score first (most need attention)
        usort($results, fn($a, $b) => $a['score'] <=> $b['score']);

        return $results;
    }

    // ── Score computation ─────────────────────────────────────────────────────

    private function computeScore(
        object $product,
        int $imageCount,
        int $attrCount,
        int $descLen,
        int $shortLen,
        int $nameLen
    ): array {
        $score = 0;
        $tips  = [];
        $productEditBase = "/seller/products/{$product->id}";

        // ── Images (30 pts) ───────────────────────────────────────────────
        if ($imageCount >= 1) {
            $score += 10;
        } else {
            $tips[] = [
                'type'        => 'images',
                'label'       => 'Add at least one photo',
                'points'      => 10,
                'action_href' => $productEditBase,
            ];
        }
        if ($imageCount >= 3) {
            $score += 10;
        } else {
            $remaining = 3 - max($imageCount, 0);
            $tips[] = [
                'type'        => 'images',
                'label'       => "Add {$remaining} more photo" . ($remaining > 1 ? 's' : '') . " (3 minimum for better sales)",
                'points'      => 10,
                'action_href' => $productEditBase,
            ];
        }
        if ($imageCount >= 5) {
            $score += 10;
        } else {
            $remaining = 5 - max($imageCount, 0);
            $tips[] = [
                'type'        => 'images',
                'label'       => "Add {$remaining} more photo" . ($remaining > 1 ? 's' : '') . " to reach 5 (best practice)",
                'points'      => 10,
                'action_href' => $productEditBase,
            ];
        }

        // ── Description (25 pts) ──────────────────────────────────────────
        if ($descLen > 0) {
            $score += 10;
        } else {
            $tips[] = [
                'type'        => 'description',
                'label'       => 'Write a product description',
                'points'      => 15,  // higher urgency if none at all
                'action_href' => $productEditBase,
            ];
        }
        if ($descLen > 100) {
            $score += 10;
        } elseif ($descLen > 0) {
            $tips[] = [
                'type'        => 'description',
                'label'       => 'Make the description longer (at least 100 characters)',
                'points'      => 10,
                'action_href' => $productEditBase,
            ];
        }
        if ($shortLen > 0) {
            $score += 5;
        } else {
            $tips[] = [
                'type'        => 'description',
                'label'       => 'Add a short summary for search results',
                'points'      => 5,
                'action_href' => $productEditBase,
            ];
        }

        // ── Title (10 pts) ────────────────────────────────────────────────
        if ($nameLen > 10) {
            $score += 5;
        } else {
            $tips[] = [
                'type'        => 'title',
                'label'       => 'Use a longer, more descriptive product name',
                'points'      => 5,
                'action_href' => $productEditBase,
            ];
        }
        if ($nameLen > 30) {
            $score += 5;
        } else {
            $tips[] = [
                'type'        => 'title',
                'label'       => 'Add more detail to the product name (brand, size, color)',
                'points'      => 5,
                'action_href' => $productEditBase,
            ];
        }

        // ── Attributes (20 pts) ───────────────────────────────────────────
        if ($product->category_id) {
            $score += 10;
        } else {
            $tips[] = [
                'type'        => 'attributes',
                'label'       => 'Assign a category to this product',
                'points'      => 10,
                'action_href' => $productEditBase,
            ];
        }
        if ($attrCount >= 1) {
            $score += 10;
        } else {
            $tips[] = [
                'type'        => 'attributes',
                'label'       => 'Add product details (size, material, color, etc.)',
                'points'      => 10,
                'action_href' => $productEditBase,
            ];
        }

        // ── Commerce (15 pts) ─────────────────────────────────────────────
        if ((float) $product->price > 0) {
            $score += 5;
        } else {
            $tips[] = [
                'type'        => 'stock',
                'label'       => 'Set a price for this product',
                'points'      => 5,
                'action_href' => $productEditBase,
            ];
        }
        if (!empty($product->sku)) {
            $score += 5;
        } else {
            $tips[] = [
                'type'        => 'attributes',
                'label'       => 'Add a SKU reference code',
                'points'      => 5,
                'action_href' => $productEditBase,
            ];
        }
        if ((int) $product->stock > 0) {
            $score += 5;
        } else {
            $tips[] = [
                'type'        => 'stock',
                'label'       => 'Update stock — this product shows as out of stock',
                'points'      => 5,
                'action_href' => "/seller/products/{$product->id}",
            ];
        }

        // Cap score at 100
        $score = min($score, 100);

        // Sort tips: biggest wins first, then remove duplicates
        usort($tips, fn($a, $b) => $b['points'] <=> $a['points']);

        // Deduplicate by label (same fix can appear multiple times from branching above)
        $seen = [];
        $tips = array_values(array_filter($tips, function ($tip) use (&$seen) {
            if (isset($seen[$tip['label']])) return false;
            $seen[$tip['label']] = true;
            return true;
        }));

        // Return top 4 tips max
        $tips = array_slice($tips, 0, 4);

        return [$score, $tips];
    }

    // ── Column detection helpers ──────────────────────────────────────────────

    private function sellerCol(): string
    {
        static $col = null;
        if ($col) return $col;
        $cols = array_map(fn($c) => $c->Field, DB::select('SHOW COLUMNS FROM products'));
        return $col = in_array('seller_id', $cols) ? 'seller_id' : 'user_id';
    }
}