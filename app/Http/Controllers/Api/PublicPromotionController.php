<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\PromotionService;
use Illuminate\Http\Request;

class PublicPromotionController extends Controller
{
    public function __construct(private PromotionService $promoService) {}

    /**
     * GET /api/flash-sales
     * Returns currently active flash sales for the Deals page.
     */
    public function flashSales(Request $request)
    {
        $flashSales = Promotion::active()
            ->where('type', 'flash_sale')
            ->with(['products' => fn($q) => $q
                ->where('is_approved', true)
                ->where('is_active', true)
                ->with(['primaryImage', 'seller:id,name'])
                ->limit(20)
            ])
            ->orderByDesc('priority')
            ->orderBy('ends_at')        // soonest-ending first
            ->get()
            ->map(fn($promo) => [
                'id'                    => $promo->id,
                'name'                  => $promo->name,
                'discount_label'        => $this->promoService->formatPromotion($promo)['discount_label'],
                'ends_at'               => $promo->ends_at->toISOString(),
                'flash_stock_remaining' => $promo->flashStockRemaining(),
                'products'              => $promo->products->map(fn($p) => [
                    'id'                => $p->id,
                    'name'              => $p->name,
                    'slug'              => $p->slug,
                    'price'             => (float) $p->price,
                    'primary_image_url' => $p->primary_image_url,
                    'seller'            => $p->seller ? ['name' => $p->seller->name] : null,
                    ...$this->promoService->getEffectivePrice($p),
                ])->values(),
            ]);

        return response()->json(['success' => true, 'data' => $flashSales]);
    }

    /**
     * GET /api/promotions/product/{productId}
     * Used by the product detail page to get real-time promotion data.
     */
    public function forProduct(int $productId)
    {
        $promotion = $this->promoService->getActivePromotionForProduct($productId);
        return response()->json([
            'success'   => true,
            'promotion' => $promotion
                ? $this->promoService->formatPromotion($promotion)
                : null,
        ]);
    }
}