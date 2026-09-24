<?php

namespace App\Http\Middleware;

use App\Services\PlanGate;
use Closure;
use Illuminate\Http\Request;

/**
 * Gate a route on a plan feature flag (subscription_plans.features).
 *
 *   Route::middleware('seller.feature:analytics')
 *
 * Only write methods are gated when `write` is passed as the second
 * parameter, so sellers can still list existing records after a downgrade:
 *
 *   Route::middleware('seller.feature:promotions,write')
 */
class SellerFeatureMiddleware
{
    public function __construct(private PlanGate $gate) {}

    public function handle(Request $request, Closure $next, string $feature, string $scope = 'all')
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ($scope === 'write' && $request->isMethodSafe()) {
            return $next($request);
        }

        return $this->gate->feature($user->id, $feature) ?? $next($request);
    }
}
