<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * /api/seller/*: only active sellers. Checked against the user row loaded from
 * the database on every request (users.role, users.is_active), never against
 * the token: a token issued while the user was a buyer works as soon as the
 * admin approves the application, and stops working as soon as the admin removes
 * the seller role or suspends the account. No logout / login needed either way.
 */
class SellerApiMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if ($user->role !== 'seller') {
            return response()->json([
                'success' => false,
                'code'    => 'NOT_SELLER',
                'message' => __('seller.gate.not_seller'),
            ], 403);
        }
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'code'    => 'ACCOUNT_INACTIVE',
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        return $next($request);
    }
}
