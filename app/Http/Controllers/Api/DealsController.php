<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DealsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealsController extends Controller
{
    public function __construct(private DealsCatalog $deals) {}

    /**
     * GET /api/deals   (public; a bearer token adds the shopper's interests)
     *
     * { success, data: {
     *     products: [card + deal{promotion_id, is_flash_sale, ends_at, flash_stock, flash_remaining}|null
     *                     + coupon{discount_type, discount_value, min_order_amount}|null],
     *     packs:    [pack card],
     *     interest_category_ids: [int]   // [] for guests / no history
     * } }
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'products'              => $this->deals->products(),
                'packs'                 => $this->deals->packs(),
                'interest_category_ids' => $this->deals->interestCategoryIds($request->user('sanctum')),
            ],
        ]);
    }
}
