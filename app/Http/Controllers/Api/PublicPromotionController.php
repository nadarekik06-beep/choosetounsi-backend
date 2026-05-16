<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * PublicPromotionController
 *
 * Handles the two public (no auth) promotion endpoints:
 *   GET /api/flash-sales   — active flash_sale promotions with their products
 *   GET /api/discounts     — active discount promotions with their products  ← NEW
 *
 * Both return the same shape so the frontend can treat them uniformly.
 */
class PublicPromotionController extends Controller
{
    public function __construct(private PromotionService $promoService) {}

    // ── GET /api/flash-sales ───────────────────────────────────────────────────

    public function flashSales()
    {
        return $this->publicPromotions('flash_sale');
    }

    // ── GET /api/discounts ─────────────────────────────────────────────────────

    public function discounts()
    {
        return $this->publicPromotions('discount');
    }

    // ── Shared logic ───────────────────────────────────────────────────────────

    private function publicPromotions(string $type)
    {
        $now = now();

        $promotions = Promotion::where('type', $type)
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->with([
                'products' => fn ($q) => $q
                    ->where('is_approved', true)
                    ->where('is_active', true)
                    ->with(['primaryImage', 'seller:id,name']),
            ])
            ->orderByDesc('priority')
            ->orderBy('ends_at')          // soonest-ending first
            ->get();

        $data = $promotions->map(function ($promo) {
            $products = $promo->products->map(function ($product) use ($promo) {
                // Compute effective (discounted) price per product
                $promoData = $this->promoService->getEffectivePrice($product);

                return [
                    'id'                => $product->id,
                    'name'              => $product->name,
                    'slug'              => $product->slug,
                    'price'             => (float) $product->price,
                    'original_price'    => (float) $product->price,          // always base price
                    'effective_price'   => $promoData['effective_price'],     // discounted
                    'discount_amount'   => $promoData['discount_amount'],
                    'primary_image_url' => $product->primary_image_url,
                    'stock'             => $product->stock,
                    'seller'            => $product->seller
                        ? ['name' => $product->seller->name]
                        : null,
                ];
            })->filter(fn ($p) => $p['stock'] > 0)->values();

            // Skip promotions where all products are out of stock
            if ($products->isEmpty()) return null;

            return [
                'id'                    => $promo->id,
                'name'                  => $promo->name,
                'type'                  => $promo->type,
                'discount_type'         => $promo->discount_type,
                'discount_value'        => (float) $promo->discount_value,
                'discount_label'        => $promo->discount_type === 'percentage'
                                             ? (int) $promo->discount_value . '% OFF'
                                             : number_format($promo->discount_value, 3) . ' DT OFF',
                'ends_at'               => $promo->ends_at->toISOString(),
                'flash_stock'           => $promo->flash_stock,
                'flash_stock_remaining' => $promo->flashStockRemaining(),
                'products'              => $products,
            ];
        })
        ->filter()   // remove nulls (empty-stock promos)
        ->values();

        return response()->json(['success' => true, 'data' => $data]);
    }
}