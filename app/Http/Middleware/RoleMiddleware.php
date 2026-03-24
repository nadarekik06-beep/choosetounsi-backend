<?php
// app/Http/Middleware/RoleMiddleware.php
// FULL REPLACEMENT — returns JSON for API routes instead of redirecting

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        // For API routes: check sanctum guard explicitly
        $user = auth('sanctum')->user();

        // If no user from sanctum, try the default guard
        if (!$user) {
            $user = auth()->user();
        }

        if (!$user) {
            // API request → return JSON
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
            return redirect()->route('login')
                ->with('error', 'Please login to access this page.');
        }

        // Check if account is active
        if (!$user->is_active) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated.',
                ], 403);
            }
            auth()->logout();
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated.');
        }

        // Check role
        if (!in_array($user->role, $roles)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Insufficient permissions.',
                ], 403);
            }
            abort(403, 'Unauthorized access.');
        }

        // Seller pending check (web only, skip for API)
        if ($user->role === 'seller' && !$user->is_approved) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                if (!$request->is('seller/pending')) {
                    return redirect()->route('seller.pending')
                        ->with('info', 'Your seller account is pending approval.');
                }
            }
        }

        return $next($request);
    }
}