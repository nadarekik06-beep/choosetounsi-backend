<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Seller Middleware
 * Ensures only approved sellers can access seller routes
 */
class SellerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Check if user is active
        if (!$user->isActiveUser()) {
            auth()->logout();
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated.');
        }

        // Check if user is a seller
        if (!$user->isSeller()) {
            abort(403, 'Only sellers can access this area.');
        }

        // If seller is not approved, redirect to pending page
        if (!$user->is_approved && !$request->is('seller/pending')) {
            return redirect()->route('seller.pending')
                ->with('info', 'Your seller account is pending admin approval.');
        }

        return $next($request);
    }
}