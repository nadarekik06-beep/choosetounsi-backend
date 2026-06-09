<?php
// app/Http/Middleware/SellerPlanMiddleware.php
//
// FULL REPLACEMENT — adds grace period support and subscription status check.
// Zero regression: still reads seller_applications.plan as the primary source.
// New behavior: also checks seller_subscriptions.status so suspended sellers
// are blocked even if their plan column hasn't been cleared.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;

/**
 * SellerPlanMiddleware
 *
 * Rejects requests from sellers whose active plan is below the required tier,
 * OR whose subscription is suspended.
 *
 * Usage in api.php:
 *   Route::middleware(['auth:sanctum', 'seller.plan:red'])
 *       ->prefix('seller/analytics')
 *       ->group(function () { ... });
 *
 * Tiers: free=0, red=1, black=2
 * Passing 'red' allows red AND black sellers.
 * Passing 'black' allows only black sellers.
 *
 * Grace period: sellers in grace_period KEEP feature access.
 * Suspended: access denied immediately regardless of plan.
 */
class SellerPlanMiddleware
{
    private const TIERS = ['free' => 0, 'red' => 1, 'black' => 2];

    public function handle(Request $request, Closure $next, string $requiredPlan = 'red')
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $application = SellerApplication::where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Approved seller account required.',
                'code'    => 'NOT_SELLER',
            ], 403);
        }

        // ── Check subscription status (suspension override) ────────────────────
        $sub = SellerSubscription::where('seller_application_id', $application->id)->first();

        if ($sub && $sub->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Your subscription has been suspended. Please contact support.',
                'code'    => 'SUBSCRIPTION_SUSPENDED',
            ], 403);
        }

        // ── Grace period: features stay accessible ─────────────────────────────
        // Sellers in grace_period or canceled (running to end of cycle) still
        // have full feature access — only check plan tier.

        // ── Plan tier check ────────────────────────────────────────────────────
        $currentTier  = self::TIERS[$application->plan] ?? 0;
        $requiredTier = self::TIERS[$requiredPlan] ?? 1;

        if ($currentTier < $requiredTier) {
            $planLabel = match($requiredPlan) {
                'red'   => 'Red Pepper (49 DT/month)',
                'black' => 'Black Pepper (129 DT/month)',
                default => 'a paid plan',
            };

            return response()->json([
                'success'       => false,
                'message'       => "This feature requires {$planLabel}. Please upgrade your subscription.",
                'code'          => 'PLAN_REQUIRED',
                'required_plan' => $requiredPlan,
                'current_plan'  => $application->plan,
            ], 403);
        }

        return $next($request);
    }
}