<?php

namespace App\Http\Controllers\Api\Seller\Ads;

use App\Exceptions\Ads\AdRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sponsorship;
use App\Services\Ads\AdForecastService;
use App\Services\Ads\AdPricing;
use App\Services\Ads\AdSettings;
use App\Services\Ads\AdWalletService;
use App\Services\Ads\Payments\AdTopUpGateways;
use App\Services\Ads\ReadinessService;
use App\Services\AutoPromotionService;
use App\Services\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Campaign wizard helpers.
 *
 *   GET  /api/seller/ads/config        numbers the seller UI needs (never duplicated in the frontend)
 *   POST /api/seller/ads/readiness     {product_id} → score, blockers, tips
 *   POST /api/seller/ads/forecast      {product_id, daily_budget, max_cpc?, days?}
 *   GET  /api/seller/ads/suggestions   trending products worth boosting (AutoPromotionService)
 */
class AdToolsController extends Controller
{
    public function __construct(
        private AdSettings $settings,
        private AdPricing $pricing,
        private PlanGate $gate,
    ) {}

    public function config(Request $request): JsonResponse
    {
        $sellerId   = $request->user()->id;
        $tier       = $this->gate->tierFor($sellerId);
        $categoryId = $request->filled('product_id')
            ? $this->ownProduct($request, (int) $request->query('product_id'))->category_id
            : ($request->filled('category_id') ? (int) $request->query('category_id') : null);

        return response()->json(['success' => true, 'data' => [
            'currency'            => 'TND',
            'tier'                => $tier,
            'tier_click_discount' => $this->pricing->tierDiscount($tier),
            'monthly_credit'      => $this->pricing->monthlyCredit($tier),
            'min_daily_budget'    => $this->settings->float('min_daily_budget'),
            'min_top_up'          => $this->settings->float('min_top_up'),
            'min_cpc'             => $this->pricing->floorCpc($categoryId),
            'suggested_cpc'       => $this->pricing->suggestedCpc($categoryId),
            'readiness_threshold' => $this->settings->int('readiness_threshold'),
            'placements'          => Sponsorship::PLACEMENTS,
            'gateways'            => app(AdTopUpGateways::class)->availableKeys(),
            'wallet_available'    => app(AdWalletService::class)->available($sellerId),
        ]]);
    }

    public function readiness(Request $request, ReadinessService $readiness): JsonResponse
    {
        $request->validate(['product_id' => ['required', 'integer']]);
        $product = $this->ownProduct($request, (int) $request->input('product_id'));

        return response()->json(['success' => true, 'data' => $readiness->check($product)]);
    }

    public function forecast(Request $request, AdForecastService $forecasts): JsonResponse
    {
        $data = $request->validate([
            'product_id'   => ['required', 'integer'],
            'daily_budget' => ['required', 'numeric', 'min:0', 'max:100000'],
            'max_cpc'      => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'days'         => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $product = $this->ownProduct($request, (int) $data['product_id']);
        $maxCpc  = isset($data['max_cpc']) ? (float) $data['max_cpc'] : $this->pricing->suggestedCpc($product->category_id);

        return response()->json(['success' => true, 'data' => $forecasts->forecast(
            $product, (float) $data['daily_budget'], $maxCpc, $data['days'] ?? null, $this->gate->tierFor($request->user()->id)
        )]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $sellerId = $request->user()->id;
        $items = Cache::remember("ads:suggestions:{$sellerId}:" . app()->getLocale(), now()->addHours(3),
            fn () => (new AutoPromotionService())->suggest($sellerId));

        // "Already boosted" means an open campaign now (not the old product flag).
        $open = Sponsorship::forSeller($sellerId)->open()->pluck('product_id')->flip();
        $items = array_map(fn ($i) => ['already_sponsored' => isset($open[$i['product_id']])] + $i, $items);

        return response()->json(['success' => true, 'data' => array_values($items)]);
    }

    private function ownProduct(Request $request, int $productId): Product
    {
        $product = Product::where('id', $productId)->where('seller_id', $request->user()->id)->first();
        if (!$product) {
            throw AdRuleViolation::make('product_not_found', [], [], 404);
        }
        return $product;
    }
}
