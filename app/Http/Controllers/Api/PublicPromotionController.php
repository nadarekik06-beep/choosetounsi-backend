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

    // ── GET /api/promo-flyers ──────────────────────────────────────────────────
    // Promotion "flyers" for product grids (search, category, shop), most relevant first.

    public function flyers(\Illuminate\Http\Request $request, \App\Services\PromoFlyers $flyers)
    {
        $data = $request->validate([
            'category_slug' => ['nullable', 'string', 'max:191'],
            'q'             => ['nullable', 'string', 'max:200'],
            'limit'         => ['nullable', 'integer', 'min:1', 'max:6'],
            'exclude'       => ['nullable', 'array', 'max:200'],
            'exclude.*'     => ['integer'],
        ]);
        $categoryId = !empty($data['category_slug'])
            ? \Illuminate\Support\Facades\DB::table('categories')->where('slug', $data['category_slug'])->value('id')
            : null;

        return response()->json(['success' => true, 'data' => $flyers->forPage(
            $categoryId ? (int) $categoryId : null, $data['q'] ?? null, $data['exclude'] ?? [], (int) ($data['limit'] ?? 3)
        )]);
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