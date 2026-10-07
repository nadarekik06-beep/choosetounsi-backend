<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformFacts;
use App\Services\PricingCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Public, read-only platform facts for storefront copy.
 * Values come from PlatformFacts so pages never hardcode plan prices,
 * commission ranges or payment details.
 */
class PlatformInfoController extends Controller
{
    public function __construct(private PlatformFacts $facts) {}

    /**
     * GET /api/seller-plans: pricing cards for /become-a-vendor. Active plans
     * only, public fields only, cached until an admin edits a plan.
     */
    public function sellerPlans(PricingCatalog $catalog): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $catalog->plans(),
        ]);
    }

    /** GET /api/checkout/payment-info */
    public function paymentInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'd17_account_number' => $this->facts->d17AccountNumber(),
            ],
        ]);
    }

    /** GET /api/seller-landing: live stats + approved shops for /become-a-vendor */
    public function sellerLanding(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'stats'   => $this->facts->sellerLandingStats(),
                'sellers' => $this->facts->sellerShowcase(),
            ],
        ]);
    }
}
