<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Generic Role Middleware
 * Protects routes based on user role
 * Usage: ->middleware('role:admin,seller')
 */
class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            return redirect()->route('login')
                ->with('error', 'Please login to access this page.');
        }

        $user = auth()->user();

        // Check if user account is active
        if (!$user->isActiveUser()) {
            auth()->logout();
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated.');
        }

        // Check if user has one of the allowed roles
        if (!in_array($user->role, $roles)) {
            abort(403, 'Unauthorized access. You do not have permission to view this page.');
        }

        // Additional check for sellers: must be approved
        if ($user->role === 'seller' && !$user->is_approved) {
            // Allow access to pending page only
            if (!$request->is('seller/pending')) {
                return redirect()->route('seller.pending')
                    ->with('info', 'Your seller account is pending approval.');
            }
        }

        return $next($request);
    }
}