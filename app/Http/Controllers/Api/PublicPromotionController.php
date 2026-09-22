<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PromotionService;

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
        $data = $this->promoService->getActivePromotionsFormatted(
            fn ($q) => $q->where('type', $type)
        );

        return response()->json(['success' => true, 'data' => $data]);
    }
}