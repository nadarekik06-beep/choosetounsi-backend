<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

class AdminAuthenticate
{
    /**
     * Handle an incoming request.
     * Ensures the request is authenticated as an Admin (not a regular user).
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if the token belongs to an Admin model via 'admin' guard
        if (! $request->user('admin') || ! ($request->user('admin') instanceof \App\Models\Admin)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.',
            ], 401);
        }

        // Block inactive admins
        if (! $request->user('admin')->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your admin account has been deactivated.',
            ], 403);
        }

        return $next($request);
    }
}