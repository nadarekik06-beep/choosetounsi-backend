<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformFacts;
use Illuminate\Http\JsonResponse;

/**
 * Public, read-only platform facts for storefront copy.
 * Values come from PlatformFacts so pages never hardcode plan prices,
 * commission ranges or payment details.
 */
class PlatformInfoController extends Controller
{
    public function __construct(private PlatformFacts $facts) {}

    /** GET /api/seller-plans */
    public function sellerPlans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => array_values($this->facts->plans()),
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
}
