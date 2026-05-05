<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Services\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CommissionController
 *
 * Provides LIVE PREVIEW commission data to the seller frontend.
 * This is estimation only — the REAL calculation is done in CheckoutController.
 *
 * Route: POST /api/seller/commission/calculate
 */
class CommissionController extends Controller
{
    public function __construct(private CommissionService $commission) {}

    /**
     * POST /api/seller/commission/calculate
     *
     * Body: { price: float, quantity?: int }
     *
     * Returns full breakdown + upgrade suggestions.
     * Reads the seller's active plan from seller_applications.plan.
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'price'    => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:1|max:9999',
        ]);

        $user = $request->user();

        // Fetch active plan — defaults to 'free' if no application exists
        $application = SellerApplication::where('user_id', $user->id)->first();
        $plan        = $application?->plan ?? 'free';

        $price    = (float) $request->price;
        $quantity = (int)   ($request->quantity ?? 1);

        $breakdown        = $this->commission->calculate($price, $plan, $quantity);
        $upgradeSuggestions = $this->commission->getUpgradeSavings($price, $plan, $quantity);

        return response()->json([
            'success' => true,
            'data'    => array_merge($breakdown, [
                'upgrade_suggestions' => $upgradeSuggestions,
            ]),
        ]);
    }
}