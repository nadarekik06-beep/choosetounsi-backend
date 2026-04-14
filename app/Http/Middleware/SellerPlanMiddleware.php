<?php
// app/Http/Middleware/SellerPlanMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\SellerApplication;

/**
 * SellerPlanMiddleware
 *
 * Rejects requests from sellers whose active plan is below the required tier.
 *
 * Usage in api.php:
 *   Route::middleware(['auth:sanctum', 'seller.plan:red'])
 *       ->prefix('seller/analytics')
 *       ->group(function () { ... });
 *
 * Tiers: free=0, red=1, black=2
 * Passing 'red' allows red AND black sellers.
 * Passing 'black' allows only black sellers.
 */
class SellerPlanMiddleware
{
    private const TIERS = ['free' => 0, 'red' => 1, 'black' => 2];

    public function handle(Request $request, Closure $next, string $requiredPlan = 'red')
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => 'Approved seller account required.',
                'code'    => 'NOT_SELLER',
            ], 403);
        }

        $currentTier  = self::TIERS[$application->plan] ?? 0;
        $requiredTier = self::TIERS[$requiredPlan] ?? 1;

        if ($currentTier < $requiredTier) {
            $planLabel = match($requiredPlan) {
                'red'   => 'Red Pepper (49 DT/month)',
                'black' => 'Black Pepper (129 DT/month)',
                default => 'a paid plan',
            };

            return response()->json([
                'success'        => false,
                'message'        => "This feature requires {$planLabel}. Please upgrade your subscription.",
                'code'           => 'PLAN_REQUIRED',
                'required_plan'  => $requiredPlan,
                'current_plan'   => $application->plan,
            ], 403);
        }

        return $next($request);
    }
}