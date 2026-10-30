<?php

namespace App\Http\Middleware;

use App\Support\GuestCheckout;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cart + checkout routes: signed in OR guest.
 *
 *   Bearer token sent  → exactly auth:sanctum (a bad / expired token is a 401,
 *                        so nobody who thinks they are signed in places a guest order)
 *   No token, signed in some other sanctum way → that user, as before
 *   No token, guest checkout on  → guest ($request->user() is null)
 *   No token, guest checkout off → 401, as before the feature existed
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->bearerToken()) {
            return app(Authenticate::class)->handle($request, $next, 'sanctum');
        }

        if (Auth::guard('sanctum')->user()) {
            Auth::shouldUse('sanctum');
            return $next($request);
        }

        if (!GuestCheckout::enabled()) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        return $next($request);
    }
}
