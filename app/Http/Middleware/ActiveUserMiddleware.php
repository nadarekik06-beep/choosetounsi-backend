<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveUserMiddleware
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
        if (auth()->check()) {
            if (!auth()->user()->isActiveUser()) {
                auth()->logout();
                
                return redirect()->route('login')
                    ->with('error', 'Your account has been deactivated. Please contact support.');
            }
        }

        return $next($request);
    }
}