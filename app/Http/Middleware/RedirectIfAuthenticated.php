<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  ...$guards
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::user();

                // Redirect based on role
                if ($user->isAdmin()) {
                    return redirect()->route('admin.dashboard');
                }

                if ($user->isSeller()) {
                    if ($user->is_approved) {
                        return redirect()->route('seller.dashboard');
                    } else {
                        return redirect()->route('seller.pending');
                    }
                }

                if ($user->isClient()) {
                    return redirect()->route('client.dashboard');
                }

                // Fallback to default
                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}