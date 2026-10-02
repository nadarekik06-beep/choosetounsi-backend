<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Shoppers must complete their profile (name, phone, one delivery address)
 * before placing an order. Other roles pass through — see ProfileCompletion.
 */
class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user) {
            $completion = $user->profileCompletion();
            if (!$completion->isComplete()) {
                return response()->json([
                    'success' => false,
                    'code'    => 'profile_incomplete',
                    'message' => __('messages.profile.incomplete_checkout'),
                    'missing' => array_values(array_filter($completion->missing(), fn($m) => $m['required'])),
                ], 403);
            }
        }
        return $next($request);
    }
}
