<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShopOverview;
use Illuminate\Http\JsonResponse;

class ShopOverviewController extends Controller
{
    public function __construct(private ShopOverview $overview) {}

    /**
     * GET /api/shop/overview   (public)
     *
     * { success, data: { stats, categories: [...], sellers: [...], deals: [...] } }
     * The product rows (for you, trending, best sellers, new) come from /api/home/feed
     * and the catalogue from /api/products.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'stats'      => $this->overview->stats(),
                'categories' => $this->overview->categories(),
                'sellers'    => $this->overview->sellers(),
                'deals'      => $this->overview->deals(),
            ],
        ]);
    }
}
