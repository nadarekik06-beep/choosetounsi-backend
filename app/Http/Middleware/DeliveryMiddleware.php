<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * DeliveryMiddleware
 *
 * Allows access only to users with role: delivery_admin OR delivery_guy.
 * Optionally accepts a specific role as parameter:
 *   middleware('delivery')             → any delivery role
 *   middleware('delivery:admin')       → delivery_admin only
 *   middleware('delivery:guy')         → delivery_guy only
 */
class DeliveryMiddleware
{
    public function handle(Request $request, Closure $next, string $role = null): mixed
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is deactivated.',
            ], 403);
        }

        $deliveryRoles = ['delivery_admin', 'delivery_guy'];

        // Check base delivery role
        if (!in_array($user->role, $deliveryRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Delivery access only.',
            ], 403);
        }

        // Optional sub-role check
        if ($role === 'admin' && $user->role !== 'delivery_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Delivery admin access required.',
            ], 403);
        }

        if ($role === 'guy' && $user->role !== 'delivery_guy') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Delivery guy access required.',
            ], 403);
        }

        return $next($request);
    }
}