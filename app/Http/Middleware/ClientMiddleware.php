<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Client Middleware
 * Protects client-only routes
 */
class ClientMiddleware
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

        // Check if user is a client
        if (!$user->isClient()) {
            abort(403, 'This area is for clients only.');
        }

        return $next($request);
    }
}